<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Models\GeneralChatMessageModel;
use CodeIgniter\API\ResponseTrait;

class GeneralChat extends BaseController
{
    use ResponseTrait;

    public function index()
    {
        $userId = $this->currentUserId();
        if (! $userId) {
            return $this->failUnauthorized('Please log in first.');
        }

        $messages = model(GeneralChatMessageModel::class)
            ->where('user_id', $userId)
            ->orderBy('id', 'ASC')
            ->findAll();

        return $this->respond([
            'data' => array_map([$this, 'transformMessage'], $messages),
        ]);
    }

    public function create()
    {
        $userId = $this->currentUserId();
        if (! $userId) {
            return $this->failUnauthorized('Please log in first.');
        }

        $payload = $this->request->getJSON(true) ?? $this->request->getPost();
        $role    = $payload['role'] ?? 'user';
        $type    = $payload['type'] ?? 'text';
        $content = trim((string) ($payload['content'] ?? ''));

        if ($content === '') {
            return $this->failValidationErrors(['content' => 'Message cannot be empty.']);
        }

        if (! in_array($role, ['user', 'assistant'], true)) {
            return $this->failValidationErrors(['role' => 'Role must be user or assistant.']);
        }

        if (! in_array($type, ['text', 'image'], true)) {
            return $this->failValidationErrors(['type' => 'Type must be text or image.']);
        }

        $model = model(GeneralChatMessageModel::class);
        $id = $model->insert([
            'user_id'    => $userId,
            'role'       => $role,
            'type'       => $type,
            'content'    => $content,
            'image_path' => $payload['image_path'] ?? null,
            'created_at' => date('Y-m-d H:i:s'),
        ], true);

        $message = $model->find($id);

        return $this->respondCreated([
            'data' => $this->transformMessage($message),
        ]);
    }

    private function transformMessage(array $message): array
    {
        return [
            'id'         => (int) $message['id'],
            'role'       => $message['role'],
            'type'       => $message['type'],
            'content'    => $message['content'],
            'image_path' => $message['image_path'],
            'created_at' => $message['created_at'],
        ];
    }

    private function currentUserId(): ?string
    {
        return session()->get('user_id');
    }
}
