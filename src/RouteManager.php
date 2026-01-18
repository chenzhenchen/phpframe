<?php

namespace PHPFrame;

use FastRoute\Dispatcher;
use FastRoute\RouteCollector;
use Psr\Http\Message\ServerRequestInterface;
use React\Promise\Promise;
use React\Http\Message\Response as ReactResponse;
use DI\Container;
use PHPFrame\Facades\Config;

/**
 * 统一路由管理器
 * 负责处理FPM、CLI、Shell三种模式的路由逻辑
 */
class RouteManager
{
    /**
     * @var Dispatcher FastRoute调度器
     */
    protected $dispatcher;
    
    /**
     * @var Container 依赖注入容器
     */
    protected $container;
    
    /**
     * 静态文件扩展名
     * @var array
     */
    protected $staticExtensions = ['css', 'js', 'png', 'jpg', 'jpeg', 'gif', 'ico', 'svg', 'woff', 'woff2', 'ttf', 'eot'];
    
    /**
     * 构造函数
     *
     * @param Dispatcher $dispatcher 路由调度器
     * @param mixed $container 依赖注入容器（支持DI\Container或PSR-11兼容容器）
     */
    public function __construct(Dispatcher $dispatcher, $container)
    {
        $this->dispatcher = $dispatcher;
        $this->container = $container;
    }
    
    /**
     * 处理FPM模式路由请求
     *
     * @param string $httpMethod HTTP方法
     * @param string $uri 请求URI
     * @param mixed $app 应用容器（支持DI\Container或PSR-11兼容容器）
     * @param float $requestStartTime 请求开始时间
     * @return mixed
     */
    public function handleFpmRequest($httpMethod, $uri, $app, float $requestStartTime = null)
    {
        if ($requestStartTime === null) {
            $requestStartTime = microtime(true);
        }
        
        // 路由匹配
        $routeInfo = $this->dispatcher->dispatch($httpMethod, $uri);
        
        switch ($routeInfo[0]) {
            case Dispatcher::NOT_FOUND:
                // 404处理 - 记录日志
                http_response_code(404);
                $this->autoLog('fpm', 404, $uri, $requestStartTime);
                return "404 Not Found";
                
            case Dispatcher::METHOD_NOT_ALLOWED:
                // 405处理 - 记录日志
                http_response_code(405);
                $this->autoLog('fpm', 405, $uri, $requestStartTime);
                return "405 Method Not Allowed";
                
            case Dispatcher::FOUND:
                $handler = $routeInfo[1];
                $vars = $routeInfo[2];
                
                try {
                    $result = $this->executeHandler($handler, $vars, $app, 'fpm');
                    $this->autoLog('fpm', 200, $uri, $requestStartTime);
                    return $result;
                } catch (\Exception $e) {
                    $this->autoLog('fpm', 500, $uri, $requestStartTime, $e);
                    throw $e;
                }
        }
        
        return null;
    }
    
