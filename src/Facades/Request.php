<?php

namespace PHPFrame\Facades;

use PHPFrame\Request as CoreRequest;
use PHPFrame\Runtime;
use PHPFrame\Facade;

/**
 * 请求门面类
 * 提供便捷的请求参数获取方法
 * 
 * @method static mixed get(string $key, $default = null) 获取单个请求参数
 * @method static array all() 获取所有请求参数
 * @method static bool has(string $key) 检查参数是否存在
 * @method static array only(array $keys) 获取指定的多个参数
 * @method static array except(array $keys) 排除指定参数
 * @method static array getJsonBody() 获取JSON请求体数据
 * @method static string getBearerToken() 获取Bearer令牌
 * @method static string getClientIp() 获取客户端IP
 * @method static string getMethod() 获取请求方法
 * @method static string getUri() 获取请求URI
 * @method static int getInt(string $key, int $default = 0) 获取整型参数
 * @method static float getFloat(string $key, float $default = 0.0) 获取浮点型参数
 * @method static bool getBool(string $key, bool $default = false) 获取布尔型参数
 * @method static array getArray(string $key, array $default = []) 获取数组参数
 * @method static string getString(string $key, string $default = '') 获取字符串参数
 * 
 * 使用示例：
 * ```php
 * // 获取单个参数
 * $username = Request::get('username');
 * 
 * // 获取所有参数
 * $allParams = Request::all();
 * 
 * // 获取JSON请求体
 * $jsonData = Request::getJsonBody();
 * 
 * // 获取客户端IP
 * $clientIp = Request::getClientIp();
 * 
 * // 获取请求方法
 * $method = Request::getMethod();
 * ```
 */
