<?php

namespace App\Modules\Advisor\Models;

use App\Modules\Shared\Models\TenantModel;

class InventoryObservation extends TenantModel
{
    protected $table = 'advisor_inventory_observations';

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'amount_minor' => 'integer', 'observed_on' => 'date', 'end_date' => 'date'];
    }
}
