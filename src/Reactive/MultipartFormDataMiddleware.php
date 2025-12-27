<?php

namespace PHPFrame\Reactive;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use React\Http\Message\Response as ReactResponse;
use React\Promise\Promise;

/**
 * Multipart/Form-Data解析中间件
 * 用于处理ReactPHP中的multipart/form-data请求
 */
class MultipartFormDataMiddleware
{
    /**
     * 处理multipart/form-data请求
     *
     * @param ServerRequestInterface $request
     * @param callable $next
     * @return Promise
     */
    public function __invoke(ServerRequestInterface $request, callable $next)
    {
        $contentType = $request->getHeaderLine('Content-Type');
        
        // 检查是否为multipart/form-data请求
        if (strpos($contentType, 'multipart/form-data') === false) {
            return $next($request);
        }
        
        return new Promise(function ($resolve, $reject) use ($request, $next) {
            $body = (string)$request->getBody();
            
            if (empty($body)) {
                return $resolve($next($request));
            }
            
            try {
                $parsedRequest = $this->parseMultipartFormData($request, $body);
                $resolve($next($parsedRequest));
            } catch (\Exception $e) {
                $resolve(new ReactResponse(400, ['Content-Type' => 'application/json'], json_encode([
                    'success' => false,
                    'message' => 'Multipart form data parsing failed: ' . $e->getMessage()
                ])));
            }
        });
    }
    
    /**
     * 解析multipart/form-data请求体
     *
     * @param ServerRequestInterface $request
     * @param string $body
     * @return ServerRequestInterface
     */
    protected function parseMultipartFormData(ServerRequestInterface $request, string $body): ServerRequestInterface
    {
        $contentType = $request->getHeaderLine('Content-Type');
        
        // 提取boundary
        if (!preg_match('/boundary=(.*)$/i', $contentType, $matches)) {
            throw new \RuntimeException('Missing boundary in Content-Type header');
        }
        
        $boundary = '--' . trim($matches[1]);
        $parts = explode($boundary, $body);
        
        $parsedBody = [];
        $uploadedFiles = [];
        
        foreach ($parts as $part) {
            $part = trim($part);
            
            // 跳过空部分和结束标记
            if (empty($part) || $part === '--') {
                continue;
            }
            
            // 解析part头部和内容
            list($headers, $content) = explode("\r\n\r\n", $part, 2);
            
            // 解析头部
            $headers = $this->parseHeaders($headers);
            
            // 获取字段名
            if (!preg_match('/name="([^"]*)"/', $headers['content-disposition'] ?? '', $matches)) {
                continue;
            }
            
            $fieldName = $matches[1];
            
            // 检查是否为文件上传
            if (isset($headers['content-disposition']) && strpos($headers['content-disposition'], 'filename') !== false) {
                // 文件上传
                $filename = '';
                if (preg_match('/filename="([^"]*)"/', $headers['content-disposition'], $filenameMatches)) {
                    $filename = $filenameMatches[1];
                }
                
                $contentType = $headers['content-type'] ?? 'application/octet-stream';
                
                // 创建临时文件
                $tempFile = tempnam(sys_get_temp_dir(), 'reactphp_upload_');
                file_put_contents($tempFile, $content);
                
                // 创建UploadedFile对象
                $uploadedFiles[$fieldName] = new ReactUploadedFile(
                    $tempFile,
                    filesize($tempFile),
                    UPLOAD_ERR_OK,
                    $filename,
                    $contentType
                );
            } else {
                // 普通表单字段
                $parsedBody[$fieldName] = trim($content);
            }
        }
        
        // 更新请求对象
        $request = $request
            ->withParsedBody($parsedBody)
            ->withUploadedFiles($uploadedFiles);
        
        return $request;
    }
    
    /**
     * 解析头部字符串
     *
     * @param string $headersString
     * @return array
     */
    protected function parseHeaders(string $headersString): array
    {
        $headers = [];
        $lines = explode("\r\n", $headersString);
        
        foreach ($lines as $line) {
            if (empty($line)) continue;
            
            if (strpos($line, ':') !== false) {
                list($name, $value) = explode(':', $line, 2);
                $headers[strtolower(trim($name))] = trim($value);
            } else {
                $headers[] = trim($line);
            }
        }
        
        return $headers;
    }
}

/**
 * ReactPHP UploadedFile实现类
 */
class ReactUploadedFile implements UploadedFileInterface
{
    private $filePath;
    private $size;
    private $error;
    private $clientFilename;
    private $clientMediaType;
    private $moved = false;
    
    public function __construct($filePath, $size, $error, $clientFilename = null, $clientMediaType = null)
    {
        $this->filePath = $filePath;
        $this->size = $size;
        $this->error = $error;
        $this->clientFilename = $clientFilename;
        $this->clientMediaType = $clientMediaType;
    }
    
    public function getStream()
    {
        if ($this->moved) {
            throw new \RuntimeException('Cannot retrieve stream after it has already been moved');
        }
        
        return new \React\Stream\ReadableResourceStream(fopen($this->filePath, 'r'));
    }
    
    public function moveTo($targetPath)
    {
        if ($this->moved) {
            throw new \RuntimeException('Cannot move file after it has already been moved');
        }
        
        if (!is_writable(dirname($targetPath))) {
            throw new \InvalidArgumentException('Upload target path is not writable');
        }
        
        if (!rename($this->filePath, $targetPath)) {
            throw new \RuntimeException('Error moving uploaded file');
        }
        
        $this->moved = true;
    }
    
    public function getSize()
    {
        return $this->size;
    }
    
    public function getError()
    {
        return $this->error;
    }
    
    public function getClientFilename()
    {
        return $this->clientFilename;
    }
    
    public function getClientMediaType()
    {
        return $this->clientMediaType;
    }
}