<?php

namespace PHPFrame;

use PHPFrame\Request;
use PHPFrame\RequestIsolationManager;
use PHPFrame\Response;
use PHPFrame\Runtime;
use React\Http\Message\Response as ReactResponse;

/**
 * 基础控制器类
 * Base controller class
 * 统一处理FPM、CLI、Shell三种模式的控制器逻辑
 * Unified handling of controller logic for FPM, CLI, and Shell modes
 */
abstract class BaseController
{
    /**
     * @var Request 请求参数处理器
     * Request parameter handler
     */
    protected $request;
    
    /**
     * @var Response 响应处理器
     * Response handler
     */
    protected $response;
    
    /**
     * @var Runtime 运行模式检测器
     * Runtime mode detector
     */
    protected $runtimeMode;
    
    /**
     * 构造函数
     * Constructor
     */
    public function __construct()
    {
        $this->runtimeMode = new Runtime();
        $this->request = new Request();
        $this->response = new Response();
        
        if ($this->runtimeMode->isCli() || $this->runtimeMode->isShell()) {
            RequestIsolationManager::isolateAll();
        }
    }
    
    /**
     * 设置请求参数（用于CLI/Shell模式）
     * Set request parameters (for CLI/Shell mode)
     */
    public function setCliParams(array $params): void
    {
        $this->request->setParams($params);
    }
    
    /**
     * 设置请求参数（用于FPM模式）
     * 通过 Request 对象获取参数，避免直接访问 $_REQUEST
     */
    public function setFpmParams(array $routeParams = []): void
    {
        // 从 Request 对象获取 GET + POST 参数
        $request = $this->request;
        $params = array_merge(
            $request->query(),
            $request->post(),
            $routeParams
        );
        $this->request->setParams($params);
    }
    
    /**
     * 获取请求参数
     * Get request parameter
     */
    protected function getParam(string $key, $default = null)
    {
        return $this->request->get($key, $default);
    }
    
    /**
     * 获取所有请求参数
     * Get all request parameters
     */
    protected function getParams(): array
    {
        return $this->request->all();
    }
    
    /**
     * 获取JSON请求体数据
     * Get JSON request body data
     */
    protected function getJsonRequestBody(): array
    {
        return $this->request->getJsonBody();
    }
    
    /**
     * 获取Bearer Token
     * Get Bearer Token
     */
    protected function getBearerToken(): ?string
    {
        return $this->request->getBearerToken();
    }
    
    /**
     * 检查参数是否存在
     * Check if parameter exists
     */
    protected function hasParam(string $key): bool
    {
        return $this->request->has($key);
    }
    
    /**
     * 只获取指定参数
     * Get only specified parameters
     */
    protected function onlyParams(array $keys): array
    {
        return $this->request->only($keys);
    }
    
    /**
     * 排除指定参数
     * Exclude specified parameters
     */
    protected function exceptParams(array $keys): array
    {
        return $this->request->except($keys);
    }
    
    /**
     * 获取当前请求的URI
     * Get current request URI
     */
    protected function getCurrentUri(): string
    {
        return $this->request->getUri();
    }
    
    /**
     * 渲染Twig模板（支持FPM和CLI模式）
     * Render Twig template (supports FPM and CLI modes)
     */
    protected function render(string $template, array $data = [])
    {
        $twig = app('twig');

        // 自动添加当前URI到模板数据中
        // Automatically add current URI to template data
        $data['current_uri'] = $this->getCurrentUri();
        return $twig->render($template, $data);

    }
    
    /**
     * 返回JSON响应
     * Return JSON response
     */
    protected function json($data, int $statusCode = 200, string $message = "")
    {
        return $this->response->json($data, $statusCode,$message);
    }
    
    /**
     * 返回成功响应
     * Return success response
     */
    protected function success($data = null, string $message = 'success')
    {
        return $this->response->success($data, $message);
    }
    
    /**
     * 返回错误响应
     * Return error response
     */
    protected function error(string $message = 'error', int $code = 500)
    {
        return $this->response->error($message, $code);
    }
    
    /**
     * 重定向（支持FPM和CLI模式）
     * Redirect (supports FPM and CLI modes)
     */
    protected function redirect(string $url, int $statusCode = 302)
    {
        if ($this->runtimeMode->isFpm()) {
            // FPM模式：使用传统的HTTP重定向
            return $this->response->redirect($url, $statusCode);
        } elseif ($this->runtimeMode->isCli()) {
            // CLI模式（ReactPHP常驻内存模式）：返回ReactPHP重定向响应
            return new ReactResponse(
                $statusCode,
                ['Location' => $url],
                "Redirecting to: {$url}"
            );
        } else {
            throw new \RuntimeException('Available in FPM and CLI modes');
        }
    }
    
    /**
     * 生成分页数据
     * Generate pagination data
     */
    protected function generatePagination($paginator): array
    {
        return $this->response->pagination($paginator);
    }
    
    /**
     * 验证请求参数
     * 委托给 Validation 类，避免重复验证逻辑
     */
    protected function validate(array $rules, array $messages = []): array
    {
        $params = $this->getParams();
        $validator = new Validation();
        $isValid = $validator->validate($params, $rules);

        if (!$isValid) {
            $errors = $validator->getErrors();
            // 如果有自定义消息，覆盖默认消息
            if (!empty($messages)) {
                foreach ($messages as $field => $message) {
                    if (isset($errors[$field])) {
                        $errors[$field] = $message;
                    }
                }
            }
            throw new \InvalidArgumentException(implode(', ', $errors));
        }

        return $this->onlyParams(array_keys($rules));
    }
    
    /**
     * 判断当前是否为FPM模式
     * Check if current mode is FPM
     */
    protected function isFpmMode(): bool
    {
        return $this->runtimeMode->isFpm();
    }
    
    /**
     * 判断当前是否为CLI模式
     * Check if current mode is CLI
     */
    protected function isCliMode(): bool
    {
        return $this->runtimeMode->isCli();
    }
    
    /**
     * 判断当前是否为Shell模式
     * Check if current mode is Shell
     */
    protected function isShellMode(): bool
    {
        return $this->runtimeMode->isShell();
    }
    
    /**
     * 资源清理
     */
    public function __destruct()
    {
        // CLI/Shell 模式下仅清理请求参数，不强制 GC
        // gc_collect_cycles() 开销大，应由业务层按需调用
        if (($this->runtimeMode->isCli() || $this->runtimeMode->isShell()) && $this->request) {
            $this->request->setParams([]);
        }
    }
}