<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Services\System\EnvManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Per-workspace control panel for the LLM provider, voice compute
 * device, and Whisper model size. Writes directly into the Python
 * voice-engine's .env file so the next service reload picks them up.
 *
 * NB: settings are GLOBAL across all projects today because the Python
 * voice-engine is a single shared instance. Per-project routing is a
 * future enhancement.
 */
class BrainSettingsController extends Controller
{
    /** Provider menu shown in the UI. */
    public const PROVIDERS = [
        'groq' => [
            'label'       => 'Groq (cloud)',
            'description' => 'Fast and free for low-volume. llama-3.3-70b is the strongest free option.',
            'fields'      => ['GROQ_API_KEY', 'GROQ_MODEL'],
            'default_model' => 'llama-3.3-70b-versatile',
        ],
        'anthropic' => [
            'label'       => 'Anthropic Claude',
            'description' => 'Best quality. Paid only. claude-opus-4-7 is the smartest model.',
            'fields'      => ['ANTHROPIC_API_KEY', 'ANTHROPIC_MODEL'],
            'default_model' => 'claude-opus-4-7',
        ],
        'gemini' => [
            'label'       => 'Google Gemini',
            'description' => 'Generous free tier. Good for high-volume cheap traffic.',
            'fields'      => ['GEMINI_API_KEY', 'GEMINI_MODEL'],
            'default_model' => 'gemini-2.0-flash-lite',
        ],
        'ollama' => [
            'label'       => 'Ollama (local)',
            'description' => 'Runs on your hardware. Zero per-token cost. Slower without GPU.',
            'fields'      => ['OLLAMA_BASE_URL', 'OLLAMA_MODEL'],
            'default_model' => 'qwen2.5:7b',
        ],
    ];

    public const WHISPER_MODELS = ['tiny', 'base', 'small', 'medium', 'large-v3'];
    public const COMPUTE_TYPES  = ['int8', 'int8_float16', 'float16', 'float32'];

    public function index(Request $request, Client $client): View
    {
        $env = $this->envManager()->all();

        $current = [
            'provider'             => $env['LLM_PROVIDER']       ?? 'groq',
            'groq_api_key'         => $env['GROQ_API_KEY']       ?? '',
            'groq_model'           => $env['GROQ_MODEL']         ?? 'llama-3.3-70b-versatile',
            'anthropic_api_key'    => $env['ANTHROPIC_API_KEY']  ?? '',
            'anthropic_model'      => $env['ANTHROPIC_MODEL']    ?? 'claude-opus-4-7',
            'gemini_api_key'       => $env['GEMINI_API_KEY']     ?? '',
            'gemini_model'         => $env['GEMINI_MODEL']       ?? 'gemini-2.0-flash-lite',
            'ollama_base_url'      => $env['OLLAMA_BASE_URL']    ?? 'http://localhost:11434/v1',
            'ollama_model'         => $env['OLLAMA_MODEL']       ?? 'qwen2.5:7b',
            'whisper_model'        => $env['WHISPER_MODEL']      ?? 'base',
            'whisper_device'       => $env['WHISPER_DEVICE']     ?? 'cpu',
            'whisper_compute_type' => $env['WHISPER_COMPUTE_TYPE'] ?? 'int8',
            'coqui_use_gpu'        => (($env['COQUI_USE_GPU'] ?? 'False') === 'True') ? 'true' : 'false',
        ];

        return view('brain-settings.index', [
            'client'       => $client,
            'current'      => $current,
            'providers'    => self::PROVIDERS,
            'whisperModels'=> self::WHISPER_MODELS,
            'computeTypes' => self::COMPUTE_TYPES,
        ]);
    }

    public function update(Request $request, Client $client): RedirectResponse
    {
        $data = $request->validate([
            'provider'             => 'required|in:groq,anthropic,gemini,ollama',
            'groq_api_key'         => 'nullable|string|max:512',
            'groq_model'           => 'nullable|string|max:120',
            'anthropic_api_key'    => 'nullable|string|max:512',
            'anthropic_model'      => 'nullable|string|max:120',
            'gemini_api_key'       => 'nullable|string|max:512',
            'gemini_model'         => 'nullable|string|max:120',
            'ollama_base_url'      => 'nullable|string|max:512',
            'ollama_model'         => 'nullable|string|max:120',
            'whisper_model'        => 'required|in:tiny,base,small,medium,large-v3',
            'whisper_device'       => 'required|in:cpu,cuda',
            'whisper_compute_type' => 'required|in:int8,int8_float16,float16,float32',
            'coqui_use_gpu'        => 'required|in:true,false',
        ]);

        $env = $this->envManager();

        $env->setMany([
            'LLM_PROVIDER'         => $data['provider'],

            'GROQ_API_KEY'         => $data['groq_api_key']      ?? '',
            'GROQ_MODEL'           => $data['groq_model']        ?? 'llama-3.3-70b-versatile',

            'ANTHROPIC_API_KEY'    => $data['anthropic_api_key'] ?? '',
            'ANTHROPIC_MODEL'      => $data['anthropic_model']   ?? 'claude-opus-4-7',

            'GEMINI_API_KEY'       => $data['gemini_api_key']    ?? '',
            'GEMINI_MODEL'         => $data['gemini_model']      ?? 'gemini-2.0-flash-lite',

            'OLLAMA_BASE_URL'      => $data['ollama_base_url']   ?? 'http://localhost:11434/v1',
            'OLLAMA_MODEL'         => $data['ollama_model']      ?? 'qwen2.5:7b',

            'WHISPER_MODEL'        => $data['whisper_model'],
            'WHISPER_DEVICE'       => $data['whisper_device'],
            'WHISPER_COMPUTE_TYPE' => $data['whisper_compute_type'],
            'COQUI_USE_GPU'        => $data['coqui_use_gpu'] === 'true' ? 'True' : 'False',
        ]);

        return redirect()
            ->route('brain-settings.index', ['client' => $client->slug])
            ->with('success', 'Saved. Click "Reload Python" to apply.');
    }

