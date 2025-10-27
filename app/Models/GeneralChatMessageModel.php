<?php

namespace App\Models;

use CodeIgniter\Model;

class GeneralChatMessageModel extends Model
{
    protected $table            = 'general_chat_messages';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $allowedFields    = [
        'user_id',
        'role',
        'type',
        'content',
        'image_path',
        'created_at',
    ];
    protected $useTimestamps    = false;
}
