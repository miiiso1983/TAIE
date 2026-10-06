<?php

namespace App\Modules\HR\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['uuid' => $this->uuid, 'employee_number' => $this->employee_number, 'first_name' => $this->first_name, 'last_name' => $this->last_name, 'work_email' => $this->work_email, 'employment_status' => $this->employment_status, 'employment_type' => $this->employment_type, 'hired_at' => $this->hired_at?->toDateString(), 'department' => $this->whenLoaded('department', fn () => ['uuid' => $this->department?->uuid, 'name' => $this->department?->name]), 'position' => $this->whenLoaded('position', fn () => ['uuid' => $this->position?->uuid, 'title' => $this->position?->title])];
    }
}
