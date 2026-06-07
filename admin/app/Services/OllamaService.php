<?php
namespace App\Services;

use Illuminate\Support\Facades\Http;

class OllamaService
{
    public function generateSqlQuery(string $userQuery, string $schema): string
    {
        $prompt = "
            You are an AI assistant that converts natural language to SQL queries.
            Here’s the database schema:
            $schema

            User query: \"$userQuery\"

            Return ONLY the SQL query.
        ";

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
        return trim($json['candidates'][0]['content']['parts'][0]['text'] ?? '');

        /* 
        //For Ollama Locally
        $response = Http::withHeaders([
            'Content-Type' => 'application/json'
        ])->post(config('services.ollama.url'), [
            'model' => config('services.ollama.model'),
            'prompt' => $prompt,
            'stream' => false
        ]);
        $json = $response->json();
        return trim($json['response'] ?? ''); */
    }

    public function generateConversationalResponse(string $userQuery): string
    {
        $prompt = "
            The following is a friendly, helpful, and conversational response to a user question:

            User query: \"$userQuery\"

            Provide a conversational response:
        ";

        //In Case of Gemini Api
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
        return trim($json['candidates'][0]['content']['parts'][0]['text'] ?? '');

        /* $response = Http::withHeaders([
            'Content-Type' => 'application/json'
        ])->post(config('services.ollama.url'), [
            'model' => config('services.ollama.model'),
            'prompt' => $prompt,
            'stream' => false
        ]);
        $json = $response->json();
        return trim($json['response'] ?? ''); */
    }
}
