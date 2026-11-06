<?php

namespace App\Http\Controllers;

use App\Models\AgentConversation;
use App\Support\Agents\CallRecordings;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A call's recording for the admin to play or download (Agents → an agent → a call). Admins only;
 * the file stays private on Listen's disk. Played from any point (the browser asks by range).
 */
class AdminAgentRecordingController extends Controller
{
    public function __invoke(Request $request, string $call): Response
    {
        abort_unless((bool) $request->user()?->is_admin, 403);
        $record = AgentConversation::query()->where('public_id', $call)->firstOrFail();
        $file = CallRecordings::file($record);
        abort_if($file === null, 404);
        $ext = CallRecordings::TYPES[$record->recording_type] ?? 'webm';

        $response = response()->file($file, ['Content-Type' => (string) $record->recording_type, 'X-Content-Type-Options' => 'nosniff']);
        $response->setContentDisposition($request->boolean('download') ? 'attachment' : 'inline', "call-{$record->public_id}.{$ext}");
        $response->setPrivate();
        $response->headers->set('Cache-Control', 'private, max-age=300');

        return $response;
    }
}
