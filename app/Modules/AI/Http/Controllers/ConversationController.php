<?php

namespace App\Modules\AI\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\AI\Models\AIConversation;
use Illuminate\Http\Request;

class ConversationController extends Controller
{
    public function index(Request $request)
    {
        $conversations = AIConversation::query()->where('user_id', $request->user()->getKey())->with(['messages:id,conversation_id,role,content_encrypted,created_at', 'agentProfile:id,uuid,name'])->latest('updated_at')->paginate(min(max($request->integer('per_page', 20), 1), 100));

        return response()->json(['data' => $conversations->getCollection()->map(fn (AIConversation $conversation) => [
            'uuid' => $conversation->uuid,
            'agent' => $conversation->agentProfile ? ['uuid' => $conversation->agentProfile->uuid, 'name' => $conversation->agentProfile->name] : null,
            'status' => $conversation->status,
            'last_message' => $conversation->messages->last() ? ['role' => $conversation->messages->last()->role, 'preview' => mb_substr($conversation->messages->last()->content_encrypted, 0, 180), 'created_at' => $conversation->messages->last()->created_at] : null,
            'updated_at' => $conversation->updated_at,
        ]), 'meta' => ['current_page' => $conversations->currentPage(), 'last_page' => $conversations->lastPage()]]);
    }

    public function messages(Request $request, string $uuid)
    {
        $conversation = AIConversation::query()->where('uuid', $uuid)->where('user_id', $request->user()->getKey())->firstOrFail();
        $messages = $conversation->messages()->orderBy('id')->paginate(min(max($request->integer('per_page', 50), 1), 100));

        return response()->json(['data' => $messages->getCollection()->map(fn ($message) => [
            'role' => $message->role,
            'content' => $message->content_encrypted,
            'citations' => $message->citations,
            'created_at' => $message->created_at,
        ]), 'meta' => ['current_page' => $messages->currentPage(), 'last_page' => $messages->lastPage()]]);
    }
}
