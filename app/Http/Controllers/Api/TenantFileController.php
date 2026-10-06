<?php

namespace App\Http\Controllers\Api;

use App\Core\Support\TenantFileService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TenantFileController extends Controller
{
    public function store(Request $request, TenantFileService $files): JsonResponse
    {
        $validated = $request->validate([
            'file' => 'required|file|max:20480',
            'category' => 'required|in:documents,avatars,reports',
            'sensitivity' => 'required|in:internal,confidential,restricted',
        ]);
        $file = $files->store($request->user(), $validated['file'], $validated['category'], $validated['sensitivity']);

        return response()->json(['success' => true, 'data' => ['uuid' => $file->uuid, 'name' => $file->original_name, 'size_bytes' => $file->size_bytes, 'sensitivity' => $file->sensitivity]], 201);
    }

    public function show(Request $request, string $uuid, TenantFileService $files)
    {
        return $files->download($request->user(), $uuid);
    }
}
