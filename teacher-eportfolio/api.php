<?php
/* =====================================================================
   واجهة برمجة ملف الإنجاز (API)
   - القراءة (load) متاحة للجميع
   - كل عمليات الكتابة والرفع والحذف للمسؤول فقط (جلسة + رمز CSRF)
   - كل قسم في الموقع محفوظ في جدول مستقل (انظر database.sql)
   ===================================================================== */
declare(strict_types=1);

require __DIR__ . '/config.php';

// أي تحذير أو خطأ من PHP لا يُطبع داخل الرد؛ الرد دائماً JSON سليم
ini_set('display_errors', '0');
ob_start();
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        while (ob_get_level()) ob_end_clean();
        if (!headers_sent()) { http_response_code(500); header('Content-Type: application/json; charset=utf-8'); }
        error_log('eportfolio fatal: ' . $e['message']);
        echo json_encode(['ok' => false, 'error' => 'خطأ في الخادم — جرّب مرة أخرى'], JSON_UNESCAPED_UNICODE);
    }
});

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
      || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
session_name('eportfolio_sid');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => $https,
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();

const SCHEMA_VERSION     = 2;
const LOGIN_MAX_ATTEMPTS = 5;
const LOGIN_LOCK_SECONDS = 900;
const STORED_FILE_RE     = '/^[a-f0-9]{32}\.(jpg|jpeg|png|webp|gif|pdf|doc|docx)$/';

// الامتدادات المسموح برفعها وأنواع MIME المقبولة لكل منها
const ALLOWED_UPLOADS = [
    'jpg'  => ['image/jpeg'],
    'jpeg' => ['image/jpeg'],
    'png'  => ['image/png'],
    'webp' => ['image/webp'],
    'gif'  => ['image/gif'],
    'pdf'  => ['application/pdf'],
    'doc'  => ['application/msword', 'application/CDFV2', 'application/x-ole-storage', 'application/vnd.ms-office', 'application/octet-stream'],
    'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
];

// إعدادات الموقع المسموحة ونوع كل منها (s نص، i رقم، b نعم/لا) — تُحفظ في ep_settings
const SETTINGS_TYPES = [
    'gender' => 's', 'siteTitle' => 's', 'badge' => 's', 'year' => 's', 'quote' => 's',
    'theme' => 's', 'customColor' => 's', 'mode' => 's', 'font' => 's', 'fontScale' => 'i', 'radius' => 's',
    'animations' => 'b', 'pattern' => 'b', 'preloader' => 'b', 'confetti' => 'b', 'scrollBar' => 'b', 'spotlight' => 'b',
    'showStats' => 'b', 'showQuote' => 'b', 'showAvatar' => 'b',
    'sections.basicsTitle' => 's', 'sections.basicsDesc' => 's', 'sections.foldersTitle' => 's', 'sections.foldersDesc' => 's',
    'logo' => 's',
];

// حقول السيرة الذاتية ← أعمدة جدول ep_profile
const PROFILE_MAP = [
    'name' => 'full_name', 'nationalId' => 'national_id', 'birth' => 'birth_date', 'nationality' => 'nationality',
    'phone' => 'phone', 'email' => 'email', 'jobTitle' => 'job_title', 'rank' => 'job_rank', 'employeeId' => 'employee_id',
    'school' => 'school_name', 'department' => 'education_department', 'hireDate' => 'hire_date',
    'experience' => 'experience_years', 'subjects' => 'subjects', 'load' => 'teaching_load', 'degree' => 'degree',
    'major' => 'major', 'university' => 'university', 'gradYear' => 'graduation_year', 'license' => 'professional_license',
];
const PROFILE_DATES = ['birth_date', 'hire_date'];
const PROFILE_INTS  = ['experience_years', 'teaching_load'];

const CARD_KEYS = ['cv', 'ksa-goals', 'science-goals', 'ethics', 'vision', 'growth', 'certs'];
const ITEM_TYPES = ['link', 'image', 'word', 'pdf'];
// ترتيب الحفظ: الإعدادات أولاً (لأنها تنشئ المجلدات قبل حفظ محتواها)
const KEY_ORDER = ['settings', 'cv', 'growth', 'certs', 'folders', 'avatar', 'logo'];

/* ---------- أدوات مساعدة ---------- */
function out(array $data, int $code = 200): void
{
    while (ob_get_level()) ob_end_clean();   // تجاهل أي تحذير طُبع قبل الرد
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function fail(string $message, int $code = 400): void
{
    out(['ok' => false, 'error' => $message], $code);
}

function clip($value, int $max): string
{
    $s = is_scalar($value) ? trim((string)$value) : '';
    if (function_exists('mb_substr')) return mb_substr($s, 0, $max, 'UTF-8');
    if (function_exists('iconv_substr')) return (string)iconv_substr($s, 0, $max, 'UTF-8');
    return substr($s, 0, $max * 2);
}

function date_or_null($value): ?string
{
    $s = is_string($value) ? trim($value) : '';
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m)) return null;
    return checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? $s : null;
}

function int_or_null($value): ?int
{
    return is_numeric($value) ? (int)$value : null;
}

function ms_to_datetime($ms): string
{
    $sec = is_numeric($ms) ? (int)floor((float)$ms / 1000) : time();
    return date('Y-m-d H:i:s', $sec > 0 ? $sec : time());
}

function datetime_to_ms(?string $dt): int
{
    $t = $dt ? strtotime($dt) : false;
    return ($t ?: time()) * 1000;
}

// يقبل فقط ملفات مرفوعة إلى uploads (وروابط http/https إذا سُمح بها)
function safe_url($value, bool $allowExternal = false): ?string
{
    if (!is_string($value) || strlen($value) > 2000) return null;
    if (strpos($value, 'uploads/') === 0 && preg_match(STORED_FILE_RE, substr($value, 8)) === 1) return $value;
    if ($allowExternal && preg_match('#^https?://[^\s<>"\']+$#i', $value) === 1) return $value;
    return null;
}

function valid_id($value): bool
{
    return is_string($value) && preg_match('/^[a-z0-9_-]{1,40}$/i', $value) === 1;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;
    try {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
    } catch (PDOException $e) {
        fail('تعذر الاتصال بقاعدة البيانات — تحقق من الإعدادات في ملف config.php', 500);
    }
    ensure_schema($pdo);
    return $pdo;
}

// تشغيل ملف database.sql (إنشاء الجداول والبيانات الافتراضية)
function run_sql_file(PDO $pdo): void
{
    $sql = (string)file_get_contents(__DIR__ . '/database.sql');
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
    foreach (preg_split('/;\s*(\r?\n|$)/', $sql) as $statement) {
        if (trim($statement) !== '') $pdo->exec($statement);
    }
}

