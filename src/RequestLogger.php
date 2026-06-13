<?php

namespace PHPFrame;

/**
 * 请求日志记录器
 * 从 RouteManager 中提取的日志职责
 */
class RequestLogger
{
    /**
     * @var mixed 依赖注入容器
     */
    protected $container;

    public function __construct($container)
    {
        $this->container = $container;
    }

    /**
     * 统一的自动日志记录方法
     *
     * @param string $mode 运行模式 (fpm, cli, shell)
     * @param int $statusCode HTTP状态码
     * @param string $uri 请求URI或命令
     * @param float $requestStartTime 请求开始时间
     * @param \Exception|null $exception 异常对象
     * @param array $context 额外上下文信息
     * @param array $args 命令行参数（仅shell模式）
     */
    public function autoLog(string $mode, int $statusCode, string $uri, float $requestStartTime, \Exception $exception = null, array $context = [], array $args = []): void
    {
        try {
            if (!$this->container->has('logger')) {
                return;
            }

            $logger = $this->container->get('logger');

            $logLevel = $exception ? 'error' : ($statusCode >= 400 ? 'error' : 'info');
            list($clientIp, $serverIp, $userAgent, $httpMethod) = $this->getRequestInfo($mode, $context);
            $elapsedTime = round((microtime(true) - $requestStartTime) * 1000, 2);
            $requestData = $this->prepareRequestData($mode, $context, $args);

            $logger->writeLog(
                date('Y-m-d H:i:s'),
                $logLevel,
                $clientIp,
                $serverIp,
                $elapsedTime,
                $statusCode,
                $httpMethod,
                $uri,
                json_encode($requestData, JSON_UNESCAPED_UNICODE),
                $userAgent,
                $exception ? $exception->getMessage() : ""
            );
        } catch (\Exception $e) {
            // 静默处理，避免影响请求
        }
    }

    /**
     * 记录HTTP错误日志
     */
    public function logHttpError(int $statusCode, string $statusText, string $httpMethod, string $uri, string $mode, array $context = []): void
    {
        try {
            if ($this->container->has('logger')) {
                $logger = $this->container->get('logger');

                $level = 'warning';
                if ($statusCode >= 500) {
                    $level = 'error';
                } elseif ($statusCode >= 400) {
                    $level = 'warning';
                } elseif ($statusCode >= 300) {
                    $level = 'info';
                }

                $message = sprintf('%s %s - %d %s', $httpMethod, $uri, $statusCode, $statusText);

                $logContext = array_merge([
                    'mode' => $mode,
                    'status_code' => $statusCode,
                    'http_method' => $httpMethod,
                    'uri' => $uri,
                    'timestamp' => date('Y-m-d H:i:s')
                ], $context);

                $logger->log($level, $message, $logContext);
            }
        } catch (\Exception $e) {
            // 静默处理
        }
    }

    /**
     * 获取请求信息
     * 优先从 context 中获取，FPM 模式回退到 Request 对象
     */
    public function getRequestInfo(string $mode, array $context): array
    {
        switch ($mode) {
            case 'fpm':
                // 优先使用 context 中传入的数据，避免直接访问超全局变量
                if (!empty($context)) {
                    return [
                        $context['client_ip'] ?? '127.0.0.1',
                        $context['server_ip'] ?? '127.0.0.1',
                        $context['user_agent'] ?? '',
                        $context['http_method'] ?? 'GET'
                    ];
                }
                // 回退：尝试从 Request 对象获取
                try {
                    if ($this->container->has('request')) {
                        $request = $this->container->get('request');
                        $server = $request->server();
                        return [
                            $server['REMOTE_ADDR'] ?? '127.0.0.1',
                            $server['SERVER_ADDR'] ?? '127.0.0.1',
                            $server['HTTP_USER_AGENT'] ?? '',
                            $server['REQUEST_METHOD'] ?? 'GET'
                        ];
                    }
                } catch (\Exception $e) {}
                return ['127.0.0.1', '127.0.0.1', '', 'GET'];

            case 'cli':
                return [
                    $context['client_ip'] ?? '127.0.0.1',
                    $context['server_ip'] ?? '127.0.0.1',
                    $context['user_agent'] ?? '',
                    $context['http_method'] ?? 'GET'
                ];

            case 'shell':
                return ['127.0.0.1', '127.0.0.1', '', 'SHELL'];

            default:
                return ['127.0.0.1', '127.0.0.1', '', 'GET'];
        }
    }

    /**
     * 准备请求数据
     * 优先从 context 中获取，FPM 模式回退到 Request 对象
     */
    public function prepareRequestData(string $mode, array $context, array $args): array
    {
        switch ($mode) {
            case 'fpm':
                // 优先使用 context 中传入的数据
                if (isset($context['request_data'])) {
                    return $context['request_data'];
                }
                // 回退：从 Request 对象获取
                try {
                    if ($this->container->has('request')) {
                        $request = $this->container->get('request');
                        return [
                            'query' => $request->query(),
                            'post' => $request->post(),
                            'json' => $this->getJsonRequestBodyFromRequest($request),
                            'files' => $request->files()
                        ];
                    }
                } catch (\Exception $e) {}
                return ['query' => [], 'post' => [], 'json' => [], 'files' => []];

            case 'cli':
                return $context['request_data'] ?? [
                    'query' => [],
                    'post' => [],
                    'json' => [],
                    'files' => []
                ];

            case 'shell':
                return ['args' => $args];

            default:
                return [];
        }
    }

    /**
     * 从 Request 对象获取 JSON 请求体
     */
    protected function getJsonRequestBodyFromRequest(Request $request): array
    {
        $contentType = $request->server('CONTENT_TYPE', '');
        if (strpos($contentType, 'application/json') !== false) {
            return $request->getJsonBody() ?: [];
        }
        return [];
    }
}
