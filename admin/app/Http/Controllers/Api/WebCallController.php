<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\Telephony\WebCalls;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * POST /api/webcall/session — a website visitor pressed "Call us".
 *
 * Public (the button runs on the customer's own website), so: the project
 * is named by its public widget key, the calling site must be one the
 * project allows, the visitor's IP must be inside the project's limits,
 * and what comes back is a one-call SIP account that can reach the AI and
 * nothing else (App\Services\Telephony\WebCalls).
 */
class WebCallController extends Controller
{
    public function __construct(private WebCalls $calls) {}

    public function preflight(Request $request): Response
    {
        return $this->cors(response('', 204), $request);
    }

    public function session(Request $request): JsonResponse
    {
        $data = $request->validate([
            'key'  => 'required|string|max:100',
            'page' => 'nullable|string|max:500',
            // The visitor's pick in the call box: the AI greets in it.
            'lang' => 'nullable|in:en,ur',
            // Random id the button keeps per browser: limits count per device.
            'device' => 'nullable|string|max:64',
        ]);
        $device = WebCalls::deviceKey($data['device'] ?? null);

        // The same public key the chat widget is embedded with.
        $project = Project::where('project_api_key', $data['key'])->where('is_active', 'Yes')->first();
        if (! $project || ! $this->calls->settings($project)['enabled']) {
            return $this->cors(response()->json(['ok' => false, 'reason' => 'disabled',
                'message' => 'Calling is not available right now.'], 403), $request);
        }

        $origin = $request->header('Origin') ?: $request->header('Referer');
        if (! $this->calls->originAllowed($project, $origin)) {
            Log::info('webcall: origin refused', ['project_id' => $project->id, 'origin' => $origin]);
            return $this->cors(response()->json(['ok' => false, 'reason' => 'origin',
                'message' => 'Calling is not available on this website.'], 403), $request);
        }

        $ip = (string) $request->ip();
        if ($refusal = $this->calls->refusal($project, $ip, null, $device)) {
            return $this->cors(response()->json(['ok' => false] + $refusal, 429)
                ->header('Retry-After', (string) $refusal['retry_after']), $request);
        }

        try {
            $call = $this->calls->issue($project, $ip, $origin, $data['page'] ?? null, $data['lang'] ?? null, $device);
        } catch (\Throwable $e) {
            Log::warning('webcall: account not issued', ['project_id' => $project->id, 'error' => $e->getMessage()]);
            return $this->cors(response()->json(['ok' => false, 'reason' => 'unavailable',
                'message' => 'Our phone line is busy right now. Please try again in a few minutes.'], 503), $request);
        }

        return $this->cors(response()->json(['ok' => true] + $call), $request);
    }

    /** The button runs on another site: answer its browser's CORS checks. */
    private function cors($response, Request $request)
    {
        $origin = (string) $request->header('Origin', '');

        return $response->withHeaders(array_filter([
            'Access-Control-Allow-Origin'  => $origin !== '' ? $origin : '*',
            'Access-Control-Allow-Methods' => 'POST, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, Accept',
            'Access-Control-Max-Age'       => '600',
            'Vary'                         => 'Origin',
        ]));
    }
}