function table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn() > 0;
}

// إنشاء الجداول تلقائياً عند أول تشغيل، ونقل بيانات النسخة السابقة (جدول ep_data) إن وُجدت
function ensure_schema(PDO $pdo): void
{
    if (table_exists($pdo, 'ep_folders')) {
        upgrade_schema($pdo);
        return;
    }
    run_sql_file($pdo);
    if (table_exists($pdo, 'ep_data')) {
        $old = [];
        foreach ($pdo->query('SELECT k, v FROM ep_data') as $row) $old[$row['k']] = json_decode($row['v'], true);
        foreach (ordered_keys(array_keys($old)) as $key) {
            try { save_key($key, $old[$key]); } catch (Throwable $e) { /* تجاهل القيم غير الصالحة */ }
        }
        $pdo->exec('RENAME TABLE ep_data TO ep_data_backup');
    }
}

// ترقية قاعدة بيانات أُنشئت بإصدار أقدم
function upgrade_schema(PDO $pdo): void
{
    $version = (int)($pdo->query("SELECT setting_value FROM ep_settings WHERE setting_key = '_schema'")->fetchColumn() ?: 1);
    if ($version >= SCHEMA_VERSION) return;
    // الإصدار 2: وصف للشواهد والشهادات
    add_column_if_missing($pdo, 'ep_folder_items', 'description', "TEXT NULL COMMENT 'وصف الشاهد' AFTER title");
    add_column_if_missing($pdo, 'ep_certificates', 'description', "TEXT NULL COMMENT 'وصف الشهادة' AFTER title");
    $pdo->prepare('INSERT INTO ep_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')
        ->execute(['_schema', (string)SCHEMA_VERSION]);
}

function add_column_if_missing(PDO $pdo, string $table, string $column, string $definition): void
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
    $stmt->execute([$table, $column]);
    if ((int)$stmt->fetchColumn() === 0) $pdo->exec("ALTER TABLE $table ADD COLUMN $column $definition");
}

function ordered_keys(array $keys): array
{
    usort($keys, function ($a, $b) {
        $ia = array_search($a, KEY_ORDER, true); $ib = array_search($b, KEY_ORDER, true);
        return ($ia === false ? 99 : $ia) <=> ($ib === false ? 99 : $ib);
    });
    return $keys;
}

function is_admin(): bool
{
    return !empty($_SESSION['admin']);
}

// نوع الملف الفعلي من محتواه (مع بديل إن كانت إضافة fileinfo معطلة في الاستضافة)
function detect_mime(string $path): string
{
    if (class_exists('finfo')) return (new finfo(FILEINFO_MIME_TYPE))->file($path) ?: '';
    if (function_exists('mime_content_type')) return mime_content_type($path) ?: '';
    $img = @getimagesize($path);
    if ($img && !empty($img['mime'])) return $img['mime'];
    $head = (string)@file_get_contents($path, false, null, 0, 8);
    if (strncmp($head, '%PDF', 4) === 0) return 'application/pdf';
    if (strncmp($head, "PK\x03\x04", 4) === 0) return 'application/zip';
    if (strncmp($head, "\xD0\xCF\x11\xE0", 4) === 0) return 'application/x-ole-storage';
    return 'application/octet-stream';
}

// الحد الفعلي للرفع: الأصغر بين إعداد الموقع وحدود PHP في الاستضافة
function ini_bytes(string $key): int
{
    $v = trim((string)ini_get($key));
    if ($v === '' || $v === '-1' || $v === '0') return PHP_INT_MAX;
    $n = (int)$v; $u = strtolower(substr($v, -1));
    return $u === 'g' ? $n * 1073741824 : ($u === 'm' ? $n * 1048576 : ($u === 'k' ? $n * 1024 : $n));
}
function upload_limit_mb(): int
{
    $bytes = min(MAX_UPLOAD_MB * 1048576, ini_bytes('upload_max_filesize'), ini_bytes('post_max_size'));
    return max(1, (int)floor($bytes / 1048576));
}

/* =====================================================================
   الربط مع منصة المعلمة
   يجلب الخادم شواهد المنصة بنفسه (فيبقى مفتاح الربط سراً لا يصل للمتصفح)،
   ويحفظ نسخة مؤقتة لعشر دقائق حتى لا يُثقل على الاستضافة.
   ===================================================================== */
const PLATFORM_CACHE_SECONDS = 600;
const PLATFORM_STANDARDS = ['classroom', 'planning', 'results', 'parents', 'tech', 'environment', 'assessment', 'analysis'];

function get_setting(string $key): ?string
{
    $stmt = db()->prepare('SELECT setting_value FROM ep_settings WHERE setting_key = ?');
    $stmt->execute([$key]);
    $v = $stmt->fetchColumn();
    return $v === false ? null : (string)$v;
}

function del_settings(array $keys): void
{
    db()->prepare('DELETE FROM ep_settings WHERE setting_key IN (' . implode(',', array_fill(0, count($keys), '?')) . ')')->execute($keys);
}

// طلب GET بسيط (curl إن توفر، وإلا file_get_contents)
function http_get(string $url, array $headers): array
{
    $headers[] = 'Accept: application/json';
    $agent = 'Mozilla/5.0 (eportfolio-sync)';
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => 6, CURLOPT_TIMEOUT => 15, CURLOPT_HTTPHEADER => $headers, CURLOPT_USERAGENT => $agent,
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($body === false) throw new RuntimeException('تعذر الاتصال بمنصة المعلمة' . ($err ? " ($err)" : ''));
        return [$code, (string)$body];
    }
    if (!ini_get('allow_url_fopen')) throw new RuntimeException('الاستضافة لا تسمح بالاتصال بمواقع أخرى (curl غير متاح)');
    $ctx = stream_context_create(['http' => ['method' => 'GET', 'header' => implode("\r\n", $headers), 'user_agent' => $agent, 'timeout' => 15, 'ignore_errors' => true]]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) throw new RuntimeException('تعذر الاتصال بمنصة المعلمة');
    $code = 0;
    foreach ($http_response_header ?? [] as $h) if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) $code = (int)$m[1];
    return [$code, (string)$body];
}

