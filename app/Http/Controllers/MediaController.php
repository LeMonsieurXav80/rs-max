<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MediaController extends Controller
{
    /**
     * Serve a private media file.
     *
     * Access is granted if:
     *   - The user is authenticated (web viewing), OR
     *   - The request has a valid signature (signed URL for external APIs)
     */
    public function show(Request $request, string $filename): StreamedResponse
    {
        // Check: authenticated user OR valid signed URL
        if (! $request->user() && ! $request->hasValidSignature()) {
            abort(403);
        }

        $path = "media/{$filename}";

        if (! Storage::disk('local')->exists($path)) {
            abort(404);
        }

        $mimeType = Storage::disk('local')->mimeType($path);

        return Storage::disk('local')->response($path, $filename, [
            'Content-Type' => $mimeType,
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }
}
