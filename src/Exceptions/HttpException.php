<?php

namespace PHPFrame\Exceptions;

/**
 * HTTP 异常
 * 用于表示带有 HTTP 状态码的异常
 */
class HttpException extends \RuntimeException
{
    protected int $statusCode;

    public function __construct(int $statusCode, string $message = '', \Throwable $previous = null, int $code = 0)
    {
        $this->statusCode = $statusCode;
        parent::__construct($message, $code, $previous);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}
