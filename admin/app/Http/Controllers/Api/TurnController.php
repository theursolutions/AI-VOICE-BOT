<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\Session;
use App\Services\Conversation\ConversationManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TurnController extends Controller
{
    public function __construct(private ConversationManager $conversation) {}

    public function send(Request $request, int $id): JsonResponse
    {
        $project = $request->attributes->get('project');

        $data = $request->validate([
            'text'          => 'required_without:audio_url|nullable|string',
            'audio_url'     => 'required_without:text|nullable|string',
            'respond_with'  => 'nullable|in:text,audio,both',
            'stream'        => 'nullable|boolean',
        ]);

        $session = Session::where('id', $id)
            ->where('project_id', $project->id)
            ->where('status', 'active')
            ->firstOrFail();

        $now = time();

        $userMessage = Message::create([
            'session_id' => $session->id,
            'project_id' => $project->id,
            'role'       => 'user',
            'content'    => $data['text']      ?? null,
            'audio_url'  => $data['audio_url'] ?? null,
            'created_at' => $now,
        ]);

        $session->last_activity_at = $now;
        $session->update_at = $now;
        $session->save();

        $reply = $this->conversation->handle(
            $session,
            $userMessage,
            $data['respond_with'] ?? 'text'
        );

        return response()->json([
            'session_id'    => $session->id,
            'user_message'  => $userMessage,
            'assistant'     => $reply,
        ]);
    }
}
