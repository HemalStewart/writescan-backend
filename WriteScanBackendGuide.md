WriteScan Backend Documentation
================================

Overview
--------
- Backend API for the WriteScan Android app (document scanning, storage, and AI-assisted review).
- Built on CodeIgniter 4 (PHP 8.1+) with Composer-managed dependencies.
- Persists data in a MySQL-compatible database using the default `MySQLi` driver.
- Uses PHP sessions for stateful authentication after OTP verification.

Core App Features
-----------------
- **Passwordless sign-in**: Mobile numbers authenticate through OTP via the Ideamart middleware, creating persistent sessions on success.
- **High-quality scanning pipeline**: The Android app captures, crops, and enhances physical documents; the backend ingests finished PDFs/images and records metadata plus optional OCR text.
- **Document library management**: Users can rename documents, organise them into colour-coded folders, and archive old files without permanent deletion.
- **AI-powered study bots**: Per-document bots let users ask questions about uploaded material; backend streams files to Google Gemini and records the chat history.
- **General notes workspace**: A freeform chat log (`general-chat`) stores quick notes or AI replies not tied to a specific document.
- **Dynamic configuration delivery**: `/api/config` keeps the client aligned with server-side limits (scan page caps, max upload size, Gemini model).
- **Session-aware syncing**: Incremental sync endpoints (`updated_after`) help the Android client stay up-to-date with minimal bandwidth.

Technology Stack & Services
---------------------------
- **Framework:** CodeIgniter 4 (`app/Controllers`, `app/Models`, `app/Services`).
- **Runtime:** PHP 8.1 or newer with `ext-intl`, `ext-mbstring`, and cURL enabled.
- **Database:** MySQL/MariaDB configured through `.env` (`database.default.*`).
- **Storage:** Uploaded files saved under `public/uploads/documents`.
- **AI Integration:** Google Gemini API via `App\Services\GeminiService`.
- **External OTP Provider:** `https://lakminiint.com/ideamart/bytehub/ReCon/middleWare/requestManager.php` (request/verify/unregister).
- **Tooling:** Composer scripts, PHPUnit (`phpunit.xml.dist`), Postman collection (`postman/writescan-backend.postman_collection.json`).

Project Layout
--------------
- `app/Controllers/Api`: REST controllers (`Auth`, `Documents`, `Folders`, `Bots`, `BotMessages`, `GeneralChat`, `Config`).
- `app/Models`: Table-specific models (documents, folders, bots, bot messages, general chat messages, users).
- `app/Services/GeminiService.php`: Google Gemini wrapper.
- `public/uploads/documents`: Default location for stored document files (created on demand).
- `writable/`: CI4 cache, logs, and session storage.

Local Setup
-----------
1. **Install Composer dependencies**
   ```bash
   composer install
   ```
2. **Configure environment**
   - Copy `.env` or ensure the shipped `.env` is populated.
   - Set `app.baseURL` to match your local server (e.g. `http://localhost:8888/` for MAMP).
   - Configure database credentials under the `database.default.*` keys.
3. **Database**
   - Create the database schema (see _Data Model Snapshot_ for required tables).
   - Run migrations/seeds if you maintain them externally, or create tables manually.
4. **Web server**
   - Serve the project through MAMP/Apache or `php spark serve` (ensure the document root points to `public/`).
5. **Sessions**
   - Ensure PHP session storage is writable (CI4 defaults to `writable/session`).

Environment Variables
---------------------
Set the following keys in `.env` to control runtime behaviour:

| Variable | Purpose | Default in code |
| --- | --- | --- |
| `GEMINI_API_KEY` | API key for Google Gemini; required for bot chat responses. | `''` (disabled) |
| `GEMINI_MODEL` | Gemini model identifier. | `models/gemini-1.5-flash` |
| `SCAN_PAGE_LIMIT` | Max pages the Android client may scan before sync. | `5` |
| `DOCUMENT_MAX_UPLOAD_MB` | Maximum allowed upload size (MB). | `25` |
| `database.default.*` | DSN/host/username/password/database settings. | see `app/Config/Database.php` |

Authentication Flow
-------------------
- Users log in with a mobile number.
- `POST /api/auth/request-otp` contacts the external OTP provider.
  - If the provider returns `Exist`, the user is auto-logged-in and a session is created.
  - Otherwise, the provider sends a code and the API returns `reference_no` for verification.
- `POST /api/auth/verify-otp` completes login; user records are created/updated in `user` table and session variables (`user_id`, `mobile`, `status`) are stored.
- Authenticated sessions are required for all document, folder, bot, bot message, and chat routes.
- `POST /api/auth/unregister` deregisters the mobile via the external service and soft-inactivates the local `user`.
- `GET /api/auth/me` exposes the current session user; `GET|POST /api/auth/logout` clears the session.