    /**
     * POST /c/{client}/brain-settings/reload — asks the Python voice
     * engine to re-read its env + rebuild the LLM + STT + TTS services
     * without a full uvicorn restart.
     */
    public function reload(Request $request, Client $client): JsonResponse
    {
        return response()->json($this->callPythonReload());
    }

    /**
     * POST /c/{client}/brain-settings/toggle-brain
     *
     * One-click swap between local Ollama and the last-used cloud
     * provider. Remembers which cloud provider was active before the
     * user went local, so toggling back returns to that exact setup
     * (e.g. Anthropic if that's what they were on).
     */
    public function toggleBrain(Request $request, Client $client): JsonResponse
    {
        $env = $this->envManager();
        $now = $env->all();
        $current = $now['LLM_PROVIDER'] ?? 'groq';

        if ($current === 'ollama') {
            // Coming back from local → restore the last cloud provider
            // (stored separately so we don't lose it across toggles).
            $target = $now['LLM_PROVIDER_PREV_CLOUD'] ?? 'groq';
            $env->setMany([
                'LLM_PROVIDER' => $target,
            ]);
        } else {
            // Going to local → remember the current cloud so toggling
            // back returns to it.
            $env->setMany([
                'LLM_PROVIDER_PREV_CLOUD' => $current,
                'LLM_PROVIDER'            => 'ollama',
            ]);
            $target = 'ollama';
        }

        $reloadResp = $this->callPythonReload();

        return response()->json([
            'ok'        => $reloadResp['ok'],
            'provider'  => $target,
            'reload'    => $reloadResp,
        ]);
    }

    /**
     * POST /c/{client}/brain-settings/toggle-device
     *
     * One-click swap between CPU and GPU. Bundles all three voice
     * compute settings (whisper device, whisper compute type, Coqui
     * GPU) so the caller doesn't have to think about whether float16
     * or int8 is correct for the device.
     */
    public function toggleDevice(Request $request, Client $client): JsonResponse
    {
        $env = $this->envManager();
        $now = $env->all();
        $currentDevice = $now['WHISPER_DEVICE'] ?? 'cpu';

        if ($currentDevice === 'cuda') {
            // GPU → CPU
            $env->setMany([
                'WHISPER_DEVICE'       => 'cpu',
                'WHISPER_COMPUTE_TYPE' => 'int8',
                'COQUI_USE_GPU'        => 'False',
            ]);
            $target = 'cpu';
        } else {
            // CPU → GPU
            $env->setMany([
                'WHISPER_DEVICE'       => 'cuda',
                'WHISPER_COMPUTE_TYPE' => 'float16',
                'COQUI_USE_GPU'        => 'True',
            ]);
            $target = 'cuda';
        }

        $reloadResp = $this->callPythonReload();

        return response()->json([
            'ok'      => $reloadResp['ok'],
            'device'  => $target,
            'reload'  => $reloadResp,
        ]);
    }

    /** Shared cURL to Python's /admin/reload — used by all save paths. */
    private function callPythonReload(): array
    {
        $base   = (string) config('services.python.base_url');
        $secret = (string) config('services.python.internal_secret');
        $url    = rtrim($base, '/') . '/admin/reload';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_HTTPHEADER     => [
                'X-Internal-Secret: ' . $secret,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS     => '{}',
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        return [
            'ok'    => $err === '' && $code >= 200 && $code < 300,
            'code'  => $code,
            'body'  => $body,
            'error' => $err ?: null,
        ];
    }

    private function envManager(): EnvManager
    {
        // voice-engine/.env relative to admin
        $envPath = base_path('../voice-engine/.env');
        return new EnvManager(realpath($envPath) ?: $envPath);
    }
}