class Request extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'request';
    }

    /**
     * 设置请求参数（用于测试或手动设置）
     * 
     * @param array $params 请求参数
     * @return void
     */
    public static function setParams(array $params): void
    {
        $request = static::resolveFacadeInstance(static::getFacadeAccessor());
        if ($request instanceof CoreRequest) {
            $request->setParams($params);
        }
    }

    /**
     * 增强的客户端IP获取方法（支持FPM和ReactPHP协程CLI模式）
     * 优先从代理头获取，再从REMOTE_ADDR获取
     * 
     * @param bool $checkProxy 是否检查代理头
     * @return string 客户端IP地址
     */
    public static function getClientIpAdvanced(bool $checkProxy = true): string
    {
        if (Runtime::isFpm()) {
            $ipHeaders = [
                'HTTP_X_FORWARDED_FOR',
                'HTTP_X_REAL_IP',
                'HTTP_CLIENT_IP',
                'REMOTE_ADDR'
            ];

            foreach ($ipHeaders as $header) {
                $ip = $_SERVER[$header] ?? '';
                if (!empty($ip)) {
                    // 处理多个IP的情况（X-Forwarded-For可能包含多个IP）
                    if ($header === 'HTTP_X_FORWARDED_FOR') {
                        $ips = explode(',', $ip);
                        $ip = trim($ips[0]);
                    }
                    
                    // 验证IP格式
                    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                        return $ip;
                    }
                }
            }
            
            // 如果没有找到有效的公网IP，返回REMOTE_ADDR
            return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        } elseif (Runtime::isCli()) {
            // ReactPHP协程CLI模式 - 可以处理真实HTTP请求
            $request = static::resolveFacadeInstance(static::getFacadeAccessor());
            if ($request instanceof CoreRequest) {
                $params = $request->all();
                
                // 在ReactPHP模式下，优先从HTTP头获取IP（如果是通过HTTP请求）
                if (isset($params['__client_ip__'])) {
                    $ip = $params['__client_ip__'];
                    if (filter_var($ip, FILTER_VALIDATE_IP)) {
                        return $ip;
                    }
                }
                
                // 检查环境变量中的IP
                $envIp = getenv('CLIENT_IP');
                if ($envIp && filter_var($envIp, FILTER_VALIDATE_IP)) {
                    return $envIp;
                }
                
                // 如果是通过HTTP服务器访问ReactPHP应用，可以从标准HTTP头获取
                $serverIp = $params['REMOTE_ADDR'] ?? '';
                if (!empty($serverIp) && filter_var($serverIp, FILTER_VALIDATE_IP)) {
                    return $serverIp;
                }
                
                // 处理代理头
                $forwardedFor = $params['HTTP_X_FORWARDED_FOR'] ?? $params['X_FORWARDED_FOR'] ?? '';
                if (!empty($forwardedFor)) {
                    $ips = explode(',', $forwardedFor);
                    $ip = trim($ips[0]);
                    if (filter_var($ip, FILTER_VALIDATE_IP)) {
                        return $ip;
                    }
                }
            }
            
            // 纯CLI执行（非HTTP请求）
            return 'cli-server';
        } elseif (Runtime::isShell()) {
            // Shell模式下返回特殊标识
            return 'shell';
        }
        
        return 'unknown';
    }

    /**
     * 获取真实的客户端IP（不考虑代理）
     * 
     * @return string 客户端IP地址
     */
    public static function getRealClientIp(): string
    {
        if (Runtime::isFpm()) {
            return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        } elseif (Runtime::isCli()) {
            // ReactPHP协程模式下从参数中获取真实IP
            $request = static::resolveFacadeInstance(static::getFacadeAccessor());
            if ($request instanceof CoreRequest) {
                $params = $request->all();
                $serverIp = $params['REMOTE_ADDR'] ?? '';
                if (!empty($serverIp) && filter_var($serverIp, FILTER_VALIDATE_IP)) {
                    return $serverIp;
                }
            }
            return 'cli-server';
        } elseif (Runtime::isShell()) {
            return 'shell';
        }
        
        return 'unknown';
    }

    /**
     * 检查是否为AJAX请求
     * 
     * @return bool 是否为AJAX请求
     */
    public static function isAjax(): bool
    {
        if (Runtime::isFpm()) {
            return strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';
        } elseif (Runtime::isCli()) {
            // ReactPHP协程模式下从参数中获取
            $request = static::resolveFacadeInstance(static::getFacadeAccessor());
            if ($request instanceof CoreRequest) {
                $params = $request->all();
                // 从HTTP头获取X-Requested-With
                if (isset($params['HTTP_X_REQUESTED_WITH'])) {
                    return strtolower($params['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
                }
                return $params['__is_ajax__'] ?? false;
            }
        } elseif (Runtime::isShell()) {
            $request = static::resolveFacadeInstance(static::getFacadeAccessor());
            if ($request instanceof CoreRequest) {
                $params = $request->all();
                return $params['__is_ajax__'] ?? false;
            }
        }
        
        return false;
    }

    /**
     * 获取User-Agent
     * 
     * @return string User-Agent字符串
     */
    public static function getUserAgent(): string
    {
        if (Runtime::isFpm()) {
            return $_SERVER['HTTP_USER_AGENT'] ?? '';
        } elseif (Runtime::isCli()) {
            // ReactPHP协程模式下优先从HTTP头获取
            $request = static::resolveFacadeInstance(static::getFacadeAccessor());
            if ($request instanceof CoreRequest) {
                $params = $request->all();
                // 从HTTP头获取User-Agent
                if (isset($params['HTTP_USER_AGENT'])) {
                    return $params['HTTP_USER_AGENT'];
                }
                return $params['__user_agent__'] ?? 'ReactPHP-Server';
            }
        } elseif (Runtime::isShell()) {
            $request = static::resolveFacadeInstance(static::getFacadeAccessor());
            if ($request instanceof CoreRequest) {
                $params = $request->all();
                return $params['__user_agent__'] ?? 'Shell';
            }
        }
        
        return '';
    }

    /**
     * 获取Referer
     * 
     * @return string Referer URL
     */
    public static function getReferer(): string
    {
        if (Runtime::isFpm()) {
            return $_SERVER['HTTP_REFERER'] ?? '';
        } elseif (Runtime::isCli()) {
            // ReactPHP协程模式下优先从HTTP头获取
            $request = static::resolveFacadeInstance(static::getFacadeAccessor());
            if ($request instanceof CoreRequest) {
                $params = $request->all();
                // 从HTTP头获取Referer
                if (isset($params['HTTP_REFERER'])) {
                    return $params['HTTP_REFERER'];
                }
                return $params['__referer__'] ?? '';
            }
        } elseif (Runtime::isShell()) {
            $request = static::resolveFacadeInstance(static::getFacadeAccessor());
            if ($request instanceof CoreRequest) {
                $params = $request->all();
                return $params['__referer__'] ?? '';
            }
        }
        
        return '';
    }

    /**
     * 获取Content-Type
     * 
     * @return string Content-Type
     */
    public static function getContentType(): string
    {
        if (Runtime::isFpm()) {
            return $_SERVER['CONTENT_TYPE'] ?? '';
        } elseif (Runtime::isCli()) {
            // ReactPHP协程模式下优先从HTTP头获取
            $request = static::resolveFacadeInstance(static::getFacadeAccessor());
            if ($request instanceof CoreRequest) {
                $params = $request->all();
                // 从HTTP头获取Content-Type
                if (isset($params['CONTENT_TYPE'])) {
                    return $params['CONTENT_TYPE'];
                }
                return $params['__content_type__'] ?? '';
            }
        } elseif (Runtime::isShell()) {
            $request = static::resolveFacadeInstance(static::getFacadeAccessor());
            if ($request instanceof CoreRequest) {
                $params = $request->all();
                return $params['__content_type__'] ?? '';
            }
        }
        
        return '';
    }

    /**
     * 检查是否为POST请求
     * 
     * @return bool 是否为POST请求
     */
    public static function isPost(): bool
    {
        return static::getMethod() === 'POST';
    }

    /**
     * 检查是否为GET请求
     * 
     * @return bool 是否为GET请求
     */
    public static function isGet(): bool
    {
        return static::getMethod() === 'GET';
    }

    /**
     * 检查是否为PUT请求
     * 
     * @return bool 是否为PUT请求
     */
    public static function isPut(): bool
    {
        return static::getMethod() === 'PUT';
    }

    /**
     * 检查是否为DELETE请求
     * 
     * @return bool 是否为DELETE请求
     */
    public static function isDelete(): bool
    {
        return static::getMethod() === 'DELETE';
    }

    /**
     * 检查是否为PATCH请求
     * 
     * @return bool 是否为PATCH请求
     */
    public static function isPatch(): bool
    {
        return static::getMethod() === 'PATCH';
    }

    /**
     * 获取请求头信息
     * 
     * @param string $name 头名称
     * @param string $default 默认值
     * @return string 头值
     */
    public static function getHeader(string $name, string $default = ''): string
    {
        $headerName = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        
        if (Runtime::isFpm()) {
            return $_SERVER[$headerName] ?? $default;
        } elseif (Runtime::isCli()) {
            // ReactPHP协程模式下优先从HTTP头获取
            $request = static::resolveFacadeInstance(static::getFacadeAccessor());
            if ($request instanceof CoreRequest) {
                $params = $request->all();
                // 直接从参数中获取HTTP头（如果ReactPHP传递了这些头）
                if (isset($params[$headerName])) {
                    return $params[$headerName];
                }
                // 兼容参数格式
                $paramName = '__header_' . strtolower(str_replace('-', '_', $name)) . '__';
                return $params[$paramName] ?? $default;
            }
        } elseif (Runtime::isShell()) {
            $request = static::resolveFacadeInstance(static::getFacadeAccessor());
            if ($request instanceof CoreRequest) {
                $params = $request->all();
                $paramName = '__header_' . strtolower(str_replace('-', '_', $name)) . '__';
                return $params[$paramName] ?? $default;
            }
        }
        
        return $default;
    }
}