    /**
     * 处理CLI模式路由请求
     *
     * @param ServerRequestInterface $request 请求对象
     * @return Promise
     */
    public function handleCliRequest(ServerRequestInterface $request)
    {
        $requestStartTime = microtime(true);
        $httpMethod = $request->getMethod();
        $uri = $request->getUri()->getPath();
        $clientIp = $request->getServerParams()['REMOTE_ADDR'] ?? '127.0.0.1';
        $serverIp = $request->getServerParams()['SERVER_ADDR'] ?? '127.0.0.1';
        $userAgent = $request->getHeaderLine('User-Agent');

        return new Promise(function ($resolve, $reject) use ($request, $requestStartTime, $httpMethod, $uri, $clientIp, $serverIp, $userAgent) {
            try {
                // 检查是否为静态文件请求
                if ($this->isStaticFileRequest($uri)) {
                    $resolve($this->serveStaticFile($uri));
                    return;
                }

                // 路由匹配
                $routeInfo = $this->dispatcher->dispatch($httpMethod, $uri);

                switch ($routeInfo[0]) {
                    case Dispatcher::NOT_FOUND:
                        // 404处理 - 记录日志
                        $this->autoLog('cli', 404, $uri, $requestStartTime, null, [
                            'client_ip' => $clientIp,
                            'server_ip' => $serverIp,
                            'user_agent' => $userAgent,
                            'http_method' => $httpMethod
                        ]);
                        $resolve(new ReactResponse(404, ['Content-Type' => 'text/plain'], "404 Not Found"));
                        break;

                    case Dispatcher::METHOD_NOT_ALLOWED:
                        // 405处理 - 记录日志
                        $allowedMethods = $routeInfo[1];
                        $this->autoLog('cli', 405, $uri, $requestStartTime, null, [
                            'client_ip' => $clientIp,
                            'server_ip' => $serverIp,
                            'user_agent' => $userAgent,
                            'http_method' => $httpMethod,
                            'allowed_methods' => $allowedMethods
                        ]);
                        $resolve(new ReactResponse(
                            405,
                            ['Content-Type' => 'text/plain', 'Allow' => implode(', ', $allowedMethods)],
                            "405 Method Not Allowed"
                        ));
                        break;

                    case Dispatcher::FOUND:
                        $handler = $routeInfo[1];
                        $vars = $routeInfo[2];

                        $result = $this->executeHandler($handler, $vars, $this->container, 'cli', $request);

                        if ($result instanceof ReactResponse) {
                            $this->autoLog('cli', $result->getStatusCode(), $uri, $requestStartTime, null, [
                                'client_ip' => $clientIp,
                                'server_ip' => $serverIp,
                                'user_agent' => $userAgent,
                                'http_method' => $httpMethod
                            ]);
                            $resolve($result);
                        } else {
                            $this->autoLog('cli', 200, $uri, $requestStartTime, null, [
                                'client_ip' => $clientIp,
                                'server_ip' => $serverIp,
                                'user_agent' => $userAgent,
                                'http_method' => $httpMethod
                            ]);
                            $resolve($this->createResponse($result));
                        }
                        break;
                }
            } catch (\Exception $e) {
                // 记录错误日志
                $this->autoLog('cli', 500, $uri, $requestStartTime, $e, [
                    'client_ip' => $clientIp,
                    'server_ip' => $serverIp,
                    'user_agent' => $userAgent,
                    'http_method' => $httpMethod
                ]);

                // 异常处理 - 使用框架的异常处理器
                try {
                    $container = $this->container;
                    if ($exceptionHandler = $container->get(config('exception.handler'))) {
                        $response = $exceptionHandler->handle($e, 'cli');

                        if ($response instanceof Response) {
                            $resolve($response);
                        } else {
                            $resolve($this->createResponse($response));
                        }
                    } else {
                        $resolve(new ReactResponse(500, ['Content-Type' => 'text/plain'], "Internal Server Error: " . $e->getMessage()));
                    }
                } catch (\Exception $handlerException) {
                    $resolve(new ReactResponse(500, ['Content-Type' => 'text/plain'], "Internal Server Error"));
                }
            }
        });
    }

