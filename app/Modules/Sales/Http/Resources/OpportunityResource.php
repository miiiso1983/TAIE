<?php

namespace App\Modules\Sales\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OpportunityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['uuid' => $this->uuid, 'name' => $this->name, 'status' => $this->status, 'amount' => $this->amount, 'currency_code' => $this->currency_code, 'probability' => $this->probability, 'expected_close_on' => $this->expected_close_on?->toDateString(), 'customer' => $this->whenLoaded('customer', fn () => ['uuid' => $this->customer?->uuid, 'name' => $this->customer?->name]), 'pipeline' => $this->whenLoaded('pipeline', fn () => ['uuid' => $this->pipeline?->uuid, 'name' => $this->pipeline?->name]), 'stage' => $this->whenLoaded('stage', fn () => ['uuid' => $this->stage?->uuid, 'name' => $this->stage?->name, 'sort_order' => $this->stage?->sort_order])];
    }
}
