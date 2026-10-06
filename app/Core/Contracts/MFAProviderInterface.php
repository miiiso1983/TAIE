<?php

namespace App\Core\Contracts;

interface MFAProviderInterface
{
    public function verify(int $userId, string $code): bool;
}