// قراءة تغذية ملف الإنجاز من المنصة (مع اجتياز فحص الحماية في InfinityFree إن ظهر)
function platform_fetch(string $base, string $key): array
{
    $url = $base . '/api.php?action=portfolio';
    $headers = ['X-Portfolio-Key: ' . $key];
    [$code, $body] = http_get($url, $headers);
    $json = json_decode($body, true);

    // صفحة الحماية: toNumbers("المفتاح") toNumbers("المتجه") toNumbers("النص المشفر") ← كوكي __test
    if (!is_array($json) && preg_match_all('/toNumbers\("([0-9a-f]+)"\)/i', $body, $m) && count($m[1]) >= 3) {
        if (!function_exists('openssl_decrypt')) throw new RuntimeException('فحص الحماية في الاستضافة يمنع الاتصال (openssl غير متاح)');
        $plain = openssl_decrypt(hex2bin($m[1][2]), 'AES-128-CBC', hex2bin($m[1][0]), OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, hex2bin($m[1][1]));
        if ($plain === false) throw new RuntimeException('تعذر اجتياز فحص الحماية في استضافة المنصة');
        $headers[] = 'Cookie: __test=' . bin2hex($plain);
        [$code, $body] = http_get($url . '&i=1', $headers);
        $json = json_decode($body, true);
    }

    if (!is_array($json)) throw new RuntimeException("رد غير مفهوم من منصة المعلمة (رمز $code) — تأكد من الرابط");
    $err = $json['error'] ?? null;
    if ($err === 'portfolio_key') throw new RuntimeException('مفتاح الربط غير صحيح، أو PORTFOLIO_KEY غير مضبوط في config.php بالمنصة');
    if ($err) throw new RuntimeException('خطأ من منصة المعلمة: ' . (is_string($err) ? $err : json_encode($err)));
    if (!is_array($json['data'] ?? null)) throw new RuntimeException('منصة المعلمة لم ترسل بيانات');
    return $json['data'];
}

function http_url($v): ?string
{
    return (is_string($v) && strlen($v) < 2000 && preg_match('#^https?://[^\s<>"\']+$#i', $v)) ? $v : null;
}

// إبقاء الحقول المعروفة فقط مما يأتي من المنصة
function platform_shape(array $d): array
{
    $str = fn($v, int $n = 255) => is_scalar($v) ? clip($v, $n) : '';
    $out = ['teacher_name' => $str($d['teacher_name'] ?? ''), 'generated_at' => $str($d['generated_at'] ?? '', 40), 'summary' => [], 'standards' => []];
    foreach ((array)($d['summary'] ?? []) as $k => $v) if (is_string($k) && (is_int($v) || is_float($v) || $v === null)) $out['summary'][$k] = $v;
    foreach ((array)($d['standards'] ?? []) as $s) {
        if (!is_array($s) || !in_array($s['key'] ?? '', PLATFORM_STANDARDS, true)) continue;
        $std = ['key' => $s['key'], 'title' => $str($s['title_ar'] ?? ''), 'items' => [], 'stats' => [], 'cards' => []];
        foreach (array_slice((array)($s['items'] ?? []), 0, 40) as $it) {
            if (!is_array($it)) continue;
            $std['items'][] = ['title' => $str($it['title_ar'] ?? ''), 'body' => $str($it['body_ar'] ?? '', 400), 'type' => $str($it['type'] ?? '', 20),
                               'file' => http_url($it['file_url'] ?? null), 'link' => http_url($it['external_url'] ?? null),
                               'section' => $str($it['section_ar'] ?? ''), 'date' => $str($it['created_at'] ?? '', 40)];
        }
        foreach (array_slice((array)($s['stats'] ?? []), 0, 12) as $st) {
            if (is_array($st)) $std['stats'][] = ['label' => $str($st['label_ar'] ?? ''), 'value' => $str($st['value'] ?? '', 40)];
        }
        foreach (array_slice((array)($s['cards'] ?? []), 0, 40) as $c) {
            if (!is_array($c)) continue;
            $std['cards'][] = ['kind' => $str($c['kind'] ?? '', 20), 'title' => $str($c['title_ar'] ?? ''), 'file' => http_url($c['file_url'] ?? null),
                               'date' => $str($c['date'] ?? '', 40), 'count' => is_numeric($c['count'] ?? null) ? (int)$c['count'] : null,
                               'student' => $str($c['student_name'] ?? '')];
        }
        $out['standards'][] = $std;
    }
    return $out;
}

// جلب جديد من المنصة وحفظه في النسخة المؤقتة
function platform_refresh(): array
{
    $base = get_setting('_platform_url');
    $key  = get_setting('_platform_key');
    if (!$base || !$key) throw new RuntimeException('الربط غير مضبوط');
    $data = platform_shape(platform_fetch($base, $key));
    put_setting('_platform_cache', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    put_setting('_platform_cache_at', (string)time());
    return $data;
}

function platform_counts(array $data): array
{
    $items = 0; $cards = 0;
    foreach ($data['standards'] as $s) { $items += count($s['items']); $cards += count($s['cards']); }
    return ['items' => $items, 'cards' => $cards];
}

function ensure_upload_dir(): void
{
    if (!is_dir(UPLOAD_DIR) && !@mkdir(UPLOAD_DIR, 0755, true)) fail('تعذر إنشاء مجلد uploads على الخادم', 500);
    if (!is_writable(UPLOAD_DIR)) fail('مجلد uploads غير قابل للكتابة — اجعل صلاحياته 755 من مدير الملفات', 500);
}

function body(): array
{
    $json = json_decode(file_get_contents('php://input') ?: '', true);
    return is_array($json) ? $json : [];
}

function require_admin(): void
{
    if (!is_admin()) fail('غير مصرح — سجّل الدخول كمسؤول', 401);
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['csrf'] ?? '', $token)) {
        fail('انتهت صلاحية الجلسة — أعد تحميل الصفحة', 403);
    }
}

function delete_stored_file($url): void
{
    if (!is_string($url) || strpos($url, 'uploads/') !== 0) return;
    $name = substr($url, strlen('uploads/'));
    if (preg_match(STORED_FILE_RE, $name) !== 1) return;
    $path = UPLOAD_DIR . '/' . $name;
    if (is_file($path)) @unlink($path);
}

function client_ip(): string
{
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
}

// بيانات دخول المسؤول: المحفوظة من داخل الموقع (إن وُجدت) وإلا من config.php
function admin_credentials(): array
{
    $rows = db()->query("SELECT setting_key, setting_value FROM ep_settings WHERE setting_key IN ('_admin_user', '_admin_hash')")
                ->fetchAll(PDO::FETCH_KEY_PAIR);
    return ['user' => $rows['_admin_user'] ?? ADMIN_USERNAME, 'hash' => $rows['_admin_hash'] ?? null];
}

