<?php

namespace App\Modules\AI\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\AI\Models\AgentProfile;
use App\Modules\AI\Services\RagAnswerService;
use Illuminate\Http\Request;

class AskController extends Controller
{
    public function __invoke(Request $request, RagAnswerService $service)
    {
        $data = $request->validate(['question' => ['required', 'string', 'max:'.(int) config('teami_ai.max_question_chars', 8000)], 'conversation_uuid' => ['nullable', 'uuid'], 'agent_uuid' => ['nullable', 'uuid']]);
        if (! empty($data['agent_uuid'])) {
            abort_unless(AgentProfile::query()->where('uuid', $data['agent_uuid'])->where('active', true)->exists(), 404, 'Agent not found.');
        }

        return response()->json(['data' => $service->answer($request->user(), $data['question'], $data['conversation_uuid'] ?? null, $data['agent_uuid'] ?? null)]);
    }
}
