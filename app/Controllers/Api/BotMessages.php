<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Models\BotMessageModel;
use App\Models\BotModel;
use App\Models\DocumentModel;
use App\Services\GeminiService;
use CodeIgniter\API\ResponseTrait;

class BotMessages extends BaseController
{
    use ResponseTrait;

    private GeminiService $gemini;

    public function __construct()
    {
        $this->gemini = new GeminiService();
    }

    public function index(string $botId)
    {
        if (! $this->currentUserId()) {
            return $this->failUnauthorized('Please log in first.');
        }

        $bot = $this->fetchBotForUser($botId);
        if (! $bot) {
            return $this->failNotFound('Bot not found.');
        }

        $messages = model(BotMessageModel::class)
            ->where('bot_id', $botId)
            ->orderBy('id', 'ASC')
            ->findAll();

        return $this->respond([
            'data' => array_map([$this, 'transformMessage'], $messages),
        ]);
    }

    public function create(string $botId)
    {
        if (! $this->currentUserId()) {
            return $this->failUnauthorized('Please log in first.');
        }

        $bot = $this->fetchBotForUser($botId);
        if (! $bot) {
            return $this->failNotFound('Bot not found.');
        }

        $payload = $this->request->getJSON(true) ?? $this->request->getPost();
        $text    = trim((string) ($payload['message'] ?? ''));

        if ($text === '') {
            return $this->failValidationErrors([
                'message' => 'Message cannot be empty.',
            ]);
        }

        $document = $this->loadDocument($bot);
        if ($document === null) {
            return $this->fail('Document file is missing on the server.');
        }

        if ($this->gemini->hasCredentials() === false) {
            return $this->failServerError('AI service is not configured.');
        }

        $history     = $this->buildHistory($botId);
        $userMessage = $this->storeMessage($botId, 'user', $text);

        $contents = $this->buildGeminiRequest($history, $text, $document);
        $response = $this->gemini->generateContent($contents);

        if ($response['content'] === null) {
            return $this->fail($response['error'] ?? 'Unable to contact AI service.');
        }

        $assistantMessage = $this->storeMessage($botId, 'assistant', $response['content']);

        return $this->respondCreated([
            'data' => [
                'user'      => $this->transformMessage($userMessage),
                'assistant' => $this->transformMessage($assistantMessage),
            ],
        ]);
    }

    private function storeMessage(string $botId, string $role, string $content): array
    {
        $now = date('Y-m-d H:i:s');
        $messageId = model(BotMessageModel::class)->insert([
            'bot_id'    => $botId,
            'user_id'   => $this->currentUserId(),
            'role'      => $role,
            'content'   => $content,
            'created_at'=> $now,
        ], true);

        return [
            'id'         => (int) $messageId,
            'bot_id'     => $botId,
            'user_id'    => $this->currentUserId(),
            'role'       => $role,
            'content'    => $content,
            'created_at' => $now,
        ];
    }

    private function fetchBotForUser(string $id): ?array
    {
        $userId = $this->currentUserId();
        if (! $userId) {
            return null;
        }

        return model(BotModel::class)
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->find($id);
    }

    /**
     * @return array<int, array{role: string, content: string}>
     */
    private function buildHistory(string $botId): array
    {
        $records = model(BotMessageModel::class)
            ->where('bot_id', $botId)
            ->orderBy('id', 'ASC')
            ->findAll();

        return array_map(
            fn ($message) => [
                'role'    => $message['role'],
                'content' => $message['content'],
            ],
            $records
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadDocument(array $bot): ?array
    {
        if (empty($bot['document_id'])) {
            return null;
        }

        /** @var array<string, mixed>|null $document */
        $document = model(DocumentModel::class)
            ->where('id', $bot['document_id'])
            ->where('user_id', $bot['user_id'])
            ->first();

        if (! $document) {
            return null;
        }

        $absolutePath = FCPATH . $document['file_path'];
        if (! is_file($absolutePath)) {
            return null;
        }

        $blob = file_get_contents($absolutePath);
        if ($blob === false) {
            return null;
        }

        $mimeType = $document['mime_type'] ?? mime_content_type($absolutePath) ?: 'application/pdf';

        $document['absolute_path'] = $absolutePath;
        $document['resolved_mime'] = $mimeType;
        $document['base64_data']  = base64_encode($blob);

        return $document;
    }

    /**
     * @param array<int, array{role: string, content: string}> $history
     * @param array<string, mixed> $document
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildGeminiRequest(array $history, string $question, array $document): array
    {
        $contents = [];
        foreach ($history as $message) {
            $contents[] = [
                'role'  => $message['role'] === 'assistant' ? 'model' : 'user',
                'parts' => [
                    ['text' => $message['content']],
                ],
            ];
        }

        $inlinePart   = [
            'inlineData' => [
                'mimeType' => $document['resolved_mime'],
                'data'     => $document['base64_data'],
            ],
        ];

        $prompt = "You are WriteScan's AI reviewer. Answer the user's question strictly "
            . "using the attached document. If the answer is not in the document, say that "
            . "you cannot find it.";

        $contents[] = [
            'role'  => 'user',
            'parts' => [
                $inlinePart,
                [
                    'text' => $prompt . "\n\nQuestion: " . $question,
                ],
            ],
        ];

        return $contents;
    }

    private function transformMessage(array $message): array
    {
        return [
            'id'         => (int) $message['id'],
            'bot_id'     => $message['bot_id'],
            'role'       => $message['role'],
            'content'    => $message['content'],
            'created_at' => $message['created_at'],
        ];
    }

    private function currentUserId(): ?string
    {
        $session = session();
        return $session->get('user_id');
    }
}
