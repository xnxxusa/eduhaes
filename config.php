<?php
/**
 * STP Batch HTML Generator — Server Save Config
 * Soft-Tech Point
 *
 * IMPORTANT: এই token টা change করো deploy করার আগেই।
 * Random token বানাতে: php -r "echo bin2hex(random_bytes(24));"
 */

return [

    // ── Auth ──────────────────────────────────────────────────────────
    // এই token ছাড়া কেউ server-এ file লিখতে পারবে না।
    'token' => 'yggdf776tw76gfwgf7e6w7we76we87',

    // ── Where to write ────────────────────────────────────────────────
    // __DIR__  = save.php যেই folder-এ আছে সেই folder (অর্থাৎ /post/)
    // এখন output যাবে subfolder-এ → example.com/post/video/
    // Folder না থাকলে save.php নিজেই বানিয়ে নেবে (0755)।
    'output_dir' => __DIR__ . '/video',

    // ── ZIP settings ──────────────────────────────────────────────────
    // প্রতিবার নতুন নাম হয় — কিছুই overwrite হয় না।
    //   false → html-batch-01.zip, html-batch-02.zip ... (url-list এর সাথে জোড়া)
    //   true  → html-batch-20260812-183045.zip
    'zip_name'        => 'html-batch.zip',  // base name (serial auto যোগ হবে)
    'timestamp_zip'   => false,
    'max_upload_mb'   => 64,                // upload size limit

    // ── Text encoding ─────────────────────────────────────────────────
    // url-list ফাইলে UTF-8 BOM বসাবে কিনা। BOM ছাড়া Notepad/Excel
    // UTF-8 কে ANSI ধরে নেয় → Arabic/Bengali লেখা ভেঙে যায়।
    'utf8_bom'        => true,

    // ── Extraction ────────────────────────────────────────────────────
    // zip থেকে .html file গুলো folder-এ বের করে রাখবে কিনা (live pages)
    'allow_extract'   => true,
    'max_files'       => 2000,              // একবারে সর্বোচ্চ কত file
    'allowed_ext'     => ['html', 'htm', 'txt'],

    // এই নাম গুলো কখনোই overwrite হবে না (self-protection)
    'protected_names' => [
        'index.html', 'index.htm', 'index.php',
        'save.php', 'config.php', '.htaccess', 'web.config',
    ],

    // ── Optional IP allowlist ─────────────────────────────────────────
    // খালি array = সবাই allowed (token তো লাগবেই)
    // উদাহরণ: ['103.120.44.10', '203.0.113.5']
    'allowed_ips' => [],
];
