<?php

namespace App\Modules\Sales\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['uuid' => $this->uuid, 'customer_number' => $this->customer_number, 'name' => $this->name, 'legal_name' => $this->legal_name, 'type' => $this->type, 'industry' => $this->industry, 'website' => $this->website, 'email' => $this->email, 'phone' => $this->phone, 'country_code' => $this->country_code, 'status' => $this->status];
    }
}
