<?php

namespace App\Core\Contracts;

interface AuthorizationInterface
{
    public function allows(object $user, string $permission, array $attributes = []): bool;
}
