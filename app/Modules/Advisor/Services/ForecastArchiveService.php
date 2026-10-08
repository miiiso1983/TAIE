<?php

namespace App\Modules\Advisor\Services;

use App\Core\Logging\AuditTrailService;
use App\Models\User;
use App\Modules\Advisor\Models\ProductForecastRun;
use Illuminate\Support\Str;

class ForecastArchiveService
{
    public function save(array $data, User $reviewer): ProductForecastRun
    {
        $result = app(ProductForecastService::class)->analyze($data);
        $hash = hash('sha256', json_encode([$result['fingerprint'], $result['algorithm_version'], $data['horizon_weeks'] ?? 1, $data['coverage_note'], $reviewer->id], JSON_THROW_ON_ERROR));
        $run = ProductForecastRun::query()->firstOrCreate(['input_hash' => $hash], ['uuid' => Str::uuid(), 'fingerprint' => $result['fingerprint'], 'created_by' => $reviewer->id, 'snapshot_encrypted' => $result]);
        app(AuditTrailService::class)->record('advisor.forecast.analyzed', ['module' => 'advisor', 'entity_type' => 'product_forecast', 'entity_id' => $run->uuid, 'new_values' => ['fingerprint' => $result['fingerprint'], 'status' => $result['status'], 'from' => $data['from'], 'to' => $data['to']]]);

        return $run;
    }
}
