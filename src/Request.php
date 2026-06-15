<?php

namespace PHPFrame;

use Psr\Http\Message\ServerRequestInterface;

class Request
{
    protected array $params = [];
    protected ?string $runtimeMode = null;

    /**
     * 注入的请求数据（从超全局变量或PSR-7请求构建）
     */
    protected array $injectedQuery = [];
    protected array $injectedPost = [];
    protected array $injectedServer = [];
    protected array $injectedFiles = [];
    protected array $injectedCookies = [];
    protected string $injectedBody = '';

    /**
     * 是否已注入数据（解耦标志）
     */
    protected bool $dataInjected = false;

    protected function getRuntimeMode(): string
    {
        if ($this->runtimeMode === null) {
            if (Runtime::isFpm()) {
                $this->runtimeMode = 'fpm';
            } elseif (Runtime::isCli()) {
                $this->runtimeMode = 'cli';
            } elseif (Runtime::isShell()) {
                $this->runtimeMode = 'shell';
            } else {
                $this->runtimeMode = 'unknown';
            }
        }
        return $this->runtimeMode;
    }

    /**
     * 从超全局变量创建Request实例
     * 显式封装超全局变量，便于测试和解耦
     */
    public static function createFromGlobals(): static
    {
        $request = new static();
        $request->injectedQuery = $_GET ?? [];
        $request->injectedPost = $_POST ?? [];
        $request->injectedServer = $_SERVER ?? [];
        $request->injectedFiles = $_FILES ?? [];
        $request->injectedCookies = $_COOKIE ?? [];
        $request->injectedBody = file_get_contents('php://input') ?: '';
        $request->dataInjected = true;

        // 处理JSON请求体：将JSON数据合并到injectedPost，使post()和get()可访问
        $contentType = $request->injectedServer['CONTENT_TYPE'] ?? $request->injectedServer['HTTP_CONTENT_TYPE'] ?? '';
        if (strpos($contentType, 'application/json') !== false && !empty($request->injectedBody)) {
            $jsonParams = json_decode($request->injectedBody, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($jsonParams)) {
                $request->injectedPost = array_merge($request->injectedPost, $jsonParams);
            }
        }

        // 合并路由参数和请求参数（兼容 setFpmParams 的行为）
        $request->params = array_merge($_REQUEST ?? [], $request->params);

        return $request;
    }

    /**
     * 从PSR-7 ServerRequest创建Request实例
     * 用于CLI（ReactPHP）模式
     */
    public static function createFromServerRequest(ServerRequestInterface $serverRequest): static
    {
        $request = new static();
        $request->injectedQuery = $serverRequest->getQueryParams();
        $request->injectedPost = $serverRequest->getParsedBody() ?? [];
        $request->injectedServer = $serverRequest->getServerParams();
        $request->injectedFiles = self::convertUploadedFiles($serverRequest->getUploadedFiles());
        $request->injectedCookies = $serverRequest->getCookieParams();
        $request->injectedBody = (string)$serverRequest->getBody();
        $request->dataInjected = true;

        // 处理JSON请求体
        $contentType = $serverRequest->getHeaderLine('Content-Type');
        if (strpos($contentType, 'application/json') !== false && !empty($request->injectedBody)) {
            $jsonParams = json_decode($request->injectedBody, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($jsonParams)) {
                $request->injectedPost = array_merge($request->injectedPost, $jsonParams);
            }
        }

        // 合并参数
        $request->params = array_merge(
            $request->injectedQuery,
            $request->injectedPost,
            $request->params
        );

        // 注入特殊头信息
        $authHeader = $serverRequest->getHeaderLine('Authorization');
        if ($authHeader) {
            $request->params['__authorization__'] = $authHeader;
            if (preg_match('/Bearer\s+(.+)$/i', $authHeader, $matches)) {
                $request->params['__bearer_token__'] = $matches[1];
            }
        }
        $request->params['__method__'] = $serverRequest->getMethod();
        $request->params['__uri__'] = $serverRequest->getUri()->getPath();

        return $request;
    }

    /**
     * 转换PSR-7上传文件为传统$_FILES格式
     */
    protected static function convertUploadedFiles(array $uploadedFiles): array
    {
        $files = [];
        foreach ($uploadedFiles as $fieldName => $uploadedFile) {
            if (is_array($uploadedFile)) {
                $files[$fieldName] = [
                    'name' => [],
                    'type' => [],
                    'tmp_name' => [],
                    'error' => [],
                    'size' => []
                ];
                foreach ($uploadedFile as $file) {
                    $files[$fieldName]['name'][] = $file->getClientFilename();
                    $files[$fieldName]['type'][] = $file->getClientMediaType();
                    $files[$fieldName]['tmp_name'][] = $file->getStream()->getMetadata('uri') ?: '';
                    $files[$fieldName]['error'][] = UPLOAD_ERR_OK;
                    $files[$fieldName]['size'][] = $file->getSize();
                }
            } else {
                $files[$fieldName] = [
                    'name' => $uploadedFile->getClientFilename(),
                    'type' => $uploadedFile->getClientMediaType(),
                    'tmp_name' => $uploadedFile->getStream()->getMetadata('uri') ?: '',
                    'error' => UPLOAD_ERR_OK,
                    'size' => $uploadedFile->getSize()
                ];
            }
        }
        return $files;
    }

