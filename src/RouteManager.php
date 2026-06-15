<?php

namespace PHPFrame;

use FastRoute\Dispatcher;
use FastRoute\RouteCollector;
use Psr\Http\Message\ServerRequestInterface;
use React\Promise\Promise;
use React\Http\Message\Response as ReactResponse;
use PHPFrame\Middleware\MiddlewarePipeline;

/**
 * 统一路由管理器
 * 负责处理FPM、CLI、Shell三种模式的路由逻辑
 *
 * 内部委托：
 * - RequestLogger: 请求日志记录
 * - ResponseFactory: 响应创建
 * - MiddlewarePipeline: 中间件管道
 */
class RouteManager
{
    protected $dispatcher;
    protected $container;

    /**
     * 请求日志记录器
     */
    protected RequestLogger $requestLogger;

    /**
     * 响应工厂
     */
    protected ResponseFactory $responseFactory;

    /**
     * 全局中间件列表
     * @var Middleware\MiddlewareInterface[]
     */
    protected array $globalMiddlewares = [];

    /**
     * 路由级中间件映射
     * @var array<string, Middleware\MiddlewareInterface>
     */
    protected array $routeMiddlewares = [];

    /**
     * 路由处理器对应的中间件列表
     * key 为 handler 字符串（如 "Controller@action"），value 为中间件名称数组
     * @var array<string, string[]>
     */
    protected array $handlerMiddlewareMap = [];

    /**
     * 构造函数（签名不变，向后兼容）
     *
     * @param Dispatcher $dispatcher 路由调度器
     * @param mixed $container 依赖注入容器
     */
    public function __construct(Dispatcher $dispatcher, $container)
    {
        $this->dispatcher = $dispatcher;
        $this->container = $container;
        $this->requestLogger = new RequestLogger($container);
        $this->responseFactory = new ResponseFactory();
    }

    /**
     * 注册全局中间件
     * 新增方法，不影响现有代码
     */
    public function middleware(Middleware\MiddlewareInterface $middleware): static
    {
        $this->globalMiddlewares[] = $middleware;
        return $this;
    }

    /**
     * 批量注册全局中间件
     * 新增方法，不影响现有代码
     */
    public function middlewares(array $middlewares): static
    {
        foreach ($middlewares as $middleware) {
            $this->middleware($middleware);
        }
        return $this;
    }

    /**
     * 注册路由级中间件别名
     * 新增方法，不影响现有代码
     */
    public function registerMiddleware(string $name, Middleware\MiddlewareInterface $middleware): static
    {
        $this->routeMiddlewares[$name] = $middleware;
        return $this;
    }

    /**
     * 获取路由级中间件
     * 新增方法，不影响现有代码
     */
    public function getRouteMiddleware(string $name): ?Middleware\MiddlewareInterface
    {
        return $this->routeMiddlewares[$name] ?? null;
    }

    /**
     * 为路由处理器绑定中间件
     * @param string $handler 处理器标识（如 "Controller@action"）
     * @param string[] $middlewareNames 中间件别名列表
     */
    public function handlerMiddleware(string $handler, array $middlewareNames): static
    {
        $this->handlerMiddlewareMap[$handler] = $middlewareNames;
        return $this;
    }

    /**
     * 获取指定处理器应执行的中间件列表
     * @param mixed $handler
     * @return Middleware\MiddlewareInterface[]
     */
    protected function resolveMiddlewaresForHandler($handler): array
    {
        $middlewares = $this->globalMiddlewares;

        $handlerKey = null;
        if (is_array($handler)) {
            $handlerKey = $handler[0] . '@' . $handler[1];
        } elseif (is_string($handler) && strpos($handler, '@') !== false) {
            $handlerKey = $handler;
        }

        if ($handlerKey && isset($this->handlerMiddlewareMap[$handlerKey])) {
            foreach ($this->handlerMiddlewareMap[$handlerKey] as $name) {
                if (isset($this->routeMiddlewares[$name])) {
                    $middlewares[] = $this->routeMiddlewares[$name];
                }
            }
        }

        return $middlewares;
    }