function password_matches(string $given, ?string $dbHash): bool
{
    if ($dbHash) return password_verify($given, $dbHash);
    if (strncmp(ADMIN_PASSWORD, '$2y$', 4) === 0) return password_verify($given, ADMIN_PASSWORD);
    return hash_equals(ADMIN_PASSWORD, $given);
}

/* =====================================================================
   قراءة جميع البيانات من الجداول وتجميعها بالشكل الذي تستخدمه الصفحة
   ===================================================================== */
function load_all(): array
{
    $pdo = db();
    $data = [];

    // الإعدادات
    $settings = ['sections' => [], 'cards' => []];
    foreach ($pdo->query('SELECT setting_key, setting_value FROM ep_settings') as $r) {
        $k = $r['setting_key'];
        if (!isset(SETTINGS_TYPES[$k])) continue;
        $v = $r['setting_value'];
        $type = SETTINGS_TYPES[$k];
        $v = $type === 'b' ? $v === '1' : ($type === 'i' ? (int)$v : $v);
        if ($k === 'logo') { if ($v !== '') $data['logo'] = $v; continue; }
        if (strpos($k, 'sections.') === 0) $settings['sections'][substr($k, 9)] = $v;
        else $settings[$k] = $v;
    }

    // البطاقات وصورها
    foreach ($pdo->query('SELECT card_key, title, description, image_url, is_hidden FROM ep_cards ORDER BY sort_order') as $r) {
        $settings['cards'][$r['card_key']] = ['title' => $r['title'], 'desc' => $r['description'], 'hidden' => (bool)$r['is_hidden']];
        if ($r['image_url']) $data['img:' . $r['card_key']] = $r['image_url'];
    }

    // المجلدات وعناصرها وشواهدها
    $points = [];
    foreach ($pdo->query('SELECT folder_id, point_text, is_done FROM ep_folder_points ORDER BY sort_order, id') as $r) {
        $points[$r['folder_id']][] = $r;
    }
    $items = [];
    foreach ($pdo->query('SELECT id, folder_id, item_type, title, description, url, file_size, created_at FROM ep_folder_items ORDER BY sort_order, id') as $r) {
        $item = ['id' => 'i' . $r['id'], 'type' => $r['item_type'], 'name' => $r['title'], 'desc' => (string)($r['description'] ?? ''),
                 'size' => $r['file_size'] === null ? null : (int)$r['file_size'], 'date' => datetime_to_ms($r['created_at'])];
        if ($r['item_type'] === 'image') $item['src'] = $r['url']; else $item['url'] = $r['url'];
        $items[$r['folder_id']][] = $item;
    }
    $settings['folders'] = [];
    $state = [];
    foreach ($pdo->query('SELECT id, title, icon, notes, is_hidden FROM ep_folders ORDER BY sort_order, id') as $f) {
        $fp = $points[$f['id']] ?? [];
        $settings['folders'][] = ['id' => $f['id'], 'title' => $f['title'], 'icon' => $f['icon'], 'hidden' => (bool)$f['is_hidden'],
                                  'points' => array_map(fn($p) => $p['point_text'], $fp)];
        $state[$f['id']] = [
            'notes'  => (string)($f['notes'] ?? ''),
            'checks' => array_values(array_map(fn($p) => $p['point_text'], array_filter($fp, fn($p) => (int)$p['is_done'] === 1))),
            'items'  => $items[$f['id']] ?? [],
        ];
    }
    // تحويل القوائم المسمّاة إلى كائنات JSON حتى لو كانت فارغة ({} وليس [])
    $settings['sections'] = (object)$settings['sections'];
    $settings['cards'] = (object)$settings['cards'];
    $data['settings'] = $settings;
    $data['folders'] = (object)$state;

    // السيرة الذاتية والصورة الشخصية
    $profile = $pdo->query('SELECT * FROM ep_profile WHERE id = 1')->fetch() ?: [];
    $cv = [];
    foreach (PROFILE_MAP as $key => $col) {
        $val = $profile[$col] ?? null;
        if ($val !== null && $val !== '') $cv[$key] = (string)$val;
    }
    $data['cv'] = (object)$cv;
    if (!empty($profile['avatar_url'])) $data['avatar'] = $profile['avatar_url'];

    // النمو المهني
    $growth = [];
    foreach ($pdo->query('SELECT * FROM ep_courses ORDER BY sort_order, id') as $r) {
        $hours = $r['hours'] === null ? '' : rtrim(rtrim((string)$r['hours'], '0'), '.');
        $growth[] = ['id' => 'c' . $r['id'], 'title' => $r['title'], 'provider' => $r['provider'], 'date' => $r['course_date'] ?? '',
                     'hours' => $hours, 'mode' => $r['attendance_mode'], 'cert' => (int)$r['has_certificate'] === 1 ? 'نعم' : 'لا'];
    }
    if ($growth) $data['growth'] = $growth;

    // الشهادات
    $certs = [];
    foreach ($pdo->query('SELECT * FROM ep_certificates ORDER BY sort_order, id') as $r) {
        $certs[] = ['id' => 't' . $r['id'], 'cat' => $r['category'], 'title' => $r['title'], 'desc' => (string)($r['description'] ?? ''),
                    'src' => $r['image_url'], 'date' => datetime_to_ms($r['created_at'])];
    }
    $data['certs'] = $certs;

    return $data;
}

/* =====================================================================
   حفظ كل جزء من الصفحة في جدوله
   ===================================================================== */
function put_setting(string $key, string $value): void
{
    db()->prepare('INSERT INTO ep_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')
        ->execute([$key, $value]);
}

function save_settings(array $s): void
{
    foreach (SETTINGS_TYPES as $key => $type) {
        if ($key === 'logo') continue;
        if (strpos($key, 'sections.') === 0) {
            $sub = substr($key, 9);
            if (!isset($s['sections']) || !is_array($s['sections']) || !array_key_exists($sub, $s['sections'])) continue;
            $val = $s['sections'][$sub];
        } else {
            if (!array_key_exists($key, $s)) continue;
            $val = $s[$key];
        }
        $str = $type === 'b' ? ($val ? '1' : '0') : ($type === 'i' ? (string)(int)$val : clip($val, 2000));
        put_setting($key, $str);
    }

    if (isset($s['cards']) && is_array($s['cards'])) {
        foreach (CARD_KEYS as $card) {
            $c = $s['cards'][$card] ?? null;
            if (!is_array($c)) continue;
            $sets = []; $args = [];
            if (array_key_exists('title', $c))  { $sets[] = 'title = ?';       $args[] = clip($c['title'], 255); }
            if (array_key_exists('desc', $c))   { $sets[] = 'description = ?'; $args[] = clip($c['desc'], 2000); }
            if (array_key_exists('hidden', $c)) { $sets[] = 'is_hidden = ?';   $args[] = $c['hidden'] ? 1 : 0; }
            if (!$sets) continue;
            $args[] = $card;
            db()->prepare('UPDATE ep_cards SET ' . implode(', ', $sets) . ' WHERE card_key = ?')->execute($args);
        }
    }

    if (isset($s['folders']) && is_array($s['folders'])) save_folder_list($s['folders']);
}