Scanner & Sync Workflow
-----------------------
- The Android client performs the actual scanning (camera capture, page detection, cleanup) and exports a PDF or image bundle; the backend treats uploads as opaque files.
- The scanner library lives in the Android Studio project (WriteScan mobile app). The PHP backend does not run any OCR or image enhancement itself; it simply accepts the processed output. Consult the mobile repo for the exact SDK (current build uses the Android CameraX/ML pipeline defined there).
- Before scanning, the client should call `GET /api/config` to read `scan_page_limit` (guideline for maximum pages per scan batch) and `max_upload_mb` (server upload cap).
- Once the scan is finalized, the client sends a multipart `POST /api/documents` request with:
  - `file`: the generated PDF (or image) binary.
  - `name`: optional user-friendly title; defaults to the original filename.
  - `type`: optional document type label (`pdf`, `image`, etc.).
  - `folder_id`: optional categorisation target.
  - `gemini_text`: optional extracted text (OCR summary) stored alongside the file for quick previews or AI priming.
- The backend saves the file under `public/uploads/documents`, records size/mime metadata, sets timestamps (`created_at`, `updated_at`, `synced_at`), and returns a canonical `file_url` for subsequent downloads.
- Clients track remote changes by polling `GET /api/documents?updated_after={timestamp}` and update document metadata via `PATCH /api/documents/{id}` (e.g. renaming or moving between folders).
- Archiving a document (`DELETE /api/documents/{id}`) removes it from active sync results but retains the binary on disk for potential recovery or auditing.
- AI chat bots are optional post-scan automations: after uploading a document, the client may call `POST /api/bots` with the new `document_id` to enable Gemini-powered question answering over that file.

Document Upload Pipeline (Server)
---------------------------------
- Endpoint: `POST /api/documents` handled by `app/Controllers/Api/Documents.php:43`.
- Authentication: requires a valid session (set by the OTP flow); unauthenticated requests receive HTTP 401.
- Request format: `multipart/form-data` with fields `file`, `name`, `type`, `folder_id`, and `gemini_text`.
- CodeIgniter wrapper (`UploadedFile`) validates the received file (`isValid()`), capturing size and MIME type before move.
- Storage:
  - Generates a 32-character hex `documentId` and randomised filename (`{documentId}_{randomName}`).
  - Ensures target directory `public/uploads/documents` exists, creating it with `0755` permissions when missing.
  - Moves the uploaded file from PHP temp storage to the destination, preventing double reads after move.
- Database insert (`DocumentModel`):
  - Persists metadata (`name`, `type`, `folder_id`, `file_path`, `file_size`, `mime_type`, `gemini_text`).
  - Sets `status` to `active` and timestamps (`created_at`, `updated_at`, `synced_at`) to the current server time.
- Response: HTTP 201 with the stored document transformed via `transformDocument()` which builds a `file_url` rooted at your configured `base_url`.
- Failure handling:
  - Invalid file → 422 validation error.
  - Move failure → 500 error (“Failed to store uploaded file.”).
  - Any exception logs to `writable/logs` (ensure directory is writable).

API Reference
-------------
The API routes are defined in `app/Config/Routes.php` under the `/api` prefix.

### Auth
- `POST /api/auth/request-otp`
  - Body: JSON or form data with `mobile`.
  - Responses:
    - `mode: login` when the user exists upstream; includes `user` payload.
    - `mode: otp` when an OTP was sent; includes `reference_no`, `mobile`, `remaining_attempts`.
- `POST /api/auth/verify-otp`
  - Body: `mobile`, `reference_no`, `otp`.
  - Success returns `user` details and `status: success`.
- `POST /api/auth/unregister`
  - Optional body `mobile`; defaults to current session mobile.
  - Success marks local user as `inactive`.
- `GET /api/auth/me`
  - Returns `status: guest` when no session, otherwise `status: success` with user info.
- `GET|POST /api/auth/logout`
  - Clears the session and returns `message: Logged out successfully`.

### Config
- `GET /api/config`
  - Returns runtime configuration values the mobile client needs (`gemini_key`, `gemini_model`, `scan_page_limit`, `max_upload_mb`).

### Documents
- `GET /api/documents`
  - Query params: optional `updated_after` (timestamp) to delta-sync.
  - Returns list of documents excluding `archived` status.
- `GET /api/documents/{id}`
  - Fetches a single document authorised to the session user.
- `POST /api/documents`
  - Multipart form fields: `file` (required upload), optional `name`, `type`, `folder_id`, `gemini_text`.
  - Stores file under `public/uploads/documents/{documentId}_{randomName}`.
  - Response: created document with resolved `file_url`.
- `PATCH /api/documents/{id}`
  - Body: JSON or form data with any of `name`, `folder_id`, `gemini_text`.
  - Updates metadata and `updated_at`.
- `DELETE /api/documents/{id}`
  - Soft-deletes by setting `status` to `archived`.

