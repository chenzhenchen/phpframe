<?php

namespace PHPFrame\Middleware;

/**
 * 中间件管道
 * 按顺序执行中间件链，支持洋葱模型
 */
class MiddlewarePipeline
{
    /**
     * 中间件列表
     * @var MiddlewareInterface[]
     */
    protected array $middlewares = [];

    /**
     * 添加中间件
     *
     * @param MiddlewareInterface $middleware
     * @return static
     */
    public function pipe(MiddlewareInterface $middleware): static
    {
        $this->middlewares[] = $middleware;
        return $this;
    }

    /**
     * 执行中间件管道
     *
     * @param mixed $request 请求对象
     * @param \Closure $handler 最终处理器
     * @return mixed
     */
    public function process($request, \Closure $handler): mixed
    {
        // 无中间件时直接执行处理器
        if (empty($this->middlewares)) {
            return $handler($request);
        }

        // 从最后一个中间件开始，反向构建洋葱模型
        $pipeline = array_reduce(
            array_reverse($this->middlewares),
            function (\Closure $next, MiddlewareInterface $middleware) {
                return function ($request) use ($next, $middleware) {
                    return $middleware->handle($request, $next);
                };
            },
            $handler
        );

        return $pipeline($request);
    }

    /**
     * 获取中间件数量
     */
    public function count(): int
    {
        return count($this->middlewares);
    }
}
