<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Models\BotMessageModel;
use App\Models\BotModel;
use App\Models\DocumentModel;
use CodeIgniter\API\ResponseTrait;

class Bots extends BaseController
{
    use ResponseTrait;

    public function index()
    {
        $userId = $this->currentUserId();
        if (! $userId) {
            return $this->failUnauthorized('Please log in first.');
        }

        $bots = model(BotModel::class)
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->orderBy('created_at', 'DESC')
            ->findAll();

        $documents = $this->loadDocumentsForBots($bots);

        return $this->respond([
            'data' => array_map(
                fn ($bot) => $this->transformBot($bot, $documents[$bot['document_id']] ?? null),
                $bots
            ),
        ]);
    }

    public function show(string $id)
    {
        if (! $this->currentUserId()) {
            return $this->failUnauthorized('Please log in first.');
        }

        $bot = $this->fetchBotForUser($id);
        if (! $bot) {
            return $this->failNotFound('Bot not found.');
        }

        $document = $this->loadDocumentsForBots([$bot])[$bot['document_id']] ?? null;

        return $this->respond([
            'data' => $this->transformBot($bot, $document),
        ]);
    }

    public function create()
    {
        $userId = $this->currentUserId();
        if (! $userId) {
            return $this->failUnauthorized('Please log in first.');
        }

        $payload    = $this->request->getJSON(true) ?? $this->request->getPost();
        $name       = trim((string) ($payload['name'] ?? ''));
        $documentId = trim((string) ($payload['document_id'] ?? ''));

        if ($documentId === '') {
            return $this->failValidationErrors([
                'document_id' => 'Please pick a document.',
            ]);
        }

        $document = model(DocumentModel::class)
            ->where('user_id', $userId)
            ->find($documentId);

        if (! $document) {
            return $this->failNotFound('Document not found.');
        }

        $absolutePath = FCPATH . $document['file_path'];
        if (! is_file($absolutePath)) {
            return $this->fail('Document file is missing on the server.');
        }

        $botId     = bin2hex(random_bytes(16));
        $now       = date('Y-m-d H:i:s');
        $botName   = $name !== '' ? $name : $document['name'];
        $color     = $this->pickColor();

        model(BotModel::class)->insert([
            'id'             => $botId,
            'user_id'        => $userId,
            'name'           => $botName,
            'color'          => $color,
            'document_id'    => $documentId,
            'chat_source_id' => null,
            'status'         => 'active',
            'created_at'     => $now,
            'updated_at'     => $now,
        ]);

        $bot = model(BotModel::class)->find($botId);

        return $this->respondCreated([
            'data' => $this->transformBot($bot, $document),
        ]);
    }

    public function delete(string $id)
    {
        if (! $this->currentUserId()) {
            return $this->failUnauthorized('Please log in first.');
        }

        $bot = $this->fetchBotForUser($id);
        if (! $bot) {
            return $this->failNotFound('Bot not found.');
        }

        model(BotModel::class)->update($id, [
            'status'     => 'archived',
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        model(BotMessageModel::class)->where('bot_id', $id)->delete();

        return $this->respondDeleted(['message' => 'Bot deleted.']);
    }

    /**
     * @param array<int, array<string, mixed>> $bots
     *
     * @return array<string, array<string, mixed>>
     */
    private function loadDocumentsForBots(array $bots): array
    {
        $ids = array_values(array_unique(array_filter(array_map(
            fn ($bot) => $bot['document_id'] ?? null,
            $bots
        ))));

        if ($ids === []) {
            return [];
        }

        $documents = model(DocumentModel::class)
            ->whereIn('id', $ids)
            ->findAll();

        $map = [];
        foreach ($documents as $doc) {
            $map[$doc['id']] = $doc;
        }

        return $map;
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
     * @param array<string, mixed>|null $document
     */
    private function transformBot(array $bot, ?array $document): array
    {
        helper('url');

        return [
            'id'          => $bot['id'],
            'name'        => $bot['name'],
            'color'       => $bot['color'],
            'document_id' => $bot['document_id'],
            'document'    => $document ? [
                'id'        => $document['id'],
                'name'      => $document['name'],
                'file_url'  => base_url($document['file_path']),
                'mime_type' => $document['mime_type'],
            ] : null,
            'created_at'  => $bot['created_at'],
            'updated_at'  => $bot['updated_at'],
        ];
    }

    private function currentUserId(): ?string
    {
        $session = session();
        return $session->get('user_id');
    }

    private function pickColor(): string
    {
        $palette = [
            '#4C6FFF',
            '#2F8D46',
            '#F04D4D',
            '#F2994A',
            '#8F61FF',
        ];

        return $palette[array_rand($palette)];
    }
}