    // ─── FPM 模式 ───

    /**
     * 处理FPM模式路由请求（签名不变）
     */
    public function handleFpmRequest($httpMethod, $uri, $app, float $requestStartTime = null)
    {
        if ($requestStartTime === null) {
            $requestStartTime = microtime(true);
        }

        $routeInfo = $this->dispatcher->dispatch($httpMethod, $uri);

        switch ($routeInfo[0]) {
            case Dispatcher::NOT_FOUND:
                http_response_code(404);
                $this->requestLogger->autoLog('fpm', 404, $uri, $requestStartTime);
                return "404 Not Found";

            case Dispatcher::METHOD_NOT_ALLOWED:
                http_response_code(405);
                $this->requestLogger->autoLog('fpm', 405, $uri, $requestStartTime);
                return "405 Method Not Allowed";

            case Dispatcher::FOUND:
                $handler = $routeInfo[1];
                $vars = $routeInfo[2];

                try {
                    $result = $this->executeWithMiddleware($handler, $vars, $app, 'fpm');
                    $this->requestLogger->autoLog('fpm', 200, $uri, $requestStartTime);
                    return $result;
                } catch (\Exception $e) {
                    $this->requestLogger->autoLog('fpm', 500, $uri, $requestStartTime, $e);
                    throw $e;
                }
        }

        return null;
    }

    // ─── CLI 模式 ───

    /**
     * 处理CLI模式路由请求（签名不变）
     */
    public function handleCliRequest(ServerRequestInterface $request)
    {
        $requestStartTime = microtime(true);
        $httpMethod = $request->getMethod();
        $uri = $request->getUri()->getPath();
        $clientIp = $request->getServerParams()['REMOTE_ADDR'] ?? '127.0.0.1';
        $serverIp = $request->getServerParams()['SERVER_ADDR'] ?? '127.0.0.1';
        $userAgent = $request->getHeaderLine('User-Agent');

        $logContext = [
            'client_ip' => $clientIp,
            'server_ip' => $serverIp,
            'user_agent' => $userAgent,
            'http_method' => $httpMethod
        ];

        return new Promise(function ($resolve, $reject) use ($request, $requestStartTime, $httpMethod, $uri, $logContext) {
            try {
                // 检查是否为静态文件请求
                if ($this->responseFactory->isStaticFileRequest($uri)) {
                    $resolve($this->responseFactory->serveStaticFile($uri));
                    return;
                }

                $routeInfo = $this->dispatcher->dispatch($httpMethod, $uri);

                switch ($routeInfo[0]) {
                    case Dispatcher::NOT_FOUND:
                        $this->requestLogger->autoLog('cli', 404, $uri, $requestStartTime, null, $logContext);
                        $resolve(new ReactResponse(404, ['Content-Type' => 'text/plain'], "404 Not Found"));
                        break;

                    case Dispatcher::METHOD_NOT_ALLOWED:
                        $allowedMethods = $routeInfo[1];
                        $this->requestLogger->autoLog('cli', 405, $uri, $requestStartTime, null, $logContext);
                        $resolve(new ReactResponse(
                            405,
                            ['Content-Type' => 'text/plain', 'Allow' => implode(', ', $allowedMethods)],
                            "405 Method Not Allowed"
                        ));
                        break;

                    case Dispatcher::FOUND:
                        $handler = $routeInfo[1];
                        $vars = $routeInfo[2];

                        $result = $this->executeWithMiddleware($handler, $vars, $this->container, 'cli', $request);

                        if ($result instanceof ReactResponse) {
                            $this->requestLogger->autoLog('cli', $result->getStatusCode(), $uri, $requestStartTime, null, $logContext);
                            $resolve($result);
                        } else {
                            $this->requestLogger->autoLog('cli', 200, $uri, $requestStartTime, null, $logContext);
                            $resolve($this->responseFactory->createResponse($result));
                        }
                        break;
                }
            } catch (\Throwable $e) {
                $this->requestLogger->autoLog('cli', 500, $uri, $requestStartTime, $e, $logContext);

                try {
                    $container = $this->container;
                    $exceptionHandlerClass = config('exception.handler');
                    if ($exceptionHandlerClass && $container->has($exceptionHandlerClass)) {
                        $exceptionHandler = $container->get($exceptionHandlerClass);
                        $response = $exceptionHandler->handle($e, 'cli');
                        if ($response instanceof ReactResponse) {
                            $resolve($response);
                        } else {
                            $resolve($this->responseFactory->createResponse($response));
                        }
                    } else {
                        $resolve(new ReactResponse(500, ['Content-Type' => 'text/plain'], "Internal Server Error: " . $e->getMessage()));
                    }
                } catch (\Throwable $handlerException) {
                    $resolve(new ReactResponse(500, ['Content-Type' => 'text/plain'], "Internal Server Error"));
                }
            }
        });
    }

