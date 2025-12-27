<?php

// 加载辅助函数
require_once 'helpers.php';
require_once 'RequestIsolationManager.php';

define('RUNTIME_PATH', ROOT_PATH . '/runtime');
define('CONFIG_PATH', ROOT_PATH . '/config');
define('ROUTES_PATH', ROOT_PATH . '/routes');

// 加载环境变量
if (file_exists(ROOT_PATH . '/.env')) {
    $envContent = file_get_contents(ROOT_PATH . '/.env');
    $lines = explode("\n", $envContent);
    
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line) || strpos($line, '#') === 0) {
            continue;
        }
        
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            
            // 移除引号
            if (preg_match('/^"(.*)"$/', $value, $matches) || preg_match('/^\'(.*)\'$/', $value, $matches)) {
                $value = $matches[1];
            }
            
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
            putenv("$key=$value");
        }
    }
}

date_default_timezone_set($_ENV['APP_TIMEZONE']??'Asia/Shanghai');

// 创建必要的目录
$requiredDirs = [
    RUNTIME_PATH . '/logs',
    RUNTIME_PATH . '/cache',
    RUNTIME_PATH . '/sessions',
];

foreach ($requiredDirs as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}

