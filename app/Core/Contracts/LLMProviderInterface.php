<?php

namespace App\Core\Contracts;

interface LLMProviderInterface
{
    /** @param list<array{role:string,content:string}> $messages @return array{content:string,usage:array<string,int>} */
    public function complete(array $messages): array;
}
