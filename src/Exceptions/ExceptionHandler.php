<?php

namespace PHPFrame\Exceptions;

use PHPFrame\Logger;

/**
 * 框架内置异常处理器
 * 作为默认处理器，可被 config('exception.handler') 覆盖
 */
class ExceptionHandler
{
    protected $logger;
    protected array $config;

    /**
     * 不需要记录的异常类型
     */
    protected array $dontReport = [];

    public function __construct($logger = null, array $config = [])
    {
        $this->logger = $logger;
        $this->config = $config;
    }

    /**
     * 处理异常
     *
     * @param \Throwable $exception
     * @param string $mode 运行模式 (fpm, cli, shell)
     * @return mixed
     */
    public function handle(\Throwable $exception, string $mode = 'fpm')
    {
        // 记录异常日志
        $this->report($exception, $mode);

        // 渲染异常响应
        return $this->render($exception, $mode);
    }

    /**
     * 记录异常到日志
     */
    public function report(\Throwable $exception, string $mode = 'fpm'): void
    {
        if ($this->shouldntReport($exception)) {
            return;
        }

        try {
            if ($this->logger) {
                $level = $this->getLogLevel($exception);
                $context = $this->buildLogContext($exception, $mode);
                $this->logger->{$level}($exception->getMessage(), $context);
            }
        } catch (\Throwable $e) {
            // 日志记录失败时静默处理
        }
    }

    /**
     * 渲染异常响应
     */
    public function render(\Throwable $exception, string $mode = 'fpm'): mixed
    {
        switch ($mode) {
            case 'fpm':
                return $this->renderFpmResponse($exception);
            case 'cli':
                return $this->renderCliResponse($exception);
            case 'shell':
                return $this->renderShellResponse($exception);
            default:
                return $this->renderFpmResponse($exception);
        }
    }

    /**
     * 判断是否不需要记录
     */
    protected function shouldntReport(\Throwable $exception): bool
    {
        foreach ($this->dontReport as $type) {
            if ($exception instanceof $type) {
                return true;
            }
        }
        return false;
    }

    /**
     * 获取日志级别
     */
    protected function getLogLevel(\Throwable $exception): string
    {
        if ($exception instanceof \ErrorException) {
            $severity = $exception->getSeverity();
            return match ($severity) {
                E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR => 'error',
                E_WARNING, E_CORE_WARNING, E_COMPILE_WARNING, E_USER_WARNING => 'warning',
                E_NOTICE, E_USER_NOTICE, E_STRICT, E_DEPRECATED, E_USER_DEPRECATED => 'notice',
                default => 'error',
            };
        }

        return 'error';
    }

    /**
     * 构建日志上下文
     */
    protected function buildLogContext(\Throwable $exception, string $mode): array
    {
        return [
            'exception' => get_class($exception),
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'mode' => $mode,
            'trace' => $exception->getTraceAsString(),
        ];
    }

    /**
     * 渲染 FPM 模式响应
     */
    protected function renderFpmResponse(\Throwable $exception): mixed
    {
        $statusCode = $this->getHttpStatusCode($exception);
        http_response_code($statusCode);

        $isDebug = ($_ENV['APP_DEBUG'] ?? 'false') === 'true';

        if ($isDebug) {
            return $this->renderDebugResponse($exception, $statusCode);
        }

        return $this->renderProductionResponse($statusCode);
    }

    /**
     * 渲染 CLI 模式响应
     */
    protected function renderCliResponse(\Throwable $exception): mixed
    {
        $statusCode = $this->getHttpStatusCode($exception);
        $isDebug = ($_ENV['APP_DEBUG'] ?? 'false') === 'true';

        $body = $isDebug
            ? json_encode([
                'error' => get_class($exception),
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ], JSON_UNESCAPED_UNICODE)
            : json_encode(['error' => 'Internal Server Error'], JSON_UNESCAPED_UNICODE);

        return new \React\Http\Message\Response(
            $statusCode,
            ['Content-Type' => 'application/json'],
            $body
        );
    }

    /**
     * 渲染 Shell 模式响应
     */
    protected function renderShellResponse(\Throwable $exception): string
    {
        $isDebug = ($_ENV['APP_DEBUG'] ?? 'false') === 'true';

        if ($isDebug) {
            return sprintf(
                "Error: %s\nMessage: %s\nFile: %s:%d\n%s",
                get_class($exception),
                $exception->getMessage(),
                $exception->getFile(),
                $exception->getLine(),
                $exception->getTraceAsString()
            );
        }

        return "Error: " . $exception->getMessage();
    }

    /**
     * 获取HTTP状态码
     */
    protected function getHttpStatusCode(\Throwable $exception): int
    {
        if ($exception instanceof \PHPFrame\Exceptions\HttpException) {
            return $exception->getStatusCode();
        }

        return 500;
    }

    /**
     * 渲染调试模式响应
     */
    protected function renderDebugResponse(\Throwable $exception, int $statusCode): array
    {
        return [
            'error' => get_class($exception),
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => explode("\n", $exception->getTraceAsString()),
            'status_code' => $statusCode,
        ];
    }

    /**
     * 渲染生产模式响应
     */
    protected function renderProductionResponse(int $statusCode): array
    {
        $messages = [
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            500 => 'Internal Server Error',
            502 => 'Bad Gateway',
            503 => 'Service Unavailable',
        ];

        return [
            'error' => $messages[$statusCode] ?? 'Internal Server Error',
            'status_code' => $statusCode,
        ];
    }
}