    // ─── Shell 模式 ───

    /**
     * 处理Shell模式路由请求（签名不变）
     */
    public function handleShellRequest($command, $args = [])
    {
        $requestStartTime = microtime(true);

        try {
            $routeInfo = $this->dispatcher->dispatch('SHELL', $command);

            switch ($routeInfo[0]) {
                case Dispatcher::NOT_FOUND:
                    echo "Shell路由不存在: {$command}\n";
                    $this->requestLogger->autoLog('shell', 404, $command, $requestStartTime, null, [], $args);
                    return false;

                case Dispatcher::METHOD_NOT_ALLOWED:
                    echo "Shell路由方法不允许: {$command}\n";
                    $this->requestLogger->autoLog('shell', 405, $command, $requestStartTime, null, [], $args);
                    return false;

                case Dispatcher::FOUND:
                    $handler = $routeInfo[1];
                    $vars = $routeInfo[2];

                    $result = $this->executeHandler($handler, $vars, $this->container, 'shell', null, $args);

                    if ($result !== null) {
                        if (is_array($result) || is_object($result)) {
                            print_r($result);
                        } else {
                            echo $result . "\n";
                        }
                    }

                    $this->requestLogger->autoLog('shell', 0, $command, $requestStartTime, null, [], $args);
                    return true;
            }
        } catch (\Exception $e) {
            $this->requestLogger->autoLog('shell', 500, $command, $requestStartTime, $e, [], $args);

            try {
                $container = $this->container;
                $exceptionHandlerClass = config('exception.handler');
                if ($exceptionHandlerClass && $container->has($exceptionHandlerClass)) {
                    $handler = $container->get($exceptionHandlerClass);
                    $response = $handler->handle($e, 'shell');
                    echo $response . "\n";
                } else {
                    echo "执行命令失败: " . $e->getMessage() . "\n";
                    if ($_ENV['APP_DEBUG'] ?? false) {
                        echo "堆栈跟踪:\n";
                        echo $e->getTraceAsString() . "\n";
                    }
                }
            } catch (\Exception $handlerException) {
                echo "Internal Server Error\n";
            }

            return false;
        }

        return false;
    }

    // ─── 处理器执行 ───

    /**
     * 通过中间件管道执行处理器
     * 如果没有注册中间件，行为与原 executeHandler 完全一致
     */
    protected function executeWithMiddleware($handler, array $vars, $container, $mode = 'fpm', ServerRequestInterface $request = null, array $shellArgs = [])
    {
        $middlewares = $this->resolveMiddlewaresForHandler($handler);

        // 无中间件时直接执行（零开销，完全兼容旧代码）
        if (empty($middlewares)) {
            return $this->executeHandler($handler, $vars, $container, $mode, $request, $shellArgs);
        }

        // 构建中间件管道
        $pipeline = new MiddlewarePipeline();
        foreach ($middlewares as $middleware) {
            $pipeline->pipe($middleware);
        }

        return $pipeline->process($request, function ($req) use ($handler, $vars, $container, $mode, $request, $shellArgs) {
            return $this->executeHandler($handler, $vars, $container, $mode, $request, $shellArgs);
        });
    }

