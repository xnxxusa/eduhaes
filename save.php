<?php
/**
 * STP Batch HTML Generator — Server Save Endpoint (v3, chunked)
 * Soft-Tech Point · Created for Tarik Aziz
 *
 * ZIP টা ছোট ছোট chunk-এ আসে, তাই nginx-এর client_max_body_size
 * (default 1MB) বাড়ানো ছাড়াই বড় batch upload হয়।
 *
 * Flow:
 *   POST action=chunk    × N   → temp file-এ append
 *   POST action=finalize       → assemble, save, extract, url-list
 *   POST (no action)           → single-shot upload (ছোট batch)
 *
 * Requires: PHP 7.4+ with ext-zip
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function out(int $code, array $data): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * ZIP entry name → safe filename.
 * নাম যেমন আছে তেমনই রাখে — space, Arabic, Bengali, &, ( ) সব allowed।
 * শুধু সত্যিই বিপজ্জনক জিনিস সরায় (path separator, control char,
 * traversal, hidden file)। আসল security হলো extension whitelist +
 * protected names + path-escape guard।
 */
function stp_safe_name(string $raw): string {
    $name = basename(str_replace('\\', '/', $raw));
    $name = preg_replace('#[\x00-\x1f\x7f/\\\\:*?"<>|]#u', '', $name) ?? '';

    while (strpos($name, '..') !== false) {
        $name = str_replace('..', '.', $name);
    }

    $name = ltrim($name, ". \t");
    $name = rtrim($name, " \t");

    if (strlen($name) > 180) {
        $ext  = (string)pathinfo($name, PATHINFO_EXTENSION);
        $stem = (string)pathinfo($name, PATHINFO_FILENAME);
        $stem = substr($stem, 0, 180 - strlen($ext) - 1);
        $name = $stem . '.' . $ext;
    }

    if ($name === '' || strpos($name, '.') === false) { return ''; }
    return $name;
}

/** Temp part-file path for a chunked upload */
function stp_part_path(string $uploadId): string {
    return rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'stp-upload-' . $uploadId . '.part';
}

/** এক ঘণ্টার পুরনো অসমাপ্ত upload মুছে ফেলি */
function stp_cleanup_stale(): void {
    $pat = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'stp-upload-*.part';
    foreach ((array)glob($pat) as $f) {
        if (is_file($f) && (time() - (int)filemtime($f)) > 3600) { @unlink($f); }
    }
}

// ══ Load config ═══════════════════════════════════════════════════════
$cfgPath = __DIR__ . '/config.php';
if (!is_file($cfgPath)) {
    out(500, ['ok' => false, 'error' => 'config.php missing']);
}
$cfg = require $cfgPath;

// ══ Method ════════════════════════════════════════════════════════════
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    out(405, ['ok' => false, 'error' => 'POST only']);
}

// ══ IP allowlist ══════════════════════════════════════════════════════
if (!empty($cfg['allowed_ips'])) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!in_array($ip, $cfg['allowed_ips'], true)) {
        out(403, ['ok' => false, 'error' => 'IP not allowed: ' . $ip]);
    }
}

// ══ Token (timing-safe) ═══════════════════════════════════════════════
$token = (string)($_POST['token'] ?? $_SERVER['HTTP_X_STP_TOKEN'] ?? '');
if ($token === '' || !hash_equals((string)$cfg['token'], $token)) {
    out(401, ['ok' => false, 'error' => 'Invalid token']);
}
if ($cfg['token'] === 'CHANGE-ME-a7f3c19d84be22105f6d90cc') {
    out(500, ['ok' => false, 'error' => 'Default token still in config.php — change it first!']);
}

if (!class_exists('ZipArchive')) {
    out(500, ['ok' => false, 'error' => 'PHP ext-zip not installed on this server']);
}

$maxB   = ((int)$cfg['max_upload_mb']) * 1024 * 1024;
$action = (string)($_POST['action'] ?? 'upload');

// ══════════════════════════════════════════════════════════════════════
//  PING — client endpoint + token যাচাই করতে পারে
// ══════════════════════════════════════════════════════════════════════
if ($action === 'ping') {
    out(200, [
        'ok'            => true,
        'pong'          => true,
        'php'           => PHP_VERSION,
        'post_max_size' => ini_get('post_max_size'),
        'upload_max'    => ini_get('upload_max_filesize'),
    ]);
}

