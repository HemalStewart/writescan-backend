<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Models\DocumentModel;
use CodeIgniter\API\ResponseTrait;
use CodeIgniter\HTTP\Files\UploadedFile;

class Documents extends BaseController
{
    use ResponseTrait;

    public function index()
    {
        $userId = $this->currentUserId();
        if (! $userId) {
            return $this->failUnauthorized('Please log in first.');
        }

        $updatedAfter = $this->request->getGet('updated_after');

        $model = model(DocumentModel::class)
            ->where('user_id', $userId)
            ->where('status !=', 'archived');

        if ($updatedAfter) {
            $model->where('updated_at >', $updatedAfter);
        }

        $documents = $model->orderBy('created_at', 'DESC')->findAll();

        return $this->respond([
            'data' => array_map([$this, 'transformDocument'], $documents),
        ]);
    }

    public function show(string $id)
    {
        $document = $this->fetchDocumentForUser($id);
        if ($document === null) {
            return $this->failNotFound('Document not found.');
        }

        return $this->respond([
            'data' => $this->transformDocument($document),
        ]);
    }

    public function create()
    {
        $userId = $this->currentUserId();
        if (! $userId) {
            return $this->failUnauthorized('Please log in first.');
        }

        $file = $this->request->getFile('file');
        if (! $file instanceof UploadedFile || ! $file->isValid()) {
            return $this->failValidationErrors([
                'file' => 'Valid document file is required.',
            ]);
        }

        $name      = trim((string) $this->request->getPost('name'));
        $type      = trim((string) $this->request->getPost('type'));
        $folderId  = $this->request->getPost('folder_id');
        $geminiTxt = $this->request->getPost('gemini_text');

        if ($name === '') {
            $name = $file->getClientName();
        }

        $documentId  = bin2hex(random_bytes(16));
        $storedName  = $documentId . '_' . $file->getRandomName();
        $relativePath = 'uploads/documents/' . $storedName;
        $destination   = FCPATH . $relativePath;

        // Capture metadata before moving the temp file because once it moves
        // PHP deletes the original tmp path and CodeIgniter can no longer
        // inspect size/mimetype on it.
        $fileSize = $file->getSize();
        $mimeType = $file->getMimeType();

        $this->ensureDirectory(dirname($destination));

        if (! $file->move(dirname($destination), basename($destination))) {
            return $this->fail('Failed to store uploaded file.');
        }

        $now = date('Y-m-d H:i:s');

        $documentModel = model(DocumentModel::class);
        $documentModel->insert([
            'id'          => $documentId,
            'user_id'     => $userId,
            'name'        => $name,
            'type'        => $type !== '' ? $type : 'pdf',
            'folder_id'   => $folderId ?: null,
            'file_path'   => $relativePath,
            'file_size'   => $fileSize,
            'mime_type'   => $mimeType,
            'gemini_text' => $geminiTxt ?: null,
            'status'      => 'active',
            'created_at'  => $now,
            'updated_at'  => $now,
            'synced_at'   => $now,
        ]);

        $document = $documentModel->find($documentId);

        return $this->respondCreated([
            'data' => $this->transformDocument($document),
        ]);
    }

    public function update(string $id)
    {
        $document = $this->fetchDocumentForUser($id);
        if ($document === null) {
            return $this->failNotFound('Document not found.');
        }

        $payload = $this->request->getJSON(true) ?? $this->request->getRawInput();

        $data = [];
        if (isset($payload['name'])) {
            $name = trim((string) $payload['name']);
            if ($name === '') {
                return $this->failValidationErrors(['name' => 'Name cannot be empty.']);
            }
            $data['name'] = $name;
        }
        if (array_key_exists('folder_id', $payload)) {
            $data['folder_id'] = $payload['folder_id'] ?: null;
        }
        if (isset($payload['gemini_text'])) {
            $data['gemini_text'] = $payload['gemini_text'];
        }

        if ($data === []) {
            return $this->failValidationErrors(['payload' => 'Nothing to update.']);
        }

        $data['updated_at'] = date('Y-m-d H:i:s');

        model(DocumentModel::class)->update($id, $data);

        $updated = $this->fetchDocumentForUser($id);

        return $this->respond([
            'data' => $this->transformDocument($updated),
        ]);
    }

    public function delete(string $id)
    {
        $document = $this->fetchDocumentForUser($id);
        if ($document === null) {
            return $this->failNotFound('Document not found.');
        }

        $model = model(DocumentModel::class);
        $model->update($id, [
            'status'     => 'archived',
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return $this->respondDeleted([
            'message' => 'Document archived successfully.',
        ]);
    }

    private function fetchDocumentForUser(string $id): ?array
    {
        $userId = $this->currentUserId();
        if (! $userId) {
            return null;
        }

        return model(DocumentModel::class)
            ->where('user_id', $userId)
            ->find($id);
    }

    private function transformDocument(array $document): array
    {
        return [
            'id'          => $document['id'],
            'name'        => $document['name'],
            'type'        => $document['type'],
            'folder_id'   => $document['folder_id'],
            'file_url'    => $this->buildAssetUrl($document['file_path']),
            'file_size'   => (int) $document['file_size'],
            'mime_type'   => $document['mime_type'],
            'gemini_text' => $document['gemini_text'],
            'status'      => $document['status'],
            'created_at'  => $document['created_at'],
            'updated_at'  => $document['updated_at'],
            'synced_at'   => $document['synced_at'],
        ];
    }

    private function buildAssetUrl(?string $relativePath): ?string
    {
        if (! $relativePath) {
            return null;
        }

        $base = rtrim(base_url(), '/');
        if (substr($base, -4) === '/api') {
            $base = substr($base, 0, -4);
        }

        return $base . '/' . ltrim($relativePath, '/');
    }

    private function ensureDirectory(string $path): void
    {
        if (! is_dir($path)) {
            mkdir($path, 0755, true);
        }
    }

    private function currentUserId(): ?string
    {
        $session = session();
        return $session->get('user_id');
    }
}
