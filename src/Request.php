<?php

namespace PHPFrame;

use PHPFrame\Runtime;

class Request
{
    protected $params = [];
    protected $runtimeMode = null;

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

    public function setParams(array $params): void
    {
        $this->params = $params;
    }

    public function get(string $key, $default = null)
    {
        $mode = $this->getRuntimeMode();
        
        if ($mode === 'fpm') {
            return $this->params[$key] ?? $_REQUEST[$key] ?? $default;
        }

        return $this->params[$key] ?? $default;
    }

    public function all(): array
    {
        $mode = $this->getRuntimeMode();
        
        if ($mode === 'fpm') {
            return array_merge($_REQUEST, $this->params);
        }

        return $this->params;
    }

    public function has(string $key): bool
    {
        $mode = $this->getRuntimeMode();
        
        if ($mode === 'fpm') {
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
            $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
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
            return $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        }

        return $mode === 'cli' ? 'cli' : 'unknown';
    }

    public function getMethod(): string
    {
        $mode = $this->getRuntimeMode();
        
        if ($mode === 'fpm' && isset($_SERVER['REQUEST_METHOD'])) {
            return strtoupper($_SERVER['REQUEST_METHOD']);
        }

        if ($mode === 'cli') {
            return $this->get('__method__');
        }

        return 'SHELL';
    }

    public function getUri(): string
    {
        $mode = $this->getRuntimeMode();
        
        if ($mode === 'fpm' && isset($_SERVER['REQUEST_URI'])) {
            $uri = $_SERVER['REQUEST_URI'];
            if (false !== $pos = strpos($uri, '?')) {
                $uri = substr($uri, 0, $pos);
            }
            return rawurldecode($uri) ?: '/';
        }

        if ($mode === 'cli') {
            return $this->get('__uri__');
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