    public function setParams(array $params): void
    {
        $this->params = $params;
    }

    /**
     * 获取查询参数（GET）
     * 优先从注入数据读取，降级到超全局变量
     */
    public function query(string $key = null, $default = null)
    {
        // 无参数时返回全部查询参数
        if ($key === null) {
            if ($this->dataInjected) {
                return $this->injectedQuery;
            }
            return $_GET ?? [];
        }
        if ($this->dataInjected) {
            return $this->injectedQuery[$key] ?? $default;
        }
        return $_GET[$key] ?? $default;
    }

    /**
     * 获取POST参数
     * 优先从注入数据读取，降级到超全局变量
     */
    public function post(string $key = null, $default = null)
    {
        // 无参数时返回全部POST参数
        if ($key === null) {
            if ($this->dataInjected) {
                return $this->injectedPost;
            }
            return $_POST ?? [];
        }
        if ($this->dataInjected) {
            return $this->injectedPost[$key] ?? $default;
        }
        return $_POST[$key] ?? $default;
    }

    public function get(string $key, $default = null)
    {
        $mode = $this->getRuntimeMode();

        if ($mode === 'fpm') {
            // 优先从注入数据读取
            if ($this->dataInjected) {
                return $this->params[$key]
                    ?? $this->injectedQuery[$key]
                    ?? $this->injectedPost[$key]
                    ?? $default;
            }
            // 降级到超全局变量（向后兼容）
            return $this->params[$key] ?? $_REQUEST[$key] ?? $default;
        }

        return $this->params[$key] ?? $default;
    }

    public function all(): array
    {
        $mode = $this->getRuntimeMode();

        if ($mode === 'fpm') {
            if ($this->dataInjected) {
                return array_merge($this->injectedQuery, $this->injectedPost, $this->params);
            }
            // 降级到超全局变量（向后兼容）
            return array_merge($_REQUEST, $this->params);
        }

        return $this->params;
    }

    public function has(string $key): bool
    {
        $mode = $this->getRuntimeMode();

        if ($mode === 'fpm') {
            if ($this->dataInjected) {
                return isset($this->params[$key])
                    || array_key_exists($key, $this->injectedQuery)
                    || array_key_exists($key, $this->injectedPost);
            }
            return isset($this->params[$key]) || isset($_REQUEST[$key]);
        } elseif ($mode === 'cli') {
            return isset($this->params[$key]);
        }

        return false;
    }

