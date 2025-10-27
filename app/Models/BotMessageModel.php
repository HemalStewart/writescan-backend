<?php

namespace App\Models;

use CodeIgniter\Model;

class BotMessageModel extends Model
{
    protected $table            = 'bot_messages';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $allowedFields    = [
        'bot_id',
        'user_id',
        'role',
        'content',
        'created_at',
    ];
    protected $useTimestamps    = false;
}