    /**
     * 执行路由处理器（逻辑不变）
     */
    protected function executeHandler($handler, array $vars, $container, $mode = 'fpm', ServerRequestInterface $request = null, array $shellArgs = [])
    {
        if ($mode === 'shell' && !empty($shellArgs)) {
            $shellArgs = $this->parseArgs($shellArgs);
            if ($shellArgs && count($shellArgs) > 0) {
                $vars = array_merge($vars, $shellArgs);
            }
        }

        if (is_callable($handler)) {
            return call_user_func_array($handler, $vars);
        }

        if (is_array($handler)) {
            list($controllerClass, $actionMethod) = $handler;

            if (class_exists($controllerClass)) {
                $controllerInstance = $container->get($controllerClass);
                $this->setRequestParams($controllerInstance, $vars, $mode, $request);

                if (method_exists($controllerInstance, $actionMethod)) {
                    if (method_exists($controllerInstance, 'before')) {
                        $controllerInstance->before();
                    }

                    return $controllerInstance->$actionMethod();
                } else {
                    throw new \RuntimeException("Action not found: {$actionMethod}");
                }
            } else {
                throw new \RuntimeException("Controller not found: {$controllerClass}");
            }
        } elseif (is_string($handler) && strpos($handler, '@') !== false) {
            list($controllerPath, $actionMethod) = explode('@', $handler);

            $controllerClass = str_replace('/', '\\', $controllerPath);
            if (class_exists($controllerClass)) {
                $controllerInstance = $container->get($controllerClass);
                $this->setRequestParams($controllerInstance, $vars, $mode, $request);

                if (method_exists($controllerInstance, $actionMethod)) {
                    if (method_exists($controllerInstance, 'before')) {
                        $controllerInstance->before();
                    }

                    return $controllerInstance->$actionMethod();
                } else {
                    throw new \RuntimeException("Action not found: {$actionMethod}");
                }
            } else {
                throw new \RuntimeException("Controller not found: {$controllerClass}");
            }
        } else {
            return $handler;
        }
    }

    // ─── 参数设置（逻辑不变） ───

    protected function setRequestParams($controller, array $routeParams, $mode, ServerRequestInterface $request = null, array $shellArgs = [])
    {
        if ($controller instanceof BaseController) {
            if ($mode === 'fpm') {
                $controller->setFpmParams($routeParams);
            } elseif ($mode === 'cli' && $request !== null) {
                $this->setCliRequestParams($controller, $request, $routeParams);
            }
        }
        if ($controller instanceof BaseShell && $mode === 'shell') {
            $controller->setShellParams($routeParams);
        }
    }

