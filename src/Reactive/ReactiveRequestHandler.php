<?php

namespace PHPFrame\Reactive;

use Psr\Http\Message\ServerRequestInterface;
use React\Promise\Promise;
use FastRoute\Dispatcher;
use PHPFrame\RouteManager;

/**
 * ReactPHP请求处理器
 * 将ReactPHP的PSR-7请求转换为框架可处理的格式
 */
class ReactiveRequestHandler
{
    /**
     * @var Dispatcher
     */
    protected $dispatcher;
    
    /**
     * @var \PHPFrame\Container
     */
    protected $container;
    
    /**
     * @var RouteManager
     */
    protected $routeManager;
    
    /**
     * 构造函数
     *
     * @param Dispatcher $dispatcher FastRoute调度器
     * @param \PHPFrame\Container $container 依赖注入容器
     * @param RouteManager|null $routeManager 路由管理器（复用已有实例以保留中间件注册）
     */
    public function __construct(Dispatcher $dispatcher, $container, ?RouteManager $routeManager = null)
    {
        $this->dispatcher = $dispatcher;
        $this->container = $container;
        $this->routeManager = $routeManager ?? new RouteManager($dispatcher, $container);
    }
    
    /**
     * 处理HTTP请求
     *
     * @param ServerRequestInterface $request
     * @return Promise
     */
    public function handle(ServerRequestInterface $request)
    {
        return $this->routeManager->handleCliRequest($request);
    }
    
    /**
     * 获取路由管理器实例
     *
     * @return RouteManager
     */
    public function getRouteManager(): RouteManager
    {
        return $this->routeManager;
    }
    
    /**
     * 获取依赖注入容器
     *
     * @return \PHPFrame\Container
     */
    public function getContainer()
    {
        return $this->container;
    }
    
    /**
     * 获取路由调度器
     *
     * @return Dispatcher
     */
    public function getDispatcher(): Dispatcher
    {
        return $this->dispatcher;
    }
}
