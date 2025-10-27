<?php

namespace App\Models;

use CodeIgniter\Model;

class DocumentModel extends Model
{
    protected $table            = 'documents';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $allowedFields    = [
        'id',
        'user_id',
        'name',
        'type',
        'folder_id',
        'file_path',
        'file_size',
        'mime_type',
        'gemini_text',
        'status',
        'created_at',
        'updated_at',
        'synced_at',
    ];
    protected $useTimestamps    = false;
}

