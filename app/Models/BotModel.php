<?php

namespace App\Models;

use CodeIgniter\Model;

class BotModel extends Model
{
    protected $table            = 'bots';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $allowedFields    = [
        'id',
        'user_id',
        'name',
        'color',
        'document_id',
        'chat_source_id',
        'status',
        'created_at',
        'updated_at',
    ];
    protected $useTimestamps    = false;
}
