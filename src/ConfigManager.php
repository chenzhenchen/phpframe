<?php

namespace PHPFrame;

class ConfigManager
{
    protected $config = [];
    protected $cache = [];

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    public function get(string $key, $default = null)
    {
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $value = $this->getNestedValue($this->config, $key, $default);
        $this->cache[$key] = $value;
        return $value;
    }

    public function set(string $key, $value): void
    {
        $this->clearCache($key);
        $this->setNestedValue($this->config, $key, $value);
    }

    public function has(string $key): bool
    {
        return $this->getNestedValue($this->config, $key, '__NOT_EXISTS__') !== '__NOT_EXISTS__';
    }

    public function all(): array
    {
        return $this->config;
    }

    public function merge(array $config): void
    {
        $this->cache = [];
        $this->config = array_merge_recursive($this->config, $config);
    }

    protected function getNestedValue(array $array, string $key, $default = null)
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

    protected function setNestedValue(array &$array, string $key, $value): void
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