<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ConfigureAgentVoicesController extends Controller
{
    // Show voice management UI
    public function index()
    {
        $voices = [];
        if (Storage::exists('agent_voices/voices.json')) {
            $voices = json_decode(Storage::get('agent_voices/voices.json'), true) ?? [];
        }
        return view('agent_voices.index', compact('voices'));
    }

    // Store voice recording, upload to ElevenLabs, and save metadata
    public function store(Request $request)
    {
        $request->validate([
            'voice_name' => 'required|string',
            'audio_blob' => 'required|file|mimes:mp3,wav,webm',
        ]);

        $voiceName = $request->input('voice_name');
        $file = $request->file('audio_blob');

        $fileName = Str::slug($voiceName) . '_' . time() . '.' . $file->getClientOriginalExtension();
        $filePath = $file->storeAs('agent_voices', $fileName);

        $apiKey = env('ELEVENLABS_API_KEY');

        $response = Http::withHeaders([
            'xi-api-key' => $apiKey,
        ])->attach(
            'files', file_get_contents(storage_path("app/$filePath")), $fileName
        )->post('https://api.elevenlabs.io/v1/voices/add', [
            'name' => $voiceName,
            'description' => 'Uploaded from Laravel UI',
            'labels' => json_encode(['source' => 'agent_ui'])
        ]);

        if (!$response->successful()) {
            return response()->json(['error' => $response->body()], 500);
        }

        $voiceId = $response['voice_id'];
        $voiceMeta = [
            'name' => $voiceName,
            'voice_id' => $voiceId,
            'file' => $filePath,
            'created_at' => now()->toDateTimeString()
        ];

        $voices = [];
        if (Storage::exists('agent_voices/voices.json')) {
            $voices = json_decode(Storage::get('agent_voices/voices.json'), true) ?? [];
        }
        $voices[] = $voiceMeta;
        Storage::put('agent_voices/voices.json', json_encode($voices, JSON_PRETTY_PRINT));

        return response()->json(['message' => 'Voice saved successfully!', 'voice' => $voiceMeta]);
    }

    public function process(Request $request)
    {
        if ($request->hasFile('file')) {
            $file = $request->file('file');

            // Optional: validate MIME types and size
            $request->validate([
                'file' => 'required'
            ]);

            // Send file to external API (Ngrok endpoint)
            $response = Http::attach(
                'file',
                file_get_contents($file),
                $file->getClientOriginalName()
            )->post('https://767654ffa8d4.ngrok-free.app/talk');

            if ($response->successful()) {
                // Assume response is an audio file blob (you may receive binary or a URL)
                // Save the returned audio to public/voices
                $outputFileName = 'response_' . time() . '.wav';
                $outputPath = public_path('voices/' . $outputFileName);
                file_put_contents($outputPath, $response->body());

                return response()->json([
                    'success' => true,
                    'user_audio' => asset('voices/' . $file->getClientOriginalName()),
                    'bot_audio' => asset('voices/' . $outputFileName),
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'API call failed.',
                    'error' => $response->body()
                ], 500);
            }
        }

        return response()->json([
            'success' => false,
            'message' => 'No file uploaded.'
        ], 400);
    }
}