Document payload schema:
```json
{
  "id": "string",
  "name": "string",
  "type": "pdf|image|...",
  "folder_id": "string|null",
  "file_url": "https://...",
  "file_size": 123456,
  "mime_type": "application/pdf",
  "gemini_text": "optional text from OCR or Gemini",
  "status": "active|archived",
  "created_at": "YYYY-MM-DD HH:MM:SS",
  "updated_at": "YYYY-MM-DD HH:MM:SS",
  "synced_at": "YYYY-MM-DD HH:MM:SS"
}
```

### Folders
- `GET /api/folders`
  - Returns folders owned by the session user (most recent first).
- `POST /api/folders`
  - Body: `name` (required), optional `color` (defaults to `#4C6FFF`).
- `PATCH /api/folders/{id}`
  - Body: `name` and/or `color`.
- `DELETE /api/folders/{id}`
  - Removes the folder; associated documents retain `folder_id` until reassigned.

Folder payload schema:
```json
{
  "id": "string",
  "name": "string",
  "color": "#RRGGBB",
  "created_at": "YYYY-MM-DD HH:MM:SS",
  "updated_at": "YYYY-MM-DD HH:MM:SS"
}
```

### Bots
- `GET /api/bots`
  - Lists active bots for the user and includes linked document metadata.
- `GET /api/bots/{id}`
  - Returns a single bot.
- `POST /api/bots`
  - Body: `document_id` (required), optional `name`.
  - Validates that the document exists and its file is present on disk; assigns a random highlight colour.
- `DELETE /api/bots/{id}`
  - Marks the bot `archived` and purges associated `bot_messages`.

Bot payload schema:
```json
{
  "id": "string",
  "name": "string",
  "color": "#RRGGBB",
  "document_id": "string",
  "document": {
    "id": "string",
    "name": "string",
    "file_url": "https://...",
    "mime_type": "application/pdf"
  },
  "created_at": "YYYY-MM-DD HH:MM:SS",
  "updated_at": "YYYY-MM-DD HH:MM:SS"
}
```

### Bot Messages
- `GET /api/bots/{botId}/messages`
  - Lists chronological conversation history (`role` is `user` or `assistant`).
- `POST /api/bots/{botId}/messages`
  - Body: `message` (user question).
  - Loads the associated document, encodes its binary into the Gemini request, and sends a constrained prompt instructing the model to answer using the document content.
  - Stores both user and assistant messages in `bot_messages`.
  - Requires `GEMINI_API_KEY` to be set; otherwise returns a 500 with `AI service is not configured.`.

Bot message payload schema:
```json
{
  "id": 1,
  "bot_id": "string",
  "role": "user|assistant",
  "content": "conversation text",
  "created_at": "YYYY-MM-DD HH:MM:SS"
}
```

### General Chat
- `GET /api/general-chat`
  - Lists chat history stored for the current user.
- `POST /api/general-chat`
  - Body: `role` (`user|assistant`, default `user`), `type` (`text|image`, default `text`), `content` (required), optional `image_path`.
  - Saves simple conversational exchanges that are not tied to a specific bot/document.

General chat payload schema:
```json
{
  "id": 1,
  "role": "user|assistant",
  "type": "text|image",
  "content": "message contents",
  "image_path": "optional/relative/path.jpg",
  "created_at": "YYYY-MM-DD HH:MM:SS"
}
```

Data Model Snapshot
-------------------
- **user** (`id`, `mobile`, `reg_datetime`, `status`)
- **documents** (`id`, `user_id`, `name`, `type`, `folder_id`, `file_path`, `file_size`, `mime_type`, `gemini_text`, `status`, `created_at`, `updated_at`, `synced_at`)
- **folders** (`id`, `user_id`, `name`, `color`, `created_at`, `updated_at`)
- **bots** (`id`, `user_id`, `name`, `color`, `document_id`, `chat_source_id`, `status`, `created_at`, `updated_at`)
- **bot_messages** (`id`, `bot_id`, `user_id`, `role`, `content`, `created_at`)
- **general_chat_messages** (`id`, `user_id`, `role`, `type`, `content`, `image_path`, `created_at`)

File Storage
------------
- Documents are placed in `public/uploads/documents` and served via `base_url`.
- `Documents::delete` marks records as `archived` without removing files. Implement a scheduled clean-up if disk space is a concern.

Testing & Tooling
-----------------
- `composer test` runs PHPUnit test suites.
- CI4's built-in filters (see `app/Config/Filters.php`) include placeholders for HTTPS enforcement and performance metrics; adjust per deployment.
- Use the bundled Postman collection to exercise endpoints (`postman/writescan-backend.postman_collection.json`).

Operational Notes
-----------------
- Ensure HTTPS termination or configure trusted origins if exposing the API publicly (CORS filter is available but disabled by default).
- Maintain the Gemini API quota; failed AI calls return descriptive error messages logged server-side.
- Monitor `writable/logs` for OTP or Gemini errors (`log_message('info', ...)` statements in `Auth` controller).
