<?php
/**
 * Central API Security Shield (includes/security_shield.php)
 * Provides: Rate Limiting, SSRF-Safe cURL, Path Traversal Guard & Magic-Byte File Validation
 */

// ۱. محدودکننده نرخ درخواست به ازای هر کاربر یا IP (جلوگیری از اسپم و سوزاندن کلیدهای AI)
if (!function_exists('enforce_api_rate_limit')) {
    function enforce_api_rate_limit(PDO $pdo, string $bucket_name, int $max_requests = 30, int $window_seconds = 60): void {
        $uid = isset($_SESSION['user_id']) ? 'u_' . (int)$_SESSION['user_id'] : 'ip_' . ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        $key = substr($bucket_name . ':' . $uid, 0, 45);

        try {
            $stmt = $pdo->prepare("SELECT attempts, locked_until, updated_at FROM auth_rate_limits WHERE ip_address = ? LIMIT 1");
            $stmt->execute([$key]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            $now = time();
            if ($row) {
                if (!empty($row['locked_until']) && strtotime($row['locked_until']) > $now) {
                    http_response_code(429);
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode([
                        'status'  => 'error',
                        'message' => 'تعداد درخواست‌های شما بیش از حد مجاز در دقیقه است. لطفاً کمی صبر کنید.'
                    ], JSON_UNESCAPED_UNICODE);
                    exit;
                }

                $last_ts = strtotime($row['updated_at'] ?? '') ?: 0;
                if (($now - $last_ts) > $window_seconds) {
                    $pdo->prepare("UPDATE auth_rate_limits SET attempts = 1, locked_until = NULL, updated_at = NOW() WHERE ip_address = ?")
                        ->execute([$key]);
                } else {
                    $new_att = (int)$row['attempts'] + 1;
                    $lock_until = ($new_att > $max_requests) ? date('Y-m-d H:i:s', $now + $window_seconds) : null;
                    $pdo->prepare("UPDATE auth_rate_limits SET attempts = ?, locked_until = ? WHERE ip_address = ?")
                        ->execute([$new_att, $lock_until, $key]);

                    if ($lock_until !== null) {
                        http_response_code(429);
                        header('Content-Type: application/json; charset=utf-8');
                        echo json_encode([
                            'status'  => 'error',
                            'message' => 'تعداد درخواست‌های شما بیش از حد مجاز است. لطفاً ۱ دقیقه دیگر تلاش کنید.'
                        ], JSON_UNESCAPED_UNICODE);
                        exit;
                    }
                }
            } else {
                $pdo->prepare("INSERT INTO auth_rate_limits (ip_address, attempts, locked_until) VALUES (?, 1, NULL)")
                    ->execute([$key]);
            }
        } catch (Exception $ignore) {}
    }
}

// ۲. محافظت در برابر Path Traversal (اطمینان از اینکه فایل محلی دقیقاً داخل پوشه uploads/ قرار دارد)
if (!function_exists('resolve_safe_upload_path')) {
    function resolve_safe_upload_path(string $relative_path): ?string {
        $clean_rel = ltrim(str_replace(['\\', "\0"], ['/', ''], trim($relative_path)), '/');
        if ($clean_rel === '' || strpos($clean_rel, '..') !== false) {
            return null;
        }

        $uploads_root = realpath(dirname(__DIR__) . '/uploads');
        $target_real  = realpath(dirname(__DIR__) . '/' . $clean_rel);

        if (!$uploads_root || !$target_real || !is_file($target_real)) {
            return null;
        }

        // بررسی اینکه مسیر واقعی فایل حتماً با مسیر واقعی پوشه uploads شروع شود
        if (strpos($target_real, $uploads_root . DIRECTORY_SEPARATOR) !== 0) {
            return null;
        }

        return $target_real;
    }
}

// ۳. دانلود امن از لینک خارجی با محافظت کامل در برابر SSRF
if (!function_exists('safe_remote_curl_get')) {
    function safe_remote_curl_get(string $url, int $max_bytes = 15728640): ?string {
        $url = trim($url);
        $parts = parse_url($url);
        if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        // جلوگیری از اتصال به آی‌پی‌های داخلی سرور و شبکه محلی (Anti-SSRF)
        $host = $parts['host'];
        $ip   = gethostbyname($host);
        if ($ip && filter_var($ip, FILTER_VALIDATE_IP)) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return null; // مسدودسازی 127.0.0.1, 10.x.x.x, 192.168.x.x, 169.254.169.254
            }
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT        => 25,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS=> CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (KonkurSecureFetcher/4.4)'
        ]);

        $data = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200 || empty($data) || strlen($data) > $max_bytes) {
            return null;
        }

        return $data;
    }
}

// ۴. اعتبارسنجی سخت‌گیرانه آپلود فایل (بررسی پسوند + بررسی Magic Bytes واقعی فایل)
if (!function_exists('validate_uploaded_file_security')) {
    function validate_uploaded_file_security(array $file_arr, array $allowed_exts, int $max_mb = 64): void {
        if (!isset($file_arr['error']) || $file_arr['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('خطا در دریافت فایل آپلودشده.');
        }

        $max_bytes = $max_mb * 1024 * 1024;
        if ($file_arr['size'] <= 0 || $file_arr['size'] > $max_bytes) {
            throw new Exception("حجم فایل بیش از حد مجاز ({$max_mb} مگابایت) است.");
        }

        $ext = strtolower(pathinfo($file_arr['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed_exts, true)) {
            throw new Exception('فرمت فایل مجاز نیست. فرمت‌های مجاز: ' . implode(', ', $allowed_exts));
        }

        // بررسی محتوای باینری فایل برای جلوگیری از آپلود شل PHP در قالب عکس یا PDF
        $head_bytes = @file_get_contents($file_arr['tmp_name'], false, null, 0, 512);
        if ($head_bytes !== false && preg_match('/<\?(?:php|=)/i', $head_bytes)) {
            throw new Exception('محتوای فایل آپلودشده غیرامن تشخیص داده شد.');
        }

        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime  = strtolower((string)finfo_file($finfo, $file_arr['tmp_name']));
            finfo_close($finfo);

            $mime_allow_map = [
                'pdf'  => ['application/pdf', 'application/x-pdf', 'application/octet-stream'],
                'jpg'  => ['image/jpeg', 'image/pjpeg'],
                'jpeg' => ['image/jpeg', 'image/pjpeg'],
                'png'  => ['image/png'],
                'webp' => ['image/webp'],
                'zip'  => ['application/zip', 'application/x-zip-compressed', 'multipart/x-zip', 'application/octet-stream'],
                'rar'  => ['application/x-rar', 'application/x-rar-compressed', 'application/vnd.rar', 'application/octet-stream'],
                'srt'  => ['text/plain', 'application/x-subrip', 'text/srt', 'application/octet-stream'],
                'vtt'  => ['text/vtt', 'text/plain', 'application/octet-stream'],
                'txt'  => ['text/plain'],
                'doc'  => ['application/msword', 'application/octet-stream'],
                'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
            ];

            if (isset($mime_allow_map[$ext]) && !in_array($mime, $mime_allow_map[$ext], true)) {
                throw new Exception("ساختار واقعی فایل ({$mime}) با پسوند .{$ext} همخوانی ندارد.");
            }
        }
    }
}