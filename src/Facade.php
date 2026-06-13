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
    /**
     * 每个门面类独立的上下文存储
     * key 为门面类名，value 为上下文数组
     */
    private static array $contextStore = [];

    public static function setContext(array $context): void
    {
        $key = static::class;
        self::$contextStore[$key] = array_merge(self::$contextStore[$key] ?? [], $context);
    }

    public static function getContext(): array
    {
        return self::$contextStore[static::class] ?? [];
    }

    public static function clearContext(): void
    {
        unset(self::$contextStore[static::class]);
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

        $context = static::getContext();
        $lastArg = $args[count($args) - 1] ?? null;
        if ($lastArg === null) {
            $args[] = $context;
        } elseif (is_array($lastArg)) {
            $args[count($args) - 1] = array_merge($context, $lastArg);
        } else {
            $args[] = $context;
        }

        return $args;
    }
}