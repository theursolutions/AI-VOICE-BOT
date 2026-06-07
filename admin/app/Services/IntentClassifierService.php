<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class IntentClassifierService
{
    public function classifyIntent(string $userQuery): string
    {
        $prompt = "
            Determine if the following user query is about data (orders, payments, etc.)
            or just a general conversation (like hello, how are you, etc.).

            User query: \"$userQuery\"

            Respond with \"data\" or \"conversation\" only.
        ";


        /* //For Ollama API Locally
        $response = Http::withHeaders([
            'Content-Type' => 'application/json'
        ])->post(config('services.ollama.url'), [
            'model' => config('services.ollama.model'),
            'prompt' => $prompt,
            'stream' => false
        ]);
        $json = $response->json();
        $intent =  trim($json['response'] ?? 'conversation');
        return $intent; */

        
        //For the case of Gemini Call
        $GEMINI_CALL_URL = env('GEMINI_CALL_URL') . env('GEMINI_API_KEY');
        $response = Http::post($GEMINI_CALL_URL, [
            'contents' => [
                [
                    'parts' => [
                        [
                            'text' => $prompt,
                        ],
                    ],
                ],
            ],
        ]); 
        $json = $response->json();
        $intent = trim($json['candidates'][0]['content']['parts'][0]['text'] ?? '');
        return $intent;
    }
}
