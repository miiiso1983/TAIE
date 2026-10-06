<?php

namespace App\Modules\Advisor\Models;

use App\Modules\Shared\Models\TenantModel;

class ReportExplanation extends TenantModel
{
    protected $table = 'advisor_report_explanations';

    protected $hidden = ['question_encrypted', 'answer_encrypted'];

    protected function casts(): array
    {
        return ['question_encrypted' => 'encrypted', 'answer_encrypted' => 'encrypted', 'references' => 'array', 'usage' => 'array'];
    }
}