// ══════════════════════════════════════════════════════════════════════
//  CHUNK — ZIP-এর একটা টুকরো temp file-এ append
// ══════════════════════════════════════════════════════════════════════
if ($action === 'chunk') {

    $uploadId = (string)($_POST['upload_id'] ?? '');
    if (!preg_match('/^[a-f0-9]{8,64}$/', $uploadId)) {
        out(400, ['ok' => false, 'error' => 'Bad upload_id']);
    }

    $index = (int)($_POST['index'] ?? -1);
    $total = (int)($_POST['total'] ?? 0);
    if ($index < 0 || $total < 1 || $index >= $total || $total > 10000) {
        out(400, ['ok' => false, 'error' => 'Bad chunk index/total']);
    }

    if (!isset($_FILES['chunk']) || $_FILES['chunk']['error'] !== UPLOAD_ERR_OK) {
        $e = $_FILES['chunk']['error'] ?? 'none';
        out(400, ['ok' => false, 'error' => 'Chunk upload failed (code ' . $e . ')']);
    }
    if (!is_uploaded_file($_FILES['chunk']['tmp_name'])) {
        out(400, ['ok' => false, 'error' => 'Not a valid upload']);
    }

    $part = stp_part_path($uploadId);

    if ($index === 0) {
        stp_cleanup_stale();
        @unlink($part);   // পুরনো অসমাপ্ত attempt থাকলে নতুন করে শুরু
    }

    $already = is_file($part) ? (int)filesize($part) : 0;
    if ($already + (int)$_FILES['chunk']['size'] > $maxB) {
        @unlink($part);
        out(413, ['ok' => false, 'error' => 'Total size over limit (' . $cfg['max_upload_mb'] . 'MB)']);
    }

    $data = file_get_contents($_FILES['chunk']['tmp_name']);
    if ($data === false) {
        out(500, ['ok' => false, 'error' => 'Cannot read chunk']);
    }
    if (file_put_contents($part, $data, FILE_APPEND | LOCK_EX) === false) {
        out(500, ['ok' => false, 'error' => 'Cannot write to temp dir: ' . sys_get_temp_dir()]);
    }

    out(200, [
        'ok'       => true,
        'index'    => $index,
        'received' => (int)filesize($part),
    ]);
}

// ══════════════════════════════════════════════════════════════════════
//  FINALIZE / single-shot — এখান থেকে দুই পথই এক
// ══════════════════════════════════════════════════════════════════════
$srcPath   = '';
$srcIsPart = false;

if ($action === 'finalize') {

    $uploadId = (string)($_POST['upload_id'] ?? '');
    if (!preg_match('/^[a-f0-9]{8,64}$/', $uploadId)) {
        out(400, ['ok' => false, 'error' => 'Bad upload_id']);
    }
    $part = stp_part_path($uploadId);
    if (!is_file($part)) {
        out(400, ['ok' => false, 'error' => 'No uploaded chunks found — আবার চেষ্টা করো']);
    }
    $srcPath   = $part;
    $srcIsPart = true;

} else {
    if (!isset($_FILES['zipfile']) || $_FILES['zipfile']['error'] !== UPLOAD_ERR_OK) {
        $e = $_FILES['zipfile']['error'] ?? 'none';
        out(400, ['ok' => false, 'error' => 'Upload failed (code ' . $e . ')']);
    }
    if (!is_uploaded_file($_FILES['zipfile']['tmp_name'])) {
        out(400, ['ok' => false, 'error' => 'Not a valid upload']);
    }
    $srcPath = $_FILES['zipfile']['tmp_name'];
}

$size = (int)filesize($srcPath);
if ($size <= 0 || $size > $maxB) {
    if ($srcIsPart) { @unlink($srcPath); }
    out(413, ['ok' => false, 'error' => 'File too large (max ' . $cfg['max_upload_mb'] . 'MB)']);
}

// Magic bytes: PK\x03\x04
$fh  = fopen($srcPath, 'rb');
$sig = fread($fh, 4);
fclose($fh);
if ($sig !== "PK\x03\x04") {
    if ($srcIsPart) { @unlink($srcPath); }
    out(400, ['ok' => false, 'error' => 'Assembled file is not a valid ZIP']);
}

