<?php

namespace PHPFrame;

use PHPFrame\Runtime;

/**
 * 响应处理类
 * 统一处理FPM、CLI、Shell三种模式的响应输出
 */
class Response
{
    /**
     * 创建JSON响应
     */
    public static function json($data, int $statusCode = 200, string $message = ""): array
    {
        if (is_array($data) && isset($data['code'])) {
            if (!isset($data['data'])) {
                $data['data'] = null;
            }
            if (!isset($data['message'])) {
                $data['message'] = '';
            }
            return $data;
        }
        
        $response = [
            'code' => $statusCode,
            'data' => $data,
            'message' => $message,
        ];

        return $response;
    }
    
    /**
     * 创建错误响应
     */
    public static function error(string $message, int $statusCode = 500, ?array $data = null): array
    {
        return self::json([
            'code' => $statusCode,
            'data' => $data,
            'message' => $message,
        ], $statusCode);
    }
    
    /**
     * 创建成功响应
     */
    public static function success($data = null, string $message = '操作成功'): array
    {
        return self::json([
            'code' => 200,
            'data' => $data,
            'message' => $message,
        ]);
    }
    
    /**
     * 重定向（仅FPM模式）
     */
    public static function redirect(string $url, int $statusCode = 302): void
    {
        if (!Runtime::isFpm()) {
            throw new \RuntimeException('重定向仅在FPM模式下可用');
        }
        
        http_response_code($statusCode);
        header("Location: {$url}");
        exit;
    }
    
    /**
     * 设置HTTP头
     */
    public static function setHeader(string $name, string $value): void
    {
        if (Runtime::isFpm()) {
            header("{$name}: {$value}");
        }
    }
    
    /**
     * 设置HTTP状态码
     */
    public static function setStatusCode(int $code): void
    {
        if (Runtime::isFpm()) {
            http_response_code($code);
        }
    }
    
    /**
     * 生成分页数据
     */
    public static function pagination($paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
            'links' => [
                'first' => $paginator->url(1),
                'last' => $paginator->url($paginator->lastPage()),
                'prev' => $paginator->previousPageUrl(),
                'next' => $paginator->nextPageUrl(),
            ],
        ];
    }
}