    protected function setCliRequestParams(BaseController $controller, ServerRequestInterface $request, array $routeParams)
    {
        $queryParams = $request->getQueryParams();

        $parsedBody = $request->getParsedBody();
        if (!is_array($parsedBody)) {
            $parsedBody = [];
        }

        $contentType = $request->getHeaderLine('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $body = (string)$request->getBody();
            if (!empty($body)) {
                $jsonParams = json_decode($body, true);
                if (is_array($jsonParams) && json_last_error() === JSON_ERROR_NONE) {
                    $parsedBody = array_merge($parsedBody, $jsonParams);
                }
            }
        }

        $uploadedFiles = $request->getUploadedFiles();
        $filesParams = [];

        if (!empty($uploadedFiles)) {
            foreach ($uploadedFiles as $fieldName => $uploadedFile) {
                if (is_array($uploadedFile)) {
                    $filesParams[$fieldName] = [
                        'name' => [],
                        'type' => [],
                        'tmp_name' => [],
                        'error' => [],
                        'size' => []
                    ];

                    foreach ($uploadedFile as $file) {
                        $filesParams[$fieldName]['name'][] = $file->getClientFilename();
                        $filesParams[$fieldName]['type'][] = $file->getClientMediaType();
                        $filesParams[$fieldName]['tmp_name'][] = $this->saveUploadedFileToTemp($file);
                        $filesParams[$fieldName]['error'][] = UPLOAD_ERR_OK;
                        $filesParams[$fieldName]['size'][] = $file->getSize();
                    }
                } else {
                    $filesParams[$fieldName] = [
                        'name' => $uploadedFile->getClientFilename(),
                        'type' => $uploadedFile->getClientMediaType(),
                        'tmp_name' => $this->saveUploadedFileToTemp($uploadedFile),
                        'error' => UPLOAD_ERR_OK,
                        'size' => $uploadedFile->getSize()
                    ];
                }
            }
        }

        $allParams = array_merge($queryParams, $parsedBody, $routeParams);

        $headers = $request->getHeaders();
        $authorizationHeader = null;
        foreach ($headers as $name => $values) {
            if (strtolower($name) === 'authorization') {
                $authorizationHeader = $values[0];
                break;
            }
        }

        if ($authorizationHeader !== null) {
            $allParams['__authorization__'] = $authorizationHeader;
            if (preg_match('/Bearer\s+(.+)$/i', $authorizationHeader, $matches)) {
                $allParams['__bearer_token__'] = $matches[1];
            }
        }

        if (isset($headers['content-type'])) {
            $allParams['__content_type__'] = $headers['content-type'][0];
        }

        if (!empty($filesParams)) {
            $allParams['__uploaded_files__'] = $filesParams;
        }

        $allParams['__method__'] = $httpMethod = $request->getMethod();
        $allParams['__uri__'] = $request->getUri()->getPath();

        $controller->setCliParams($allParams);
    }

    protected function saveUploadedFileToTemp($uploadedFile): string
    {
        $originalFilename = $uploadedFile->getClientFilename();
        $extension = pathinfo($originalFilename, PATHINFO_EXTENSION);

        $tempPrefix = 'upload_';
        if ($extension) {
            $tempFile = tempnam(sys_get_temp_dir(), $tempPrefix) . '.' . $extension;
        } else {
            $tempFile = tempnam(sys_get_temp_dir(), $tempPrefix);
        }

        $uploadedFile->moveTo($tempFile);
        return $tempFile;
    }

    protected function parseArgs(array $args): array
    {
        $params = [];

        foreach ($args as $arg) {
            if (strpos($arg, '=') !== false) {
                list($key, $value) = explode('=', $arg, 2);
                $params[$key] = $value;
            } else {
                $params[] = $arg;
            }
        }

        return $params;
    }

    // ─── 兼容旧版：保留 autoLog / getRequestInfo / prepareRequestData 作为委托方法 ───
    // 如果有子类覆写了这些方法，仍然可以正常工作

    protected function autoLog(string $mode, int $statusCode, string $uri, float $requestStartTime, \Exception $exception = null, array $context = [], array $args = []): void
    {
        $this->requestLogger->autoLog($mode, $statusCode, $uri, $requestStartTime, $exception, $context, $args);
    }

    protected function getRequestInfo(string $mode, array $context): array
    {
        return $this->requestLogger->getRequestInfo($mode, $context);
    }

    protected function prepareRequestData(string $mode, array $context, array $args): array
    {
        return $this->requestLogger->prepareRequestData($mode, $context, $args);
    }

    protected function logHttpError(int $statusCode, string $statusText, string $httpMethod, string $uri, string $mode, array $context = []): void
    {
        $this->requestLogger->logHttpError($statusCode, $statusText, $httpMethod, $uri, $mode, $context);
    }

    // ─── 访问器 ───

    public function getRequestLogger(): RequestLogger
    {
        return $this->requestLogger;
    }

    public function getResponseFactory(): ResponseFactory
    {
        return $this->responseFactory;
    }
}