// ══ Output dir ════════════════════════════════════════════════════════
$outDir = rtrim((string)$cfg['output_dir'], '/\\');
if (!is_dir($outDir) && !@mkdir($outDir, 0755, true)) {
    out(500, ['ok' => false, 'error' => 'Cannot create output dir']);
}
if (!is_writable($outDir)) {
    out(500, ['ok' => false, 'error' => 'Output dir not writable — chmod 755 লাগবে']);
}
$outReal = realpath($outDir);

$scriptDir = realpath(__DIR__);
$relDir    = '';
if ($outReal !== $scriptDir && strpos($outReal, $scriptDir . DIRECTORY_SEPARATOR) === 0) {
    $relDir = str_replace('\\', '/', substr($outReal, strlen($scriptDir) + 1));
}

// ══ Serial (zip ↔ url-list জোড়া) ══════════════════════════════════════
$zipBase = (string)pathinfo((string)$cfg['zip_name'], PATHINFO_FILENAME);
if ($zipBase === '') { $zipBase = 'html-batch'; }
$zipBase = basename($zipBase);

if (!empty($cfg['timestamp_zip'])) {
    $serial  = date('Ymd-His');
    $zipName = $zipBase . '-' . $serial . '.zip';
    if (file_exists($outReal . DIRECTORY_SEPARATOR . $zipName)) {
        $zipName = $zipBase . '-' . $serial . '-' . substr(bin2hex(random_bytes(3)), 0, 4) . '.zip';
    }
} else {
    $next = 1;
    $seen = array_merge(
        (array)glob($outReal . DIRECTORY_SEPARATOR . $zipBase . '-*.zip'),
        (array)glob($outReal . DIRECTORY_SEPARATOR . 'url-list-*.txt')
    );
    foreach ($seen as $f) {
        if (preg_match('/-(\d+)\.(zip|txt)$/i', basename($f), $m)) {
            $n = (int)$m[1];
            if ($n >= $next) { $next = $n + 1; }
        }
    }
    $tries = 0;
    do {
        $pad     = ($next < 100) ? 2 : strlen((string)$next);
        $serial  = str_pad((string)$next, $pad, '0', STR_PAD_LEFT);
        $zipName = $zipBase . '-' . $serial . '.zip';
        $clash   = file_exists($outReal . DIRECTORY_SEPARATOR . $zipName)
                || file_exists($outReal . DIRECTORY_SEPARATOR . 'url-list-' . $serial . '.txt');
        $next++; $tries++;
    } while ($clash && $tries < 200);
}

// ══ Write the ZIP ═════════════════════════════════════════════════════
$zipPath = $outReal . DIRECTORY_SEPARATOR . $zipName;

if ($srcIsPart) {
    // temp আর webroot আলাদা filesystem-এ হতে পারে → rename fail হলে copy
    if (!@rename($srcPath, $zipPath)) {
        if (!@copy($srcPath, $zipPath)) {
            @unlink($srcPath);
            out(500, ['ok' => false, 'error' => 'Could not write ZIP to folder']);
        }
        @unlink($srcPath);
    }
} else {
    if (!move_uploaded_file($srcPath, $zipPath)) {
        out(500, ['ok' => false, 'error' => 'Could not write ZIP to folder']);
    }
}
@chmod($zipPath, 0644);

$result = [
    'ok'        => true,
    'dir'       => $relDir,
    'zip'       => $zipName,
    'zip_bytes' => filesize($zipPath),
    'zip_kept'  => true,
    'extracted' => 0,
    'skipped'   => [],
    'renamed'   => [],
    'files'     => [],
];

// ══ Extraction ════════════════════════════════════════════════════════
$wantExtract = (($_POST['extract']  ?? '0') === '1');
$keepZip     = (($_POST['keep_zip'] ?? '1') === '1');

