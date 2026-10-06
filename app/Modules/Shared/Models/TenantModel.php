<?php

namespace App\Modules\Shared\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

abstract class TenantModel extends Model
{
    use BelongsToTenant;

    protected $guarded = [];
}
