<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Models\FolderModel;
use CodeIgniter\API\ResponseTrait;

class Folders extends BaseController
{
    use ResponseTrait;

    public function index()
    {
        $userId = $this->currentUserId();
        if (! $userId) {
            return $this->failUnauthorized('Please log in first.');
        }

        $folders = model(FolderModel::class)
            ->where('user_id', $userId)
            ->orderBy('created_at', 'DESC')
            ->findAll();

        return $this->respond([
            'data' => array_map([$this, 'transformFolder'], $folders),
        ]);
    }

    public function create()
    {
        $userId = $this->currentUserId();
        if (! $userId) {
            return $this->failUnauthorized('Please log in first.');
        }

        $payload = $this->request->getJSON(true) ?? $this->request->getPost();
        $name    = trim((string) ($payload['name'] ?? ''));
        $color   = trim((string) ($payload['color'] ?? '#4C6FFF'));

        if ($name === '') {
            return $this->failValidationErrors(['name' => 'Folder name is required.']);
        }

        $folderId = bin2hex(random_bytes(12));
        $now      = date('Y-m-d H:i:s');

        $model = model(FolderModel::class);
        $model->insert([
            'id'         => $folderId,
            'user_id'    => $userId,
            'name'       => $name,
            'color'      => $color,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $folder = $model->find($folderId);

        return $this->respondCreated([
            'data' => $this->transformFolder($folder),
        ]);
    }

    public function update(string $id)
    {
        $folder = $this->fetchFolderForUser($id);
        if ($folder === null) {
            return $this->failNotFound('Folder not found.');
        }

        $payload = $this->request->getJSON(true) ?? $this->request->getRawInput();
        $data    = [];

        if (isset($payload['name'])) {
            $name = trim((string) $payload['name']);
            if ($name === '') {
                return $this->failValidationErrors(['name' => 'Folder name cannot be empty.']);
            }
            $data['name'] = $name;
        }

        if (isset($payload['color'])) {
            $data['color'] = trim((string) $payload['color']);
        }

        if ($data === []) {
            return $this->failValidationErrors(['payload' => 'Nothing to update.']);
        }

        $data['updated_at'] = date('Y-m-d H:i:s');

        model(FolderModel::class)->update($id, $data);

        $updated = $this->fetchFolderForUser($id);

        return $this->respond([
            'data' => $this->transformFolder($updated),
        ]);
    }

    public function delete(string $id)
    {
        $folder = $this->fetchFolderForUser($id);
        if ($folder === null) {
            return $this->failNotFound('Folder not found.');
        }

        model(FolderModel::class)->delete($id);

        return $this->respondDeleted([
            'message' => 'Folder deleted successfully.',
        ]);
    }

    private function fetchFolderForUser(string $id): ?array
    {
        $userId = $this->currentUserId();
        if (! $userId) {
            return null;
        }

        return model(FolderModel::class)
            ->where('user_id', $userId)
            ->find($id);
    }

    private function transformFolder(array $folder): array
    {
        return [
            'id'         => $folder['id'],
            'name'       => $folder['name'],
            'color'      => $folder['color'],
            'created_at' => $folder['created_at'],
            'updated_at' => $folder['updated_at'],
        ];
    }

    private function currentUserId(): ?string
    {
        return session()->get('user_id');
    }
}