if ($wantExtract && !empty($cfg['allow_extract'])) {

    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        $result['extract_error'] = 'Cannot open saved ZIP';
        out(200, $result);
    }

    if ($zip->numFiles > (int)$cfg['max_files']) {
        $zip->close();
        $result['extract_error'] = 'Too many files (' . $zip->numFiles . ' > ' . $cfg['max_files'] . ')';
        out(200, $result);
    }

    $allowedExt = array_map('strtolower', (array)$cfg['allowed_ext']);
    $protected  = array_map('strtolower', (array)$cfg['protected_names']);

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entry = $zip->getNameIndex($i);
        if ($entry === false) { continue; }
        if (substr($entry, -1) === '/') { continue; }

        $name = stp_safe_name($entry);

        if ($name === '') {
            $result['skipped'][] = $entry . ' (name unrecoverable)';
            continue;
        }
        if ($name !== basename(str_replace('\\', '/', $entry))) {
            if (count($result['renamed']) < 20) {
                $result['renamed'][] = basename($entry) . ' → ' . $name;
            }
        }

        $ext = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExt, true)) {
            $result['skipped'][] = $name . ' (.' . $ext . ' not allowed)';
            continue;
        }
        if (in_array(strtolower($name), $protected, true)) {
            $result['skipped'][] = $name . ' (protected)';
            continue;
        }
        // url-list.txt আলাদাভাবে serial নামে লেখা হয় — duplicate এড়াই
        if (strtolower($name) === 'url-list.txt') { continue; }

        $stat = $zip->statIndex($i);
        if ($stat === false || $stat['size'] > 5 * 1024 * 1024) {
            $result['skipped'][] = $name . ' (too big)';
            continue;
        }

        $data = $zip->getFromIndex($i);
        if ($data === false) {
            $result['skipped'][] = $name . ' (read fail)';
            continue;
        }

        $dest = $outReal . DIRECTORY_SEPARATOR . $name;
        if (dirname($dest) !== $outReal) {
            $result['skipped'][] = $name . ' (path escape)';
            continue;
        }

        if (file_put_contents($dest, $data) !== false) {
            @chmod($dest, 0644);
            $result['extracted']++;
            if (count($result['files']) < 50) { $result['files'][] = $name; }
        } else {
            $result['skipped'][] = $name . ' (write fail)';
        }
    }

    $zip->close();
}

// ══ URL list → url-list-01.txt (ZIP-এর সাথে একই serial) ═══════════════
$urlList = (string)($_POST['url_list'] ?? '');
if ($urlList !== '') {

    if (strlen($urlList) > 5 * 1024 * 1024) {
        $result['url_list_error'] = 'URL list too large (>5MB)';
    } else {
        $listName = 'url-list-' . $serial . '.txt';
        $listPath = $outReal . DIRECTORY_SEPARATOR . $listName;

        if (file_exists($listPath)) {
            $k = 2;
            while (file_exists($listPath) && $k < 100) {
                $listName = 'url-list-' . $serial . '-' . $k . '.txt';
                $listPath = $outReal . DIRECTORY_SEPARATOR . $listName;
                $k++;
            }
        }

        $urlList = str_replace(["\r\n", "\r"], "\n", $urlList);

        if (function_exists('mb_check_encoding') && !mb_check_encoding($urlList, 'UTF-8')) {
            $urlList = mb_convert_encoding($urlList, 'UTF-8', 'UTF-8');
        }

        // UTF-8 BOM — BOM ছাড়া Notepad/Excel UTF-8 কে ANSI ধরে,
        // ফলে Arabic / Bengali লেখা ভেঙে যায়।
        $bom = (!isset($cfg['utf8_bom']) || $cfg['utf8_bom']) ? "\xEF\xBB\xBF" : '';

        if (file_put_contents($listPath, $bom . $urlList) !== false) {
            @chmod($listPath, 0644);
            $result['url_list']       = $listName;
            $result['url_list_lines'] = count(array_filter(explode("\n", trim($urlList))));
        } else {
            $result['url_list_error'] = 'Could not write ' . $listName;
        }
    }
}

// ══ "Save ZIP" off থাকলে extract শেষে zip মুছে ফেলি ═══════════════════
if (!$keepZip) {
    if ($wantExtract && empty($cfg['allow_extract'])) {
        $result['note'] = 'Extraction disabled in config; ZIP kept instead.';
    } else {
        @unlink($zipPath);
        $result['zip_kept'] = false;
    }
}

out(200, $result);
