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
        ]);

        $name = $request->input('name');
        $slug = Str::slug($name);

        // Ensure unique slug.
        $baseSlug = $slug;
        $counter = 1;
        while (MediaFolder::where('slug', $slug)->exists()) {
            $slug = $baseSlug . '-' . $counter++;
        }

        $maxOrder = MediaFolder::max('sort_order') ?? 0;

        $folder = MediaFolder::create([
            'name' => $name,
            'slug' => $slug,
            'color' => $request->input('color', '#6366f1'),
            'sort_order' => $maxOrder + 1,
        ]);

        return response()->json($folder->loadCount('files'), 201);
    }

    /**
     * Update a folder (name, color).
     */
    public function update(Request $request, MediaFolder $folder): JsonResponse
    {
        $request->validate([
            'name' => 'sometimes|required|string|max:100',
            'color' => 'sometimes|string|max:7',
        ]);

        if ($request->has('name')) {
            $folder->name = $request->input('name');
            $folder->slug = Str::slug($request->input('name'));
        }

        if ($request->has('color')) {
            $folder->color = $request->input('color');
        }

        $folder->save();

        return response()->json($folder->loadCount('files'));
    }

    /**
     * Delete a folder (system folders are protected).
     */
    public function destroy(MediaFolder $folder): JsonResponse
    {
        if ($folder->is_system) {
            return response()->json(['error' => 'Impossible de supprimer un dossier systeme.'], 422);
        }

        // Files in this folder will have folder_id set to null (via nullOnDelete).
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
