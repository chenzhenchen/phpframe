<?php

namespace PHPFrame;

/**
 * 门面基类
 * Facade base class
 * 提供静态方式访问容器中的服务
 * Provides static access to services in the container
 */
abstract class Facade
{
    protected static $context = [];

    public static function setContext(array $context): void
    {
        static::$context = array_merge(static::$context, $context);
    }

    public static function getContext(): array
    {
        return static::$context;
    }

    public static function clearContext(): void
    {
        static::$context = [];
    }

    /**
     * 获取门面对应的服务名称
     * Get facade accessor service name
     */
    protected static function getFacadeAccessor()
    {
        throw new \Exception('Facade does not implement getFacadeAccessor method.');
    }

    /**
     * 获取容器实例
     * Get container instance
     */
    protected static function getContainer()
    {
        return Container::getInstance();
    }

    /**
     * 解析门面对应的服务实例
     * Resolve facade service instance
     */
    protected static function resolveFacadeInstance($name)
    {
        return static::getContainer()->get($name);
    }

    /**
     * 处理静态方法调用
     * Handle static method calls
     */
    public static function __callStatic($method, $args)
    {
        $instance = static::resolveFacadeInstance(static::getFacadeAccessor());

        if (! $instance) {
            throw new \Exception('A facade root has not been set.');
        }

        $args = static::injectContext($method, $args);

        return $instance->$method(...$args);
    }

    protected static function injectContext(string $method, array $args): array
    {
        $facadeAccessor = static::getFacadeAccessor();
        if ($facadeAccessor !== 'logger') {
            return $args;
        }

        $contextMethods = ['log', 'info', 'error', 'warning', 'debug', 'notice', 'alert', 'critical', 'emergency'];
        if (!in_array($method, $contextMethods)) {
            return $args;
        }

        $lastArg = $args[count($args) - 1] ?? null;
        if ($lastArg === null) {
            $args[] = static::$context;
        } elseif (is_array($lastArg)) {
            $args[count($args) - 1] = array_merge(static::$context, $lastArg);
        } else {
            $args[] = static::$context;
        }

        return $args;
    }
}