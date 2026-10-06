<?php

namespace App\Modules\Advisor\Models;

use App\Modules\Shared\Models\TenantModel;

class Product extends TenantModel
{
    protected $table = 'advisor_products';
}