// المجلدات: الاسم والأيقونة والترتيب والإظهار والعناصر الفرعية (مع الحفاظ على حالة الإنجاز)
function save_folder_list(array $list): void
{
    $pdo = db();
    $upsert = $pdo->prepare('INSERT INTO ep_folders (id, title, icon, is_hidden, sort_order) VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE title = VALUES(title), icon = VALUES(icon), is_hidden = VALUES(is_hidden), sort_order = VALUES(sort_order)');
    $doneStmt = $pdo->prepare('SELECT point_text FROM ep_folder_points WHERE folder_id = ? AND is_done = 1');
    $delPoints = $pdo->prepare('DELETE FROM ep_folder_points WHERE folder_id = ?');
    $addPoint = $pdo->prepare('INSERT INTO ep_folder_points (folder_id, point_text, is_done, sort_order) VALUES (?, ?, ?, ?)');

    $ids = [];
    foreach (array_values($list) as $i => $f) {
        if (!is_array($f) || !valid_id($f['id'] ?? null) || in_array($f['id'], $ids, true)) continue;
        $id = $f['id'];
        $icon = (is_string($f['icon'] ?? null) && preg_match('/^fa-[a-z0-9-]{1,40}$/', $f['icon'])) ? $f['icon'] : 'fa-folder';
        $upsert->execute([$id, clip($f['title'] ?? '', 255) ?: 'مجلد بدون اسم', $icon, !empty($f['hidden']) ? 1 : 0, $i + 1]);
        $ids[] = $id;

        $doneStmt->execute([$id]);
        $done = $doneStmt->fetchAll(PDO::FETCH_COLUMN);
        $delPoints->execute([$id]);
        foreach (array_values(is_array($f['points'] ?? null) ? $f['points'] : []) as $j => $p) {
            $text = clip($p, 500);
            if ($text !== '') $addPoint->execute([$id, $text, in_array($text, $done, true) ? 1 : 0, $j + 1]);
        }
    }

    // حذف المجلدات التي أُزيلت (تُحذف عناصرها وشواهدها تلقائياً)
    if ($ids) {
        $pdo->prepare('DELETE FROM ep_folders WHERE id NOT IN (' . implode(',', array_fill(0, count($ids), '?')) . ')')->execute($ids);
    } else {
        $pdo->exec('DELETE FROM ep_folders');
    }
}

// محتوى المجلدات: الملاحظات، العناصر المكتملة، الشواهد المرفوعة
function save_folder_state(array $state): void
{
    $pdo = db();
    $exists = $pdo->prepare('SELECT 1 FROM ep_folders WHERE id = ?');
    $notes = $pdo->prepare('UPDATE ep_folders SET notes = ? WHERE id = ?');
    $clear = $pdo->prepare('UPDATE ep_folder_points SET is_done = 0 WHERE folder_id = ?');
    $mark = $pdo->prepare('UPDATE ep_folder_points SET is_done = 1 WHERE folder_id = ? AND point_text = ?');
    $delItems = $pdo->prepare('DELETE FROM ep_folder_items WHERE folder_id = ?');
    $addItem = $pdo->prepare('INSERT INTO ep_folder_items (folder_id, item_type, title, description, url, file_size, created_at, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');

    foreach ($state as $fid => $st) {
        $fid = (string)$fid;
        if (!valid_id($fid) || !is_array($st)) continue;
        $exists->execute([$fid]);
        if (!$exists->fetchColumn()) continue;

        $notes->execute([clip($st['notes'] ?? '', 5000), $fid]);
        $clear->execute([$fid]);
        foreach (is_array($st['checks'] ?? null) ? $st['checks'] : [] as $c) $mark->execute([$fid, clip($c, 500)]);

        $delItems->execute([$fid]);
        foreach (array_values(is_array($st['items'] ?? null) ? $st['items'] : []) as $j => $it) {
            if (!is_array($it) || !in_array($it['type'] ?? '', ITEM_TYPES, true)) continue;
            $type = $it['type'];
            $url = safe_url($type === 'image' ? ($it['src'] ?? null) : ($it['url'] ?? null), $type === 'link');
            if (!$url) continue;
            $addItem->execute([$fid, $type, clip($it['name'] ?? '', 255), clip($it['desc'] ?? '', 2000), $url, int_or_null($it['size'] ?? null), ms_to_datetime($it['date'] ?? null), $j + 1]);
        }
    }
}

function save_profile(array $cv): void
{
    $sets = []; $args = [];
    foreach (PROFILE_MAP as $key => $col) {
        if (!array_key_exists($key, $cv)) continue;
        $val = $cv[$key];
        if (in_array($col, PROFILE_DATES, true)) $val = date_or_null($val);
        elseif (in_array($col, PROFILE_INTS, true)) $val = int_or_null($val);
        else $val = clip($val, 255);
        $sets[] = "$col = ?"; $args[] = $val;
    }
    db()->exec('INSERT IGNORE INTO ep_profile (id) VALUES (1)');
    if ($sets) db()->prepare('UPDATE ep_profile SET ' . implode(', ', $sets) . ' WHERE id = 1')->execute($args);
}

