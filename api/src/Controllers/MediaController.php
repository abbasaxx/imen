<?php
// api/src/Controllers/MediaController.php
declare(strict_types=1);

class MediaController
{
    private const UPLOAD_DIR  = APP_ROOT . '/uploads/';
    private const MAX_BYTES   = 52_428_800; // 50 MB
    private const ALLOWED_MIME = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp',
        'video/mp4', 'video/webm', 'video/ogg',
        'audio/webm', 'audio/ogg', 'audio/mpeg', 'audio/wav',
        'application/octet-stream', // encrypted blobs arrive as binary
    ];

    /**
     * POST /media/upload
     * Accepts a multipart upload of an encrypted binary blob.
     * Stores it under a UUID filename; never inspects content.
     *
     * Form fields:
     *   file       — the encrypted blob (required)
     *   file_type  — 'image' | 'video' | 'voice' (required)
     *
     * Returns: { file_id: string, file_path: string }
     */
    public static function upload(array $params, int $userId): never
    {
        if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            Response::error('No file uploaded or upload error', 422);
        }

        $fileType = $_POST['file_type'] ?? '';
        if (!in_array($fileType, ['image', 'video', 'voice', 'file'], true)) {
            Response::error('file_type must be image, video, voice, or file', 422);
        }

        $tmpPath = $_FILES['file']['tmp_name'];
        $size    = $_FILES['file']['size'];

        if ($size > self::MAX_BYTES) {
            Response::error('File exceeds 50 MB limit', 413);
        }

        // UUID v4 filename — no extension so content type cannot be guessed
        $fileId   = self::uuid4();
        $destPath = self::UPLOAD_DIR . $fileId;

        if (!move_uploaded_file($tmpPath, $destPath)) {
            Response::error('Failed to save file', 500);
        }

        Response::json(['file_id' => $fileId, 'file_path' => $fileId], 201);
    }

    /**
     * GET /media/:file_id
     * Serves the encrypted blob back to the authenticated caller.
     *
     * Parallel-download strategy (two layers):
     *
     * 1. X-Sendfile (LiteSpeed / Apache mod_xsendfile) or X-Accel-Redirect (nginx)
     *    PHP authenticates and exits in ~1 ms; the web server streams the file,
     *    freeing the PHP worker immediately.
     *
     *    LiteSpeed / Apache — set in .env:
     *      SENDFILE_PATH=/home/user/public_html/api/uploads
     *    Also add to api/.htaccess:
     *      XSendFile On
     *      XSendFilePath /home/user/public_html/api/uploads
     *
     *    nginx — set in .env:
     *      NGINX_ACCEL_BASE=/uploads-internal
     *    Also add to nginx config:
     *      location /uploads-internal/ { internal; alias /path/to/api/uploads/; }
     *
     * 2. HTTP Range support (always active) — browsers request video segments
     *    in parallel and can seek without re-downloading the whole file.
     */
    public static function serve(array $params, int $userId): never
    {
        $fileId = preg_replace('/[^a-f0-9\-]/i', '', $params['file_id'] ?? '');
        if (!$fileId) Response::error('Invalid file id', 400);

        $path = self::UPLOAD_DIR . $fileId;
        if (!file_exists($path)) Response::error('Not found', 404);

        // Remove the JSON Content-Type set by index.php
        header_remove('Content-Type');
        header('Content-Type: application/octet-stream');
        header('Cache-Control: private, max-age=86400');
        header('Content-Disposition: attachment; filename="' . $fileId . '"');

        // ── X-Sendfile (LiteSpeed / Apache mod_xsendfile) ────────────────────
        $sendfileDir = (string) (getenv('SENDFILE_PATH') ?: '');
        if ($sendfileDir !== '') {
            header('X-Sendfile: ' . rtrim($sendfileDir, '/') . '/' . $fileId);
            exit;
        }

        // ── X-Accel-Redirect (nginx) ──────────────────────────────────────────
        $accelBase = (string) (getenv('NGINX_ACCEL_BASE') ?: '');
        if ($accelBase !== '') {
            header('X-Accel-Redirect: ' . rtrim($accelBase, '/') . '/' . $fileId);
            exit;
        }

        // ── PHP streaming with Range support ──────────────────────────────────
        $size = filesize($path);
        header('Accept-Ranges: bytes');

        $rangeHeader = trim($_SERVER['HTTP_RANGE'] ?? '');
        if ($rangeHeader !== '' && preg_match('/^bytes=(\d*)-(\d*)$/', $rangeHeader, $m)) {
            $start  = $m[1] !== '' ? (int) $m[1] : 0;
            $end    = $m[2] !== '' ? (int) $m[2] : $size - 1;
            $end    = min($end, $size - 1);
            $length = max(0, $end - $start + 1);

            http_response_code(206);
            header("Content-Range: bytes {$start}-{$end}/{$size}");
            header("Content-Length: {$length}");

            $fp = fopen($path, 'rb');
            fseek($fp, $start);
            $remaining = $length;
            while ($remaining > 0 && !feof($fp)) {
                $chunk = fread($fp, min(65536, $remaining));
                echo $chunk;
                $remaining -= strlen($chunk);
            }
            fclose($fp);
        } else {
            header("Content-Length: {$size}");
            readfile($path);
        }

        exit;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private static function uuid4(): string
    {
        $data    = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
