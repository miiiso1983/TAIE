<?php

namespace App\Core\Contracts;

interface EmbeddingProviderInterface
{
    /** @return list<float> */
    public function embed(string $text): array;

    public function model(): string;
}
