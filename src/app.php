<?php

use PHPFrame\Application;

require_once 'autoload.php';

$app = new Application();

// 如果配置了异常处理器，则设置异常处理
if ($exceptionHandler = config('exception.handler')) {
    $app->set($exceptionHandler, function($c) use ($exceptionHandler) {
        return new $exceptionHandler(
            $c->get('logger'),
            $c->get('config')->get('exception')
        );
    });

    set_exception_handler(function($exception) use ($app, $exceptionHandler) {
        $handler = $app->get($exceptionHandler);
        $mode = PHP_SAPI === 'cli' ? 'cli' : 'fpm';
        return $handler->handle($exception, $mode);
    });
}

return $app;