    public function only(array $keys): array
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->get($key);
        }
        return $result;
    }

    public function except(array $keys): array
    {
        $all = $this->all();
        foreach ($keys as $key) {
            unset($all[$key]);
        }
        return $all;
    }

    public function getJsonBody(): array
    {
        $mode = $this->getRuntimeMode();

        if ($mode === 'fpm') {
            if ($this->dataInjected) {
                $contentType = $this->injectedServer['CONTENT_TYPE'] ?? '';
                if (strpos($contentType, 'application/json') !== false && !empty($this->injectedBody)) {
                    $data = json_decode($this->injectedBody, true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
                        return $data;
                    }
                }
                return [];
            }
            // 降级（向后兼容）
            $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
            if (strpos($contentType, 'application/json') !== false) {
                return $_POST;
            }
            return [];
        } elseif ($mode === 'cli') {
            $params = $this->all();

            if (isset($params['__json_body__'])) {
                $jsonData = $params['__json_body__'];
                if (is_string($jsonData)) {
                    $data = json_decode($jsonData, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        return $data;
                    }
                } elseif (is_array($jsonData)) {
                    return $jsonData;
                }
            }

            $postParams = $this->get('__post_params__', []);
            if (!empty($postParams) && is_array($postParams)) {
                return $postParams;
            }

            $filteredParams = [];
            foreach ($params as $key => $value) {
                if (!preg_match('/^__.*__$/', $key)) {
                    $filteredParams[$key] = $value;
                }
            }

            if (!empty($filteredParams)) {
                return $filteredParams;
            }
        }

        return [];
    }

    public function getBearerToken(): ?string
    {
        $mode = $this->getRuntimeMode();

        if ($mode === 'fpm') {
            if ($this->dataInjected) {
                $header = $this->injectedServer['HTTP_AUTHORIZATION'] ?? '';
            } else {
                $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
            }
            if (preg_match('/Bearer\s+(.*)$/i', $header, $matches)) {
                return $matches[1];
            }
        } elseif ($mode === 'cli') {
            $params = $this->all();

            if (isset($params['__authorization__'])) {
                $header = $params['__authorization__'];
                if (preg_match('/Bearer\s+(.*)$/i', $header, $matches)) {
                    return $matches[1];
                }
            }

            if (isset($params['__bearer_token__'])) {
                return $params['__bearer_token__'];
            }
        }

        return null;
    }

    public function getClientIp(): string
    {
        $mode = $this->getRuntimeMode();

        if ($mode === 'fpm') {
            if ($this->dataInjected) {
                return $this->injectedServer['HTTP_X_FORWARDED_FOR']
                    ?? $this->injectedServer['REMOTE_ADDR']
                    ?? 'unknown';
            }
            return $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        }

        return $mode === 'cli' ? 'cli' : 'unknown';
    }

    public function getMethod(): string
    {
        $mode = $this->getRuntimeMode();

        if ($mode === 'fpm') {
            if ($this->dataInjected) {
                return strtoupper($this->injectedServer['REQUEST_METHOD'] ?? 'GET');
            }
            if (isset($_SERVER['REQUEST_METHOD'])) {
                return strtoupper($_SERVER['REQUEST_METHOD']);
            }
        }

        if ($mode === 'cli') {
            return $this->get('__method__', 'GET');
        }

        return 'SHELL';
    }

    public function getUri(): string
    {
        $mode = $this->getRuntimeMode();

        if ($mode === 'fpm') {
            if ($this->dataInjected) {
                $uri = $this->injectedServer['REQUEST_URI'] ?? '/';
            } else {
                $uri = $_SERVER['REQUEST_URI'] ?? '/';
            }
            if (false !== $pos = strpos($uri, '?')) {
                $uri = substr($uri, 0, $pos);
            }
            return rawurldecode($uri) ?: '/';
        }

        if ($mode === 'cli') {
            return $this->get('__uri__', '/');
        }

        if ($mode === 'shell' && php_sapi_name() === 'cli') {
            global $argv;
            if (isset($argv[1])) {
                return $argv[1];
            }
            return $argv[0] ?? '/';
        }

        return '/';
    }

    /**
     * 获取Server参数
     * 优先从注入数据读取，降级到超全局变量
     * 不传 $key 时返回全部 server 参数
     */
    public function server(string $key = '', $default = null)
    {
        if ($key === '') {
            if ($this->dataInjected) {
                return $this->injectedServer;
            }
            return $_SERVER;
        }

        if ($this->dataInjected) {
            return $this->injectedServer[$key] ?? $default;
        }
        return $_SERVER[$key] ?? $default;
    }

    /**
     * 获取上传文件
     * 优先从注入数据读取，降级到超全局变量
     * 不传 $key 时返回全部文件
     */
    public function files(string $key = '', $default = null)
    {
        if ($key === '') {
            if ($this->dataInjected) {
                return $this->injectedFiles;
            }
            return $_FILES;
        }

        if ($this->dataInjected) {
            return $this->injectedFiles[$key] ?? $default;
        }
        return $_FILES[$key] ?? $default;
    }

    /**
     * 获取Cookie
     * 优先从注入数据读取，降级到超全局变量
     */
    public function cookie(string $key, $default = null)
    {
        if ($this->dataInjected) {
            return $this->injectedCookies[$key] ?? $default;
        }
        return $_COOKIE[$key] ?? $default;
    }

    public function getInt(string $key, int $default = 0): int
    {
        $value = $this->get($key, $default);
        return is_numeric($value) ? (int)$value : $default;
    }

    public function getFloat(string $key, float $default = 0.0): float
    {
        $value = $this->get($key, $default);
        return is_numeric($value) ? (float)$value : $default;
    }

    public function getBool(string $key, bool $default = false): bool
    {
        $value = $this->get($key, $default);

        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (bool)$value;
        }

        if (is_string($value)) {
            $value = strtolower($value);
            return in_array($value, ['true', '1', 'yes', 'on']);
        }

        return $default;
    }

    public function getArray(string $key, array $default = []): array
    {
        $value = $this->get($key, $default);

        if (is_array($value)) {
            return $value;
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }

            if (strpos($value, ',') !== false) {
                return array_map('trim', explode(',', $value));
            }
        }

        return $default;
    }

    public function getString(string $key, string $default = ''): string
    {
        return (string) $this->get($key, $default);
    }

    public function clearParams(): void
    {
        $this->params = [];
    }
}
