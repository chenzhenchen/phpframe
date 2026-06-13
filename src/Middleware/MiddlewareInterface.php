<?php

namespace PHPFrame\Middleware;

/**
 * 中间件接口
 * 所有中间件必须实现此接口
 */
interface MiddlewareInterface
{
    /**
     * 处理请求
     *
     * @param mixed $request 请求对象（FPM模式为null，CLI模式为ServerRequestInterface）
     * @param \Closure $next 传递给下一个中间件的闭包
     * @return mixed
     */
    public function handle($request, \Closure $next);
}