    /**
     * 处理Shell模式路由请求
     *
     * @param string $command Shell命令
     * @param array $args 命令参数
     * @return bool
     */
    public function handleShellRequest($command, $args = [])
    {
        $requestStartTime = microtime(true);

        try {
            // 路由匹配
            $routeInfo = $this->dispatcher->dispatch('SHELL', $command);

            switch ($routeInfo[0]) {
                case Dispatcher::NOT_FOUND:
                    echo "Shell路由不存在: {$command}\n";
                    $this->autoLog('shell', 404, $command, $requestStartTime, null, [], $args);
                    return false;

                case Dispatcher::METHOD_NOT_ALLOWED:
                    echo "Shell路由方法不允许: {$command}\n";
                    $this->autoLog('shell', 405, $command, $requestStartTime, null, [], $args);
                    return false;

                case Dispatcher::FOUND:
                    $handler = $routeInfo[1];
                    $vars = $routeInfo[2];

                    // 执行处理器
                    $result = $this->executeHandler($handler, $vars, $this->container, 'shell', null, $args);

                    // 处理结果输出
                    if ($result !== null) {
                        if (is_array($result) || is_object($result)) {
                            print_r($result);
                        } else {
                            echo $result . "\n";
                        }
                    }

                    $this->autoLog('shell', 0, $command, $requestStartTime, null, [], $args);
                    return true;
            }
        } catch (\Exception $e) {
            // 先设置requestData，确保后续日志记录能获取正确的mode
            $this->autoLog('shell', 500, $command, $requestStartTime, $e, [], $args);

            // 异常处理 - 使用框架的异常处理器
            try {
                $container = $this->container;
                if ($exceptionHandler = config('exception.handler')) {
                    if ($container->has($exceptionHandler)) {
                        $handler = $container->get($exceptionHandler);
                        $response = $handler->handle($e, 'shell');
                        echo $response . "\n";
                    } else {
                        echo "执行命令失败: " . $e->getMessage() . "\n";
                    }
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

    /**
     * 解析Shell命令参数
     *
     * @param array $args 原始参数
     * @return array
     */
    protected function parseShellArgs(array $args)
    {
        $positional = [];
        $named = [];

        foreach ($args as $arg) {
            if (strpos($arg, '=') !== false) {
                list($key, $value) = explode('=', $arg, 2);
                $named[$key] = $value;
            } else {
                $positional[] = $arg;
            }
        }

        return [
            'args' => $positional,
            'options' => $named
        ];
    }

    /**
     * 统一的自动日志记录方法
     *
     * @param string $mode 运行模式 (fpm, cli, shell)
     * @param int $statusCode HTTP状态码
     * @param string $uri 请求URI或命令
     * @param float $requestStartTime 请求开始时间
     * @param \Exception|null $exception 异常对象（如果有）
     * @param array $context 额外上下文信息
     * @param array $args 命令行参数（仅shell模式使用）
     */
    protected function autoLog(string $mode, int $statusCode, string $uri, float $requestStartTime, \Exception $exception = null, array $context = [], array $args = []): void
    {
        try {
            $container = $this->container;
            if (!$container->has('logger')) {
                return;
            }
            
            $logger = $container->get('logger');
            
            // 确定日志级别
            $logLevel = $exception ? 'error' : ($statusCode >= 400 ? 'error' : 'info');
            
            // 获取基础信息
            list($clientIp, $serverIp, $userAgent, $httpMethod) = $this->getRequestInfo($mode, $context);
            
            // 计算响应时间
            $elapsedTime = round((microtime(true) - $requestStartTime) * 1000, 2);
            
            // 准备请求数据
            $requestData = $this->prepareRequestData($mode, $context, $args);
            
            // 记录日志
            $logger->writeLogLine(
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
     * 获取请求信息
     *
     * @param string $mode 运行模式
     * @param array $context 上下文信息
     * @return array [clientIp, serverIp, userAgent, httpMethod]
     */
    protected function getRequestInfo(string $mode, array $context): array
    {
        switch ($mode) {
            case 'fpm':
                return [
                    $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                    $_SERVER['SERVER_ADDR'] ?? '127.0.0.1',
                    $_SERVER['HTTP_USER_AGENT'] ?? '',
                    $_SERVER['REQUEST_METHOD'] ?? 'GET'
                ];
                
            case 'cli':
                return [
                    $context['client_ip'] ?? '127.0.0.1',
                    $context['server_ip'] ?? '127.0.0.1',
                    $context['user_agent'] ?? '',
                    $context['http_method'] ?? 'GET'
                ];
                
            case 'shell':
                return [
                    '127.0.0.1',
                    '127.0.0.1',
                    'shell',
                    'SHELL'
                ];
                
            default:
                return ['127.0.0.1', '127.0.0.1', '', 'GET'];
        }
    }

    /**
     * 准备请求数据
     *
     * @param string $mode 运行模式
     * @param array $context 上下文信息
     * @param array $args 命令行参数
     * @return array
     */
    protected function prepareRequestData(string $mode, array $context, array $args): array
    {
        switch ($mode) {
            case 'fpm':
                return [
                    'query' => $_GET ?? [],
                    'post' => $_POST ?? [],
                    'json' => $this->getJsonRequestBody(),
                    'files' => $_FILES ?? []
                ];
                
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
     * 准备FPM请求数据
     *
     * @return array
     */
    protected function prepareFpmRequestData(): array
    {
        return [
            'query' => $_GET ?? [],
            'post' => $_POST ?? [],
            'json' => $this->getJsonRequestBody(),
            'files' => $_FILES ?? [],
        ];
    }

    /**
     * 获取JSON请求体
     *
     * @return array
     */
    protected function getJsonRequestBody(): array
    {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        
        if (strpos($contentType, 'application/json') !== false) {
            $jsonInput = file_get_contents('php://input');
            $jsonData = json_decode($jsonInput, true);
            
            if (json_last_error() === JSON_ERROR_NONE && is_array($jsonData)) {
                return $jsonData;
            }
        }
        
        return [];
    }

    /**
     * 准备CLI请求数据
     *
     * @param mixed $container 容器
     * @param array $context 上下文
     * @return array
     */
    protected function prepareCliRequestData($container, array $context = []): array
    {
        return [
            'query' => [],
            'post' => [],
            'json' => [],
            'files' => [],
        ];
    }
    
    /**
     * 执行路由处理器
     *
     * @param mixed $handler 处理器（闭包、控制器方法等）
     * @param array $vars 路由参数
     * @param mixed $container 容器（支持DI\Container或PSR-11兼容容器）
     * @param string $mode 运行模式
     * @param ServerRequestInterface|null $request CLI模式请求对象
     * @param array $shellArgs Shell模式命令行参数
     * @return mixed
     */
    protected function executeHandler($handler, array $vars, $container, $mode = 'fpm', ServerRequestInterface $request = null, array $shellArgs = [])
    {
        if ($mode === 'shell' && !empty($shellArgs)) {
            $vars = array_merge($vars, $shellArgs);
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
                    $reflectionMethod = new \ReflectionMethod($controllerInstance, $actionMethod);
                    $parameters = $reflectionMethod->getParameters();
                    if (method_exists($controllerInstance, 'before')) {
                        try {
                            $controllerInstance->before();
                        } catch (\Exception $e) {
                            // 处理异常，例如记录日志或返回错误响应
                        }
                    }
                    if (count($parameters) > 0) {
                        $firstParam = $parameters[0];
                        $firstParamType = $firstParam->getType();
                        $firstParamName = $firstParam->getName();

                        if (($firstParamType && $firstParamType->getName() === 'array' && !$firstParam->isVariadic())
                            || ($mode === 'shell' && in_array($firstParamName, ['args', 'params', 'arguments', 'options']))) {
                            return $reflectionMethod->invoke($controllerInstance, $vars);
                        }

                        return $reflectionMethod->invokeArgs($controllerInstance, $vars);
                    } else {
                        return $controllerInstance->$actionMethod();
                    }
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
                    $reflectionMethod = new \ReflectionMethod($controllerInstance, $actionMethod);
                    $parameters = $reflectionMethod->getParameters();
                    if (method_exists($controllerInstance, 'before')) {
                        try {
                            $controllerInstance->before();
                        } catch (\Exception $e) {
                            // 处理异常，例如记录日志或返回错误响应
                        }
                    }
                    if (count($parameters) > 0) {
                        $firstParam = $parameters[0];
                        $firstParamType = $firstParam->getType();
                        $firstParamName = $firstParam->getName();

                        if (($firstParamType && $firstParamType->getName() === 'array' && !$firstParam->isVariadic())
                            || ($mode === 'shell' && in_array($firstParamName, ['args', 'params', 'arguments', 'options']))) {
                            return $reflectionMethod->invoke($controllerInstance, $vars);
                        }

                        return $reflectionMethod->invokeArgs($controllerInstance, $vars);
                    } else {
                        return $controllerInstance->$actionMethod();
                    }
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
    
    /**
     * 设置控制器请求参数
     *
     * @param mixed $controller 控制器实例
     * @param array $routeParams 路由参数
     * @param string $mode 运行模式
     * @param ServerRequestInterface|null $request CLI模式请求对象
     * @param array $shellArgs Shell模式命令行参数
     */
    protected function setRequestParams($controller, array $routeParams, $mode, ServerRequestInterface $request = null, array $shellArgs = [])
    {
        // 如果控制器是BaseController的实例，设置请求参数
        if ($controller instanceof BaseController) {
            if ($mode === 'fpm') {
                // FPM模式：总是设置请求参数，即使路由参数为空
                $controller->setFpmParams($routeParams);
            } elseif ($mode === 'cli' && $request !== null) {
                // CLI模式：设置完整的请求参数
                $this->setCliRequestParams($controller, $request, $routeParams);
            }
        }

        // 如果控制器是BaseShell的实例，设置Shell模式参数
        if ($controller instanceof BaseShell && $mode === 'shell' && !empty($shellArgs)) {
            $parsedArgs = $this->parseShellArgs($shellArgs);
            $shellParams = array_merge($parsedArgs['args'], $parsedArgs['options']);
            $shellParams['__args__'] = $parsedArgs['args'];
            $shellParams['__options__'] = $parsedArgs['options'];
            $controller->setShellParams($shellParams);
        }
    }
    
    /**
     * 设置CLI模式请求参数
     *
     * @param BaseController $controller 控制器实例
     * @param ServerRequestInterface $request 请求对象
     * @param array $routeParams 路由参数
     */
    protected function setCliRequestParams(BaseController $controller, ServerRequestInterface $request, array $routeParams)
    {
        // 获取GET参数
        $queryParams = $request->getQueryParams();
        
        // 获取POST参数
        $parsedBody = $request->getParsedBody();
        if (!is_array($parsedBody)) {
            $parsedBody = [];
        }
        
        // 对于JSON请求，需要手动解析请求体
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
        
        // 获取文件上传参数（ReactPHP使用UploadedFileInterface）
        $uploadedFiles = $request->getUploadedFiles();
        $filesParams = [];
        
        if (!empty($uploadedFiles)) {
            // 将ReactPHP的UploadedFileInterface转换为传统的$_FILES格式
            foreach ($uploadedFiles as $fieldName => $uploadedFile) {
                if (is_array($uploadedFile)) {
                    // 多文件上传
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
                    // 单文件上传
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
        
        // 合并所有参数：GET参数 + POST参数 + 路由参数
        $allParams = array_merge($queryParams, $parsedBody, $routeParams);
        
        // 添加HTTP头信息
        $headers = $request->getHeaders();
        
        // 查找Authorization头
        $authorizationHeader = null;
        foreach ($headers as $name => $values) {
            if (strtolower($name) === 'authorization') {
                $authorizationHeader = $values[0];
                break;
            }
        }
        
        if ($authorizationHeader !== null) {
            $allParams['__authorization__'] = $authorizationHeader;
            
            // 提取Bearer token
            if (preg_match('/Bearer\\s+(.+)$/i', $authorizationHeader, $matches)) {
                $allParams['__bearer_token__'] = $matches[1];
            }
        }
        
        // 添加Content-Type头信息
        if (isset($headers['content-type'])) {
            $allParams['__content_type__'] = $headers['content-type'][0];
        }
        
        // 添加文件上传参数
        if (!empty($filesParams)) {
            $allParams['__uploaded_files__'] = $filesParams;
        }

        $allParams['__method__'] = $httpMethod = $request->getMethod();
        $allParams['__uri__'] = $request->getUri()->getPath();
        
        // 设置到控制器
        $controller->setCliParams($allParams);
    }
    
    /**
     * 将ReactPHP的UploadedFile保存到临时文件
     *
     * @param \Psr\Http\Message\UploadedFileInterface $uploadedFile
     * @return string 临时文件路径
     */
    protected function saveUploadedFileToTemp($uploadedFile): string
    {
        // 获取原始文件名和扩展名
        $originalFilename = $uploadedFile->getClientFilename();
        $extension = pathinfo($originalFilename, PATHINFO_EXTENSION);
        
        // 生成临时文件名，保留原始扩展名
        $tempPrefix = 'upload_';
        if ($extension) {
            $tempFile = tempnam(sys_get_temp_dir(), $tempPrefix) . '.' . $extension;
        } else {
            $tempFile = tempnam(sys_get_temp_dir(), $tempPrefix);
        }
        
        // 将上传的文件内容保存到临时文件
        $uploadedFile->moveTo($tempFile);
        
        return $tempFile;
    }
    
    /**
     * 创建HTTP响应
     *
     * @param mixed $data 响应数据
     * @return ReactResponse
     */
    protected function createResponse($data)
    {
        if (is_array($data) || is_object($data)) {
            return new ReactResponse(
                200,
                ['Content-Type' => 'application/json'],
                json_encode($data, JSON_UNESCAPED_UNICODE)
            );
        } else {
            return new ReactResponse(
                200,
                ['Content-Type' => 'text/html; charset=utf-8'],
                (string)$data
            );
        }
    }
    
    /**
     * 检查是否为静态文件请求
     *
     * @param string $path 请求路径
     * @return bool
     */
    protected function isStaticFileRequest($path): bool
    {
        $extension = pathinfo($path, PATHINFO_EXTENSION);
        return in_array($extension, $this->staticExtensions);
    }
    
    /**
     * 提供静态文件
     *
     * @param string $path 文件路径
     * @return ReactResponse
     */
    protected function serveStaticFile($path)
    {
        $filePath = public_path($path);
        
        if (!file_exists($filePath)) {
            return new ReactResponse(404, ['Content-Type' => 'text/plain'], 'File Not Found');
        }
        
        $content = file_get_contents($filePath);
        $mimeType = $this->getMimeType($filePath);
        
        return new ReactResponse(200, ['Content-Type' => $mimeType], $content);
    }
    
    /**
     * 获取文件的MIME类型
     *
     * @param string $filePath 文件路径
     * @return string
     */
    protected function getMimeType($filePath): string
    {
        $extension = pathinfo($filePath, PATHINFO_EXTENSION);
        
        $mimeTypes = [
            'css' => 'text/css',
            'js' => 'application/javascript',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'ico' => 'image/x-icon',
            'svg' => 'image/svg+xml',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf' => 'font/ttf',
            'eot' => 'application/vnd.ms-fontobject',
        ];
        
        return $mimeTypes[$extension] ?? 'text/plain';
    }
    
    /**
     * 记录HTTP错误日志
     *
     * @param int $statusCode HTTP状态码
     * @param string $statusText 状态文本
     * @param string $httpMethod HTTP方法
     * @param string $uri 请求URI
     * @param string $mode 运行模式
     * @param array $context 额外上下文信息
     */
    protected function logHttpError(int $statusCode, string $statusText, string $httpMethod, string $uri, string $mode, array $context = []): void
    {
        try {
            // 获取日志记录器
            $container = $this->container;
            if ($container->has('logger')) {
                $logger = $container->get('logger');
                
                // 根据状态码确定日志级别
                $level = 'warning'; // 默认警告级别
                if ($statusCode >= 500) {
                    $level = 'error';
                } elseif ($statusCode >= 400) {
                    $level = 'warning';
                } elseif ($statusCode >= 300) {
                    $level = 'info';
                }
                
                // 构建日志消息
                $message = sprintf(
                    '%s %s - %d %s',
                    $httpMethod,
                    $uri,
                    $statusCode,
                    $statusText
                );
                
                // 构建上下文
                $logContext = array_merge([
                    'mode' => $mode,
                    'status_code' => $statusCode,
                    'http_method' => $httpMethod,
                    'uri' => $uri,
                    'timestamp' => date('Y-m-d H:i:s')
                ], $context);
                
                // 记录日志
                $logger->log($level, $message, $logContext);
            }
        } catch (\Exception $e) {
            // 如果日志记录失败，静默处理，避免影响正常请求处理
        }
    }
}