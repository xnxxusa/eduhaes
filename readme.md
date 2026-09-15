# STP Batch HTML Generator — Web Deployment

`example.com/post/` — generator page + generated ZIP একই folder-এ।

---

## Folder structure (upload করার পর)

```
public_html/
└── post/
    ├── index.html      ← generator page (example.com/post/)
    ├── save.php        ← ZIP save + extract endpoint
    ├── config.php      ← token + settings (edit করতেই হবে)
    ├── .htaccess       ← hardening
    ├── html-batch.zip  ← (auto তৈরি হবে)
    └── match-001.html… ← (extract on করলে auto তৈরি হবে)
```

---

## Deploy steps

### 1. Token change করো (বাধ্যতামূলক)
`config.php` খুলে `'token' => '...'` line-এ নতুন random token বসাও।

SSH থাকলে:
```bash
php -r "echo bin2hex(random_bytes(24)), PHP_EOL;"
```
না থাকলে যেকোনো ৩০+ character random string দাও। Default token রেখে দিলে
`save.php` ইচ্ছে করেই error দেবে।

### 2. Upload
পুরো `post/` folder টা `public_html/`-এ upload করো (hPanel File Manager বা FTP)।

### 3. Permission
```
folder post/   → 755
*.php, *.html  → 644
```

### 4. Test
`https://example.com/post/` খোলো → **Server token** field-এ config.php-এর token
বসাও → Generate চাপো।

সফল হলে নিচে link আসবে:
```
📦 https://example.com/post/html-batch.zip
```

---

## Checkbox গুলো কী করে

| Option | কাজ |
|---|---|
| **Save ZIP to folder** | ZIP টা `/post/html-batch.zip` হিসেবে server-এ save হবে |
| **Extract .html live** | ZIP-এর ভিতরের HTML file গুলো folder-এ বের হবে → `example.com/post/match-001.html` সরাসরি live |
| **Download to PC** | আগের মতো browser download (দুটো একসাথেও চলে) |

Token খালি রাখলে server-এ কিছুই যাবে না — শুধু PC download, অর্থাৎ tool টা
আগের মতোই কাজ করবে।

---

## Useful config tweaks (`config.php`)

```php
// প্রতিবার আলাদা zip রাখতে (overwrite এড়াতে)
'timestamp_zip' => true,     // html-batch-20260812-1830.zip

// ZIP আর live HTML আলাদা folder-এ চাইলে
'output_dir' => __DIR__ . '/out',   // → example.com/post/out/

// শুধু নিজের IP থেকে লিখতে দিতে
'allowed_ips' => ['103.120.44.10'],
```

`output_dir` change করলে generator-এর **URL List** box-এও সেই base URL দাও
(`https://example.com/post/out/`), তাহলে `url-list.txt` ঠিক URL বানাবে —
stpindexer-এ সরাসরি paste করা যাবে।

---

## Security notes

`save.php` একটা file-write endpoint, তাই এগুলো built-in আছে:

- **Token auth** — `hash_equals()` দিয়ে timing-safe compare
- **ZIP magic-byte check** — `PK\x03\x04` না হলে reject
- **Filename whitelist** — শুধু `[A-Za-z0-9._-]`, path traversal (`../`) flatten করা
- **Extension whitelist** — শুধু `.html/.htm/.txt` extract হয়, `.php` কখনোই না
- **Protected names** — `index.html`, `save.php`, `config.php`, `.htaccess` overwrite হবে না
- **Limits** — 64MB upload, 2000 file, per-file 5MB
- **`config.php` deny** — `.htaccess` দিয়ে browser থেকে blocked

অতিরিক্ত সুরক্ষা চাইলে folder-এ cPanel **Directory Privacy** (HTTP Basic Auth)
বসিয়ে দাও — তাহলে page টাই public থাকবে না।

---

## Requirements

- PHP 7.4+ (8.x recommended)
- `ext-zip` enabled — Hostinger/cPanel-এ default থাকে
- Static host (GitHub Pages, Netlify, Cloudflare Pages) হলে PHP চলবে না →
  শুধু `index.html` কাজ করবে, ZIP PC-তেই নামবে

---

## Troubleshooting

| Error | কারণ / সমাধান |
|---|---|
| `Invalid token` | config.php-এর token আর page-এর token মিলছে না |
| `Default token still in config.php` | Step 1 করা হয়নি |
| `PHP ext-zip not installed` | hPanel → PHP Configuration → `zip` extension enable |
| `Output dir not writable` | folder permission 755 করো |
| `413 Request Entity Too Large` | `index.html`-এ `CHUNK_BYTES` কমিয়ে `256 * 1024` করো |
| `404 — save.php পাওয়া যায়নি` | `save.php` আর `index.html` একই folder-এ আছে কিনা দেখো |
| `File not found.` (nginx) | PHP-FPM script path পাচ্ছে না — nginx block-এ `fastcgi_param SCRIPT_FILENAME` ঠিক আছে কিনা দেখো |
| `Cannot write to temp dir` | PHP-র `sys_get_temp_dir()` writable না — `open_basedir` চেক করো |
| `403` | server ওই folder-এ PHP execute করতে দিচ্ছে না |

---

## nginx ব্যবহারকারীদের জন্য

`.htaccess` **nginx-এ কাজ করে না**। Chunked upload-এর কারণে
`client_max_body_size` না বাড়ালেও চলবে, তবে server block-এ এটা দিলে
আরও দ্রুত হবে (তখন `CHUNK_BYTES` বড় করা যাবে):

```nginx
location /post/ {
    client_max_body_size 68M;
}

# config.php browser থেকে block (বাড়তি সুরক্ষা)
location = /post/config.php { deny all; }

# ZIP download হিসেবে যাক
location ~ ^/post/.*\.zip$ { add_header Content-Disposition attachment; }
```

PHP-FPM-এর `upload_max_filesize` / `post_max_size` chunk-এর চেয়ে বড় হলেই
যথেষ্ট (default 2M/8M-ই যথেষ্ট, কারণ chunk মাত্র 512KB)।

---

Created by **Tarik Aziz** · Soft-Tech Point
