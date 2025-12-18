<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class UploadController extends Controller
{
    private const MAX_FILE_SIZES = [
        'image' => 50 * 1024 * 1024,      // 50MB
        'video' => 200 * 1024 * 1024,     // 200MB
        '3d_model' => 100 * 1024 * 1024,  // 100MB
    ];

    private const ALLOWED_MIMES = [
        'image' => ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'],
        'video' => ['video/mp4', 'video/quicktime', 'video/x-msvideo', 'video/webm'],
        '3d_model' => ['model/gltf-binary', 'model/gltf+json', 'model/obj', 'application/octet-stream'],
    ];

    /**
     * Upload file to Cloudflare R2
     */
    public function upload(Request $request)
    {
        try {
            $request->validate([
                'file' => 'required|file',
                'type' => 'required|in:image,video,3d_model',
            ]);

            $file = $request->file('file');
            $type = $request->input('type');

            \Log::info('Upload request', [
                'type' => $type,
                'mime' => $file->getMimeType(),
                'size' => $file->getSize(),
                'original_name' => $file->getClientOriginalName(),
            ]);

            // Validate file size
            if ($file->getSize() > self::MAX_FILE_SIZES[$type]) {
                return response()->json([
                    'message' => 'File size exceeds maximum allowed size',
                    'max_size' => $this->formatBytes(self::MAX_FILE_SIZES[$type]),
                ], 413);
            }

            // Validate MIME type
            if (!in_array($file->getMimeType(), self::ALLOWED_MIMES[$type])) {
                return response()->json([
                    'message' => 'Invalid file type',
                    'allowed_types' => self::ALLOWED_MIMES[$type],
                    'received_type' => $file->getMimeType(),
                ], 422);
            }

            // Generate unique filename
            $extension = $file->getClientOriginalExtension();

            // If no extension, derive from MIME type
            if (!$extension) {
                $extension = match($file->getMimeType()) {
                    'image/jpeg', 'image/jpg' => 'jpg',
                    'image/png' => 'png',
                    'image/gif' => 'gif',
                    'image/webp' => 'webp',
                    'video/mp4' => 'mp4',
                    'video/quicktime' => 'mov',
                    'video/webm' => 'webm',
                    'model/gltf-binary' => 'glb',
                    'model/gltf+json' => 'gltf',
                    default => 'bin',
                };
            }

            $filename = Str::uuid() . '.' . $extension;

            // Map type to directory name
            $directory = match($type) {
                'image' => 'images',
                'video' => 'videos',
                '3d_model' => '3d_models',
            };

            $path = "{$directory}/{$filename}";

            // Upload to R2
            \Log::info('Attempting R2 upload', ['path' => $path]);

            $uploaded = Storage::disk('r2')->put($path, file_get_contents($file->getRealPath()), 'public');

            if (!$uploaded) {
                \Log::error('R2 upload failed', ['path' => $path]);
                return response()->json(['message' => 'Upload failed'], 500);
            }

            $url = Storage::disk('r2')->url($path);
            \Log::info('Upload successful', ['url' => $url]);

            return response()->json([
                'url' => $url,
                'key' => $path,
                'type' => $type,
            ], 201);
        } catch (\Exception $e) {
            \Log::error('Upload error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Upload failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete file from Cloudflare R2
     */
    public function delete(Request $request, string $key)
    {
        if (!Storage::disk('r2')->exists($key)) {
            return response()->json(['message' => 'File not found'], 404);
        }

        Storage::disk('r2')->delete($key);

        return response()->json(['message' => 'File deleted successfully'], 200);
    }

    /**
     * Format bytes to human-readable size
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }
}
