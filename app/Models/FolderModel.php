<?php

namespace App\Models;

use CodeIgniter\Model;

class FolderModel extends Model
{
    protected $table            = 'folders';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $allowedFields    = [
        'id',
        'user_id',
        'name',
        'color',
        'created_at',
        'updated_at',
    ];
    protected $useTimestamps    = false;
}