function save_courses(array $rows): void
{
    $pdo = db();
    $pdo->exec('DELETE FROM ep_courses');
    $add = $pdo->prepare('INSERT INTO ep_courses (title, provider, course_date, hours, attendance_mode, has_certificate, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $order = 0;
    foreach ($rows as $r) {
        if (!is_array($r)) continue;
        $title = clip($r['title'] ?? '', 255);
        $provider = clip($r['provider'] ?? '', 255);
        $date = date_or_null($r['date'] ?? null);
        $hours = is_numeric($r['hours'] ?? null) ? (float)$r['hours'] : null;
        if ($title === '' && $provider === '' && $date === null && $hours === null) continue;   // صف فارغ
        $add->execute([$title, $provider, $date, $hours, clip($r['mode'] ?? 'حضوري', 30), ($r['cert'] ?? 'نعم') === 'لا' ? 0 : 1, ++$order]);
    }
}

function save_certificates(array $rows): void
{
    $pdo = db();
    $pdo->exec('DELETE FROM ep_certificates');
    $add = $pdo->prepare('INSERT INTO ep_certificates (category, title, description, image_url, created_at, sort_order) VALUES (?, ?, ?, ?, ?, ?)');
    $order = 0;
    foreach ($rows as $r) {
        if (!is_array($r) || !($url = safe_url($r['src'] ?? null))) continue;
        $add->execute([($r['cat'] ?? '') === 'course' ? 'course' : 'thanks', clip($r['title'] ?? '', 255), clip($r['desc'] ?? '', 2000), $url, ms_to_datetime($r['date'] ?? null), ++$order]);
    }
}

function save_key(string $key, $value): void
{
    switch ($key) {
        case 'settings': if (is_array($value)) save_settings($value); return;
        case 'cv':       if (is_array($value)) save_profile($value); return;
        case 'growth':   if (is_array($value)) save_courses($value); return;
        case 'certs':    if (is_array($value)) save_certificates($value); return;
        case 'folders':  if (is_array($value)) save_folder_state($value); return;
        case 'avatar':
            db()->exec('INSERT IGNORE INTO ep_profile (id) VALUES (1)');
            db()->prepare('UPDATE ep_profile SET avatar_url = ? WHERE id = 1')->execute([safe_url($value)]);
            return;
        case 'logo':
            $url = safe_url($value);
            if ($url) put_setting('logo', $url); else db()->prepare('DELETE FROM ep_settings WHERE setting_key = ?')->execute(['logo']);
            return;
    }
    if (strpos($key, 'img:') === 0 && in_array(substr($key, 4), CARD_KEYS, true)) {
        db()->prepare('UPDATE ep_cards SET image_url = ? WHERE card_key = ?')->execute([safe_url($value), substr($key, 4)]);
        return;
    }
    fail('نوع بيانات غير معروف: ' . $key);
}

function in_transaction(callable $fn): void
{
    $pdo = db();
    $pdo->beginTransaction();
    try { $fn(); $pdo->commit(); }
    catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}

/* =====================================================================
   التوجيه
   ===================================================================== */
$action = (string)($_GET['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($action, ['load', 'platform'], true) && $method !== 'POST') fail('طريقة الطلب غير مسموحة', 405);

try {
    switch ($action) {

        // قراءة جميع البيانات (للزوار والمسؤول)
        case 'load':
            out([
                'ok'          => true,
                'data'        => load_all(),
                'admin'       => is_admin(),
                'csrf'        => is_admin() ? $_SESSION['csrf'] : null,
                'adminUser'   => is_admin() ? admin_credentials()['user'] : null,
                'maxUploadMb' => MAX_UPLOAD_MB,
                'platform'    => is_admin() ? [
                    'url'      => get_setting('_platform_url') ?? '',
                    'hasKey'   => (bool)get_setting('_platform_key'),
                    'cachedAt' => (int)(get_setting('_platform_cache_at') ?? 0),
                ] : null,
            ]);

        // شواهد منصة المعلمة (للجميع) — من النسخة المؤقتة، وتُجدَّد كل 10 دقائق
        case 'platform':
            if (!get_setting('_platform_url') || !get_setting('_platform_key')) out(['ok' => true, 'data' => null]);
            $cache = get_setting('_platform_cache');
            $age = time() - (int)(get_setting('_platform_cache_at') ?? 0);
            if ($cache && $age < PLATFORM_CACHE_SECONDS) out(['ok' => true, 'data' => json_decode($cache, true)]);
            try {
                out(['ok' => true, 'data' => platform_refresh()]);
            } catch (Throwable $e) {
                error_log('eportfolio platform: ' . $e->getMessage());
                out(['ok' => true, 'data' => $cache ? json_decode($cache, true) : null, 'stale' => true,
                     'error' => is_admin() ? $e->getMessage() : null]);
            }

        // حفظ رابط المنصة ومفتاح الربط ثم اختبار الاتصال
        case 'platform_config':
            require_admin();
            $b = body();
            $url = trim((string)($b['url'] ?? ''));
            if ($url === '') {
                del_settings(['_platform_url', '_platform_key', '_platform_cache', '_platform_cache_at']);
                out(['ok' => true, 'disabled' => true]);
            }
            if (!preg_match('#^https?://[^\s<>"\']+$#i', $url)) fail('رابط المنصة غير صحيح — مثال: https://teacher.infinityfree.me');
            $url = preg_replace('#[?\#].*$#', '', $url);
            $url = preg_replace('#/(api\.php|index\.html?)$#i', '', rtrim($url, '/'));
            $key = trim((string)($b['key'] ?? ''));
            if ($key === '' && !get_setting('_platform_key')) fail('اكتب مفتاح الربط');
            put_setting('_platform_url', $url);
            if ($key !== '') put_setting('_platform_key', $key);
            del_settings(['_platform_cache', '_platform_cache_at']);
            try {
                $data = platform_refresh();
                out(['ok' => true, 'url' => $url, 'test' => ['ok' => true] + platform_counts($data), 'data' => $data]);
            } catch (Throwable $e) {
                out(['ok' => true, 'url' => $url, 'test' => ['ok' => false, 'error' => $e->getMessage()]]);
            }

        // تحديث فوري لشواهد المنصة
        case 'platform_refresh':
            require_admin();
            try {
                $data = platform_refresh();
                out(['ok' => true, 'data' => $data, 'test' => ['ok' => true] + platform_counts($data)]);
            } catch (Throwable $e) {
                fail($e->getMessage());
            }

        // تسجيل دخول المسؤول
        case 'login':
            $cred = admin_credentials();
            if (!$cred['hash'] && (ADMIN_PASSWORD === 'CHANGE_ME' || ADMIN_PASSWORD === '')) {
                fail('لم تُضبط كلمة مرور المسؤول بعد — افتح ملف config.php وغيّر CHANGE_ME إلى كلمة مرورك', 403);
            }
            $ip = client_ip();
            $stmt = db()->prepare('SELECT attempts, last_at FROM ep_login_attempts WHERE ip = ?');
            $stmt->execute([$ip]);
            $row = $stmt->fetch();
            $recent = $row && (time() - (int)$row['last_at']) < LOGIN_LOCK_SECONDS;
            if ($recent && (int)$row['attempts'] >= LOGIN_MAX_ATTEMPTS) {
                fail('محاولات دخول كثيرة — حاول مرة أخرى بعد 15 دقيقة', 429);
            }

            $b = body();
            $userOk = hash_equals((string)$cred['user'], (string)($b['username'] ?? ''));
            $passOk = password_matches((string)($b['password'] ?? ''), $cred['hash']);
            if (!$userOk || !$passOk) {
                $attempts = $recent ? (int)$row['attempts'] + 1 : 1;
                db()->prepare('INSERT INTO ep_login_attempts (ip, attempts, last_at) VALUES (?, ?, ?)
                               ON DUPLICATE KEY UPDATE attempts = VALUES(attempts), last_at = VALUES(last_at)')
                    ->execute([$ip, $attempts, time()]);
                usleep(400000);
                fail('اسم المستخدم أو كلمة المرور غير صحيحة', 401);
            }

            db()->prepare('DELETE FROM ep_login_attempts WHERE ip = ?')->execute([$ip]);
            session_regenerate_id(true);
            $_SESSION['admin'] = true;
            $_SESSION['csrf']  = bin2hex(random_bytes(32));
            out(['ok' => true, 'csrf' => $_SESSION['csrf']]);

        // تسجيل الخروج
        case 'logout':
            $_SESSION = [];
            $p = session_get_cookie_params();
            setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $p['path'], 'secure' => $p['secure'], 'httponly' => true, 'samesite' => 'Strict']);
            session_destroy();
            out(['ok' => true]);

        // حفظ جزء من الصفحة
        case 'save':
            require_admin();
            $b = body();
            if (!is_string($b['key'] ?? null) || !array_key_exists('value', $b)) fail('طلب غير صالح');
            in_transaction(fn() => save_key($b['key'], $b['value']));
            out(['ok' => true]);

        // حفظ عدة أجزاء دفعة واحدة (الاستيراد)
        case 'save_many':
            require_admin();
            $items = body()['items'] ?? null;
            if (!is_array($items)) fail('طلب غير صالح');
            in_transaction(function () use ($items) {
                foreach (ordered_keys(array_keys($items)) as $key) save_key((string)$key, $items[$key]);
            });
            out(['ok' => true]);

        // حذف الصورة الشخصية / الشعار / صورة بطاقة
        case 'delete':
            require_admin();
            $key = body()['key'] ?? null;
            if (!in_array($key, ['avatar', 'logo'], true) && !(is_string($key) && strpos($key, 'img:') === 0)) fail('طلب غير صالح');
            in_transaction(fn() => save_key($key, null));
            out(['ok' => true]);

        // رفع ملف على أجزاء نصية صغيرة (Base64 داخل JSON)
        // جدار الحماية في بعض الاستضافات المجانية يفحص بايتات الصور الخام ويرفض بعضها خطأً (403)،
        // والأجزاء النصية الصغيرة لا تثير هذا الفحص ولا تتجاوز حد حجم الطلب.
        case 'upload_chunk':
            require_admin();
            $b = body();
            $id = (string)($b['id'] ?? '');
            if (preg_match('/^[a-f0-9]{32}$/', $id) !== 1) fail('طلب غير صالح');
            $ext = strtolower((string)($b['ext'] ?? ''));
            if (!isset(ALLOWED_UPLOADS[$ext])) fail('نوع الملف غير مسموح (المسموح: الصور، Word، PDF)');
            $offset = $b['offset'] ?? null;
            if (!is_int($offset) || $offset < 0) fail('طلب غير صالح');
            $data = base64_decode((string)($b['data'] ?? ''), true);
            if ($data === false || $data === '' || strlen($data) > 262144) fail('بيانات الملف تالفة — أعد المحاولة');

            ensure_upload_dir();
            $part = UPLOAD_DIR . '/' . $id . '.part';
            if ($offset === 0) {
                foreach (glob(UPLOAD_DIR . '/*.part') ?: [] as $old) {
                    if (@filemtime($old) < time() - 3600) @unlink($old);   // بقايا رفع لم يكتمل
                }
                @unlink($part);
            }
            clearstatcache(true, $part);
            $size = is_file($part) ? (int)filesize($part) : 0;
            if ($offset !== $size) fail('انقطع الرفع — أعد المحاولة', 409);
            if ($size + strlen($data) > MAX_UPLOAD_MB * 1048576) { @unlink($part); fail('الملف أكبر من ' . MAX_UPLOAD_MB . ' ميجابايت'); }
            if (@file_put_contents($part, $data, FILE_APPEND | LOCK_EX) === false) fail('تعذر حفظ الملف على الخادم', 500);
            if (empty($b['last'])) out(['ok' => true, 'received' => $size + strlen($data)]);

            if (!in_array(detect_mime($part), ALLOWED_UPLOADS[$ext], true)) { @unlink($part); fail('محتوى الملف لا يطابق امتداده'); }
            $name = bin2hex(random_bytes(16)) . '.' . $ext;
            if (!@rename($part, UPLOAD_DIR . '/' . $name)) { @unlink($part); fail('تعذر حفظ الملف على الخادم', 500); }
            @chmod(UPLOAD_DIR . '/' . $name, 0644);
            out(['ok' => true, 'url' => 'uploads/' . $name, 'size' => (int)filesize(UPLOAD_DIR . '/' . $name)]);

        // رفع ملف (صورة / Word / PDF) دفعة واحدة — بديل قديم
        case 'upload':
            require_admin();
            $file = $_FILES['file'] ?? null;
            if (!$file && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > ini_bytes('post_max_size')) {
                fail('الملف أكبر من الحد المسموح في الاستضافة (' . upload_limit_mb() . ' ميجابايت)');
            }
            if (!$file || !is_array($file) || is_array($file['error'])) fail('لم يُرسل أي ملف');
            if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) fail('الملف أكبر من الحد المسموح في إعدادات الاستضافة');
            if ($file['error'] !== UPLOAD_ERR_OK) fail('فشل رفع الملف (رمز ' . (int)$file['error'] . ')');
            if ($file['size'] > upload_limit_mb() * 1048576) fail('الملف أكبر من ' . upload_limit_mb() . ' ميجابايت');

            $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
            if (!isset(ALLOWED_UPLOADS[$ext])) fail('نوع الملف غير مسموح (المسموح: الصور، Word، PDF)');
            $mime = detect_mime($file['tmp_name']);
            if (!in_array($mime, ALLOWED_UPLOADS[$ext], true)) fail('محتوى الملف لا يطابق امتداده');

            ensure_upload_dir();
            $name = bin2hex(random_bytes(16)) . '.' . $ext;
            if (!@move_uploaded_file($file['tmp_name'], UPLOAD_DIR . '/' . $name)) fail('تعذر حفظ الملف على الخادم — تحقق من صلاحيات مجلد uploads', 500);
            @chmod(UPLOAD_DIR . '/' . $name, 0644);
            out(['ok' => true, 'url' => 'uploads/' . $name, 'size' => (int)$file['size']]);

        // حذف ملف مرفوع
        case 'delete_file':
            require_admin();
            delete_stored_file(body()['url'] ?? null);
            out(['ok' => true]);

        // تغيير اسم المستخدم وكلمة مرور المسؤول من داخل الموقع
        case 'change_credentials':
            require_admin();
            $b = body();
            $cred = admin_credentials();
            if (!password_matches((string)($b['current'] ?? ''), $cred['hash'])) { usleep(400000); fail('كلمة المرور الحالية غير صحيحة'); }
            $newPass = (string)($b['password'] ?? '');
            if (strlen($newPass) < 8) fail('كلمة المرور الجديدة يجب أن تكون 8 أحرف على الأقل');
            $newUser = clip($b['username'] ?? '', 64) ?: (string)$cred['user'];
            in_transaction(function () use ($newUser, $newPass) {
                put_setting('_admin_user', $newUser);
                put_setting('_admin_hash', password_hash($newPass, PASSWORD_DEFAULT));
            });
            session_regenerate_id(true);
            out(['ok' => true, 'adminUser' => $newUser]);

        // تنزيل نسخة احتياطية كاملة (البيانات + الملفات) بصيغة ZIP
        case 'backup':
            require_admin();
            if (!class_exists('ZipArchive')) fail('الاستضافة لا تدعم إنشاء ملفات ZIP (إضافة zip في PHP غير مفعّلة)', 500);
            $tmp = tempnam(sys_get_temp_dir(), 'epb');
            $zip = new ZipArchive();
            if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) fail('تعذر إنشاء ملف النسخة الاحتياطية', 500);
            $zip->addFromString('data.json', json_encode(load_all(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            foreach (glob(UPLOAD_DIR . '/*') ?: [] as $path) {
                if (preg_match(STORED_FILE_RE, basename($path)) === 1) $zip->addFile($path, 'uploads/' . basename($path));
            }
            $zip->close();
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="eportfolio-backup-' . date('Y-m-d') . '.zip"');
            header('Content-Length: ' . filesize($tmp));
            readfile($tmp);
            @unlink($tmp);
            exit;

        // استعادة نسخة احتياطية كاملة من ملف ZIP
        case 'restore':
            require_admin();
            if (!class_exists('ZipArchive')) fail('الاستضافة لا تدعم ملفات ZIP (إضافة zip في PHP غير مفعّلة)', 500);
            $file = $_FILES['file'] ?? null;
            if (!$file || !is_array($file) || is_array($file['error'])) fail('لم يُرسل أي ملف');
            if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) fail('ملف النسخة أكبر من الحد المسموح في إعدادات الاستضافة');
            if ($file['error'] !== UPLOAD_ERR_OK) fail('فشل رفع ملف النسخة (رمز ' . (int)$file['error'] . ')');
            $zip = new ZipArchive();
            if ($zip->open($file['tmp_name']) !== true) fail('ملف النسخة الاحتياطية غير صالح');
            $data = json_decode((string)$zip->getFromName('data.json'), true);
            if (!is_array($data)) fail('ملف النسخة لا يحتوي على بيانات صالحة (data.json)');

            // استخراج الملفات المسموحة فقط (بأسمائها العشوائية) مع التحقق من نوعها
            if (!is_dir(UPLOAD_DIR)) @mkdir(UPLOAD_DIR, 0755, true);
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if (!$stat || !preg_match('#^uploads/([a-f0-9]{32}\.([a-z]+))$#', $stat['name'], $m)) continue;
                if (preg_match(STORED_FILE_RE, $m[1]) !== 1 || $stat['size'] > MAX_UPLOAD_MB * 1024 * 1024) continue;
                $dest = UPLOAD_DIR . '/' . $m[1];
                $in = $zip->getStream($stat['name']);
                if (!$in) continue;
                $outFile = fopen($dest, 'wb');
                stream_copy_to_stream($in, $outFile, MAX_UPLOAD_MB * 1024 * 1024);
                fclose($in); fclose($outFile);
                if (!in_array(detect_mime($dest), ALLOWED_UPLOADS[$m[2]] ?? [], true)) @unlink($dest);
                else @chmod($dest, 0644);
            }
            $zip->close();

            in_transaction(function () use ($data) {
                $pdo = db();
                $pdo->exec('DELETE FROM ep_profile');
                $pdo->exec('INSERT INTO ep_profile (id) VALUES (1)');
                $pdo->exec('UPDATE ep_cards SET image_url = NULL');
                $pdo->exec("DELETE FROM ep_settings WHERE setting_key = 'logo'");
                foreach (ordered_keys(array_keys($data)) as $key) {
                    $key = (string)$key;
                    if (in_array($key, KEY_ORDER, true) || strpos($key, 'img:') === 0) save_key($key, $data[$key]);
                }
            });
            out(['ok' => true]);

        // حذف جميع البيانات والملفات وإعادة الوضع الافتراضي (مع الإبقاء على بيانات دخول المسؤول)
        case 'reset':
            require_admin();
            $pdo = db();
            $keepAuth = $pdo->query("SELECT setting_key, setting_value FROM ep_settings WHERE setting_key IN ('_admin_user', '_admin_hash', '_platform_url', '_platform_key')")->fetchAll(PDO::FETCH_KEY_PAIR);
            foreach (['ep_folder_items', 'ep_folder_points', 'ep_folders', 'ep_certificates', 'ep_courses', 'ep_cards', 'ep_profile', 'ep_settings'] as $t) {
                $pdo->exec("DROP TABLE IF EXISTS $t");
            }
            run_sql_file($pdo);
            foreach ($keepAuth as $k => $v) put_setting($k, $v);
            foreach (glob(UPLOAD_DIR . '/*') ?: [] as $path) {
                if (preg_match(STORED_FILE_RE, basename($path)) === 1) @unlink($path);
            }
            out(['ok' => true]);

        default:
            fail('طلب غير معروف', 404);
    }
} catch (PDOException $e) {
    error_log('eportfolio: ' . $e->getMessage());
    fail('حدث خطأ في قاعدة البيانات', 500);
} catch (Throwable $e) {
    error_log('eportfolio: ' . $e->getMessage());
    fail(is_admin() ? 'خطأ في الخادم: ' . $e->getMessage() : 'خطأ في الخادم', 500);
}
