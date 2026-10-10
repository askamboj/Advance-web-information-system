<?php
// Development server: php -S 127.0.0.1:8080 router.php
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$file = realpath(__DIR__ . $path);
if ($file && str_starts_with($file, __DIR__ . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR) && is_file($file) && in_array(pathinfo($file,PATHINFO_EXTENSION),['css','js','svg','png','webp'],true)) return false;
if ($path !== '/' && $path !== '/index.php') { http_response_code(404); exit('Not found'); }
require __DIR__ . '/index.php';
