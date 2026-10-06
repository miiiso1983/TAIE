<?php

namespace App\Modules\Integrations\Models;

use App\Modules\Shared\Models\TenantModel;

class IntegrationConnection extends TenantModel
{
    public const PROVIDERS = ['teami_pro' => 'Teami Pro', 'maxcon' => 'MaxCon', 'odoo' => 'Odoo', 'al_bayan' => 'البيان', 'al_khazen' => 'الخازن', 'custom' => 'برنامج آخر'];
}
