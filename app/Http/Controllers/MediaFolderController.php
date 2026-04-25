<?php

namespace App\Http\Controllers;

use App\Models\MediaFile;
use App\Models\MediaFolder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class MediaFolderController extends Controller
{
    /**
     * List all folders with file counts.
     */
    public function index(): JsonResponse
    {
        $folders = MediaFolder::ordered()->withCount('files')->get();

        return response()->json($folders);
    }

    /**
     * Create a new folder.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:100',
            'color' => 'nullable|string|max:7',
            'parent_id' => 'nullable|exists:media_folders,id',
        ]);

        $name = $request->input('name');
        $slug = Str::slug($name);

        $baseSlug = $slug;
        $counter = 1;
        while (MediaFolder::where('slug', $slug)->exists()) {
            $slug = $baseSlug . '-' . $counter++;
        }

        $maxOrder = MediaFolder::max('sort_order') ?? 0;

        $folder = MediaFolder::create([
            'name' => $name,
            'slug' => $slug,
            'parent_id' => $request->input('parent_id'),
            'color' => $request->input('color', '#6366f1'),
            'sort_order' => $maxOrder + 1,
        ]);

        return response()->json($folder->loadCount('files'), 201);
    }

    /**
     * Update a folder (name, color, parent).
     */
    public function update(Request $request, MediaFolder $folder): JsonResponse
    {
        $request->validate([
            'name' => 'sometimes|required|string|max:100',
            'color' => 'sometimes|string|max:7',
            'parent_id' => 'sometimes|nullable|exists:media_folders,id',
        ]);

        if ($request->has('name')) {
            $folder->name = $request->input('name');
            $folder->slug = Str::slug($request->input('name'));
        }

        if ($request->has('color')) {
            $folder->color = $request->input('color');
        }

        if ($request->has('parent_id')) {
            $newParentId = $request->input('parent_id');
            if ($newParentId == $folder->id) {
                return response()->json(['error' => 'Un dossier ne peut pas être son propre parent.'], 422);
            }
            if ($newParentId && in_array($newParentId, $folder->descendantIds(), true)) {
                return response()->json(['error' => 'Le nouveau parent serait un descendant du dossier.'], 422);
            }
            $folder->parent_id = $newParentId;
        }

        $folder->save();

        return response()->json($folder->loadCount('files'));
    }

    /**
     * Delete a folder. Les sous-dossiers remontent au parent du dossier supprimé,
     * les fichiers passent en "Non classé" (folder_id = null via nullOnDelete).
     */
    public function destroy(MediaFolder $folder): JsonResponse
    {
        if ($folder->is_system) {
            return response()->json(['error' => 'Impossible de supprimer un dossier systeme.'], 422);
        }

        MediaFolder::where('parent_id', $folder->id)->update(['parent_id' => $folder->parent_id]);

        $folder->delete();

        return response()->json(['success' => true]);
    }

    /**
     * Move files to a folder (or to uncategorized if folder_id is null).
     */
    public function moveFiles(Request $request): JsonResponse
    {
        $request->validate([
            'file_ids' => 'required|array',
            'file_ids.*' => 'exists:media_files,id',
            'folder_id' => 'nullable|exists:media_folders,id',
        ]);

        MediaFile::whereIn('id', $request->input('file_ids'))
            ->update(['folder_id' => $request->input('folder_id')]);

        return response()->json(['success' => true]);
    }
}
