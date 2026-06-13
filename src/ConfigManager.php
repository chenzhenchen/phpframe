<?php

namespace PHPFrame;

/**
 * 配置管理器
 * 负责加载、合并和访问配置项
 *
 * 特性：
 * - 自动扫描 config/ 目录，按文件名注册配置组
 * - 支持环境特定配置文件（app.production.php 覆盖 app.php）
 * - 点号分隔的键访问（database.connections.mysql.host）
 * - 配置缓存避免重复解析
 */
class ConfigManager
{
    protected array $config = [];
    protected array $cache = [];

    /**
     * 配置目录路径
     */
    protected string $configPath;

    /**
     * 当前环境
     */
    protected string $environment;

    /**
     * 是否已加载配置文件
     */
    protected bool $loaded = false;

    public function __construct(array $config = [], string $configPath = '', string $environment = '')
    {
        $this->config = $config;
        $this->configPath = $configPath;
        $this->environment = $environment;

        if (!empty($configPath) && empty($config)) {
            $this->loadFromDirectory($configPath);
        }
    }

    /**
     * 从目录自动加载所有配置文件
     *
     * 加载规则：
     * 1. 扫描 config/ 下所有 *.php 文件
     * 2. 文件名作为配置组键名（如 database.php → database）
     * 3. 环境特定文件覆盖基础文件（如 app.production.php 覆盖 app.php）
     */
    public function loadFromDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $env = $this->environment ?: ($_ENV['APP_ENV'] ?? 'production');

        // 收集基础配置文件和环境配置文件
        $baseConfigs = [];
        $envConfigs = [];

        $files = glob($path . '/*.php');
        foreach ($files as $file) {
            $basename = basename($file, '.php');

            // 检查是否为环境特定文件（如 app.production.php）
            if (preg_match('/^(.+)\.' . preg_quote($env, '/') . '$/', $basename, $matches)) {
                $envConfigs[$matches[1]] = $file;
            } else {
                // 排除已有的环境特定文件名（包含点号且点号后部分匹配已知环境）
                if (strpos($basename, '.') === false) {
                    $baseConfigs[$basename] = $file;
                }
            }
        }

        // 先加载基础配置，再用环境配置覆盖
        foreach ($baseConfigs as $name => $file) {
            $config = require $file;
            if (!is_array($config)) {
                throw new \RuntimeException("配置文件 {$file} 必须返回数组，实际返回 " . gettype($config));
            }
            $this->config[$name] = $config;
        }

        foreach ($envConfigs as $name => $file) {
            $envConfig = require $file;
            if (!is_array($envConfig)) {
                throw new \RuntimeException("环境配置文件 {$file} 必须返回数组，实际返回 " . gettype($envConfig));
            }
            if (isset($this->config[$name]) && is_array($this->config[$name]) && is_array($envConfig)) {
                // 使用深度覆盖而非递归合并
                // array_merge_recursive 对相同字符串键会合并为数组而非覆盖
                // array_replace_recursive 对相同字符串键会用后者覆盖前者
                $this->config[$name] = $this->deepMerge($this->config[$name], $envConfig);
            } else {
                $this->config[$name] = $envConfig;
            }
        }

        $this->loaded = true;
        $this->cache = [];
    }

    /**
     * 加载单个配置文件
     */
    public function loadFile(string $path, ?string $key = null): void
    {
        if (!file_exists($path)) {
            return;
        }

        $config = require $path;

        if ($key !== null) {
            $this->config[$key] = $config;
        } else {
            $basename = basename($path, '.php');
            // 去除环境后缀
            $env = $this->environment ?: ($_ENV['APP_ENV'] ?? 'production');
            $basename = preg_replace('/\.' . preg_quote($env, '/') . '$/', '', $basename);
            $this->config[$basename] = $config;
        }

        $this->cache = [];
    }

    public function get(string $key, $default = null)
    {
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $value = $this->getValue($this->config, $key, $default);
        $this->cache[$key] = $value;
        return $value;
    }

    public function set(string $key, $value): void
    {
        $this->clearCache($key);
        $this->setValue($this->config, $key, $value);
    }

    public function has(string $key): bool
    {
        return $this->getValue($this->config, $key, '__NOT_EXISTS__') !== '__NOT_EXISTS__';
    }

    public function all(): array
    {
        return $this->config;
    }

    public function merge(array $config): void
    {
        $this->cache = [];
        $this->config = $this->deepMerge($this->config, $config);
    }

    /**
     * 深度合并两个数组
     * 与 array_merge_recursive 不同，对相同字符串键用后者覆盖前者
     * 对数字键则追加
     */
    protected function deepMerge(array $base, array $override): array
    {
        $result = $base;
        foreach ($override as $key => $value) {
            if (is_int($key)) {
                // 数字键：追加
                $result[] = $value;
            } elseif (isset($result[$key]) && is_array($result[$key]) && is_array($value)) {
                // 字符串键且双方都是数组：递归合并
                $result[$key] = $this->deepMerge($result[$key], $value);
            } else {
                // 字符串键：覆盖
                $result[$key] = $value;
            }
        }
        return $result;
    }

    /**
     * 获取当前环境
     */
    public function getEnvironment(): string
    {
        return $this->environment ?: ($_ENV['APP_ENV'] ?? 'production');
    }

    /**
     * 设置环境（会清除缓存，但不重新加载文件）
     */
    public function setEnvironment(string $environment): void
    {
        $this->environment = $environment;
        $this->cache = [];
    }

    /**
     * 重新加载所有配置文件
     */
    public function reload(): void
    {
        $this->cache = [];
        $this->config = [];

        if (!empty($this->configPath)) {
            $this->loadFromDirectory($this->configPath);
        }
    }

    protected function getValue(array $array, string $key, $default = null)
    {
        $keys = explode('.', $key);
        $value = $array;

        foreach ($keys as $k) {
            if (!is_array($value) || !array_key_exists($k, $value)) {
                return $default;
            }
            $value = $value[$k];
        }

        return $value;
    }

    protected function setValue(array &$array, string $key, $value): void
    {
        $keys = explode('.', $key);
        $current = &$array;

        foreach ($keys as $k) {
            if (!isset($current[$k]) || !is_array($current[$k])) {
                $current[$k] = [];
            }
            $current = &$current[$k];
        }

        $current = $value;
    }

    protected function clearCache(string $key = null): void
    {
        if ($key === null) {
            $this->cache = [];
            return;
        }

        $prefix = $key . '.';
        foreach (array_keys($this->cache) as $cacheKey) {
            if ($cacheKey === $key || strpos($cacheKey, $prefix) === 0) {
                unset($this->cache[$cacheKey]);
            }
        }
    }
}
