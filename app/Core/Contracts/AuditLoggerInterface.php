<?php

namespace App\Core\Contracts;

interface AuditLoggerInterface
{
    public function record(string $action, array $data = []): void;
}
