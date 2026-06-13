<?php

namespace PHPFrame;

use React\Http\Message\Response as ReactResponse;

/**
 * 响应工厂
 * 从 RouteManager 中提取的响应创建职责
 */
class ResponseFactory
{
    /**
     * 静态文件扩展名
     */
    protected array $staticExtensions = ['css', 'js', 'png', 'jpg', 'jpeg', 'gif', 'ico', 'svg', 'woff', 'woff2', 'ttf', 'eot'];

    /**
     * 创建HTTP响应（CLI模式使用）
     *
     * @param mixed $data 响应数据
     * @return ReactResponse
     */
    public function createResponse($data): ReactResponse
    {
        if (is_array($data) || is_object($data)) {
            return new ReactResponse(
                200,
                ['Content-Type' => 'application/json'],
                json_encode($data, JSON_UNESCAPED_UNICODE)
            );
        }

        return new ReactResponse(
            200,
            ['Content-Type' => 'text/html; charset=utf-8'],
            (string)$data
        );
    }

    /**
     * 检查是否为静态文件请求
     */
    public function isStaticFileRequest(string $path): bool
    {
        $extension = pathinfo($path, PATHINFO_EXTENSION);
        return in_array($extension, $this->staticExtensions);
    }

    /**
     * 提供静态文件
     */
    public function serveStaticFile(string $path): ReactResponse
    {
        $filePath = public_path($path);

        if (!file_exists($filePath)) {
            return new ReactResponse(404, ['Content-Type' => 'text/plain'], 'File Not Found');
        }

        $content = file_get_contents($filePath);
        $mimeType = $this->getMimeType($filePath);

        return new ReactResponse(200, ['Content-Type' => $mimeType], $content);
    }

    /**
     * 获取文件的MIME类型
     */
    public function getMimeType(string $filePath): string
    {
        $extension = pathinfo($filePath, PATHINFO_EXTENSION);

        $mimeTypes = [
            'css' => 'text/css',
            'js' => 'application/javascript',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'ico' => 'image/x-icon',
            'svg' => 'image/svg+xml',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf' => 'font/ttf',
            'eot' => 'application/vnd.ms-fontobject',
        ];

        return $mimeTypes[$extension] ?? 'text/plain';
    }
}
