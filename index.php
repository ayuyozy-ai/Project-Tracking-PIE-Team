<?php

declare(strict_types=1);

$html = file_get_contents(__DIR__ . '/public/index.html');
if ($html === false) {
    http_response_code(500);
    exit('Gagal memuat antarmuka aplikasi.');
}

$html = str_replace('src="/shared.js"', 'src="public/shared.js"', $html);
header('Content-Type: text/html; charset=utf-8');
echo $html;
