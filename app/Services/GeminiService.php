<?php

namespace App\Services;

use CodeIgniter\HTTP\CURLRequest;
use Config\Services;
use Throwable;

class GeminiService
{
    private CURLRequest $client;
    private string $apiKey;
    private string $model;

    public function __construct(?CURLRequest $client = null, ?string $apiKey = null, ?string $model = null)
    {
        $this->client = $client ?: Services::curlrequest([
            'baseURI' => 'https://generativelanguage.googleapis.com/v1beta/',
            'timeout' => 60,
        ]);

        $this->apiKey = $apiKey ?? (string) (getenv('GEMINI_API_KEY') ?: '');
        $this->model  = $model ?? (string) (getenv('GEMINI_MODEL') ?: 'models/gemini-flash-latest');
    }

    public function hasCredentials(): bool
    {
        return $this->apiKey !== '';
    }

    /**
     * @param array<int, array<string, mixed>> $contents
     *
     * @return array{content: ?string, error: ?string}
     */
    public function generateContent(array $contents): array
    {
        if (! $this->hasCredentials()) {
            return [
                'content' => null,
                'error'   => 'Gemini API key is missing.',
            ];
        }

        try {
            $response = $this->client->post(
                $this->model . ':generateContent?key=' . $this->apiKey,
                [
                    'json'    => ['contents' => $contents],
                    'timeout' => 60,
                ]
            );

            $body = json_decode($response->getBody(), true) ?: [];

            if ($response->getStatusCode() >= 400 || isset($body['error'])) {
                $message = $body['error']['message'] ?? 'Gemini request failed.';

                return [
                    'content' => null,
                    'error'   => $message,
                ];
            }

            $text = $body['candidates'][0]['content']['parts'][0]['text'] ?? null;

            return [
                'content' => $text,
                'error'   => null,
            ];
        } catch (Throwable $e) {
            return [
                'content' => null,
                'error'   => $e->getMessage(),
            ];
        }
    }
}
