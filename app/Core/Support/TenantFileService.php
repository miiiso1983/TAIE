<?php

namespace App\Core\Support;

use App\Core\Authorization\PolicyEvaluator;
use App\Core\Logging\SecurityEventLogger;
use App\Core\Tenancy\TenantContext;
use App\Models\TenantFile;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

final class TenantFileService
{
    public function __construct(private TenantContext $context, private TenantStorage $storage, private PolicyEvaluator $authorization, private SecurityEventLogger $securityEvents) {}

    public function store(User $user, UploadedFile $file, string $category, string $sensitivity): TenantFile
    {
        $this->authorize($user, 'files.create', $sensitivity);
        abort_unless(in_array($sensitivity, ['internal', 'confidential', 'restricted'], true), 422, 'Invalid file sensitivity.');
        $extension = $file->guessExtension();
        $path = $this->storage->path($category, (string) Str::uuid().($extension ? '.'.$extension : ''));
        $storedPath = Storage::disk('local')->putFileAs(dirname($path), $file, basename($path));
        if ($storedPath === false) {
            throw new \RuntimeException('Unable to store uploaded file.');
        }

        try {
            return TenantFile::query()->create([
                'tenant_id' => $this->context->getTenantId(),
                'uploaded_by' => $user->getKey(), 'uuid' => (string) Str::uuid(), 'disk' => 'local',
                'path' => $path, 'original_name' => basename($file->getClientOriginalName()),
                'mime_type' => $file->getMimeType(), 'size_bytes' => $file->getSize(), 'sensitivity' => $sensitivity,
            ]);
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }
    }

    public function download(User $user, string $uuid): StreamedResponse
    {
        $file = TenantFile::query()->where('uuid', $uuid)->first();
        if (! $file) {
            throw new NotFoundHttpException('File not found.');
        }
        $this->authorize($user, 'files.read', $file->sensitivity);
        abort_unless(Storage::disk($file->disk)->exists($file->path), 404, 'File not found.');

        return Storage::disk($file->disk)->download($file->path, $file->original_name ?? basename($file->path));
    }

    private function authorize(User $user, string $permission, string $sensitivity): void
    {
        $sensitive = in_array($sensitivity, ['confidential', 'restricted'], true);
        $sensitivePermission = $permission === 'files.create' ? 'files.sensitive.create' : 'files.sensitive.read';
        if (! $this->authorization->allows($user, $permission, ['tenant_id' => $this->context->getTenantId(), 'sensitivity' => $sensitive ? 'restricted' : 'internal', 'sensitive_permission' => $sensitivePermission])) {
            $this->securityEvents->record('sensitive_data_request', 'high', ['permission' => $permission, 'sensitivity' => $sensitivity], 'File access was denied by policy.', 'request_denied');
            throw new AccessDeniedHttpException('File access denied.');
        }
    }
}
