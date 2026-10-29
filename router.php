<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if (str_starts_with($path, '/api/')) {
    $_SERVER['PATH_INFO'] = substr($path, 4);
    require __DIR__ . '/api/index.php';
    return true;
}

if ($path === '/') {
    require __DIR__ . '/public/index.html';
    return true;
}

$publicFile = realpath(__DIR__ . '/public' . $path);
$publicRoot = realpath(__DIR__ . '/public');

if ($publicFile !== false &&
    str_starts_with($publicFile, $publicRoot . DIRECTORY_SEPARATOR) &&
    is_file($publicFile)) {
    return false;
}

http_response_code(404);
echo 'Not found';