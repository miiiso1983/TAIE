<?php

namespace App\Core\Support;

use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

class TenantStorage
{
    public function __construct(private TenantContext $context) {}

    public function path(string $category, ?string $filename = null): string
    {
        if (! $this->context->hasTenant()) {
            throw new LogicException('Tenant context is required for tenant storage.');
        }
        if (! in_array($category, ['documents', 'avatars', 'reports'], true)) {
            throw new InvalidArgumentException('Unsupported tenant file category.');
        }
        if ($filename !== null && (str_contains($filename, '..') || str_contains($filename, '/') || str_contains($filename, '\\'))) {
            throw new InvalidArgumentException('Invalid filename.');
        }

        return 'tenants/'.$this->context->requireTenant()->uuid.'/'.$category.'/'.($filename ? basename($filename) : Str::uuid());
    }

    public function put(string $category, string $contents, ?string $extension = null): string
    {
        $name = Str::uuid().($extension ? '.'.ltrim($extension, '.') : '');
        $path = $this->path($category, $name);
        Storage::disk('local')->put($path, $contents);

        return $path;
    }
}
