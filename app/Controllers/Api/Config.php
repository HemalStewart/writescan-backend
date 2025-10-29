<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use CodeIgniter\API\ResponseTrait;

class Config extends BaseController
{
    use ResponseTrait;

    public function show()
    {
        $config = [
            'gemini_key'      => getenv('GEMINI_API_KEY') ?: '',
            'gemini_model'    => getenv('GEMINI_MODEL') ?: 'models/gemini-1.5-flash',
            'scan_page_limit' => (int) (getenv('SCAN_PAGE_LIMIT') ?: 5),
            'max_upload_mb'   => (int) (getenv('DOCUMENT_MAX_UPLOAD_MB') ?: 25),
        ];

        return $this->respond($config);
    }
}
