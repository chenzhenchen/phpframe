<?php

namespace PHPFrame;

use React\EventLoop\Loop;
use React\Promise\Promise as ReactPromise;
use React\Promise\PromiseInterface;
use React\ChildProcess\Process;
/**
 * 任务类
 * Task class
 * 提供异步任务执行和并发控制
 * Provides asynchronous task execution and concurrency control
 */
class Task
{
    private static array $sleepingFibers = [];
    private static array $sleepWakeTimes = [];

    public static function run(callable $callback, int $timeoutMs = 0): mixed
    {
        $fiber = new \Fiber($callback);
        $fiber->start();

        // 等待Fiber完成，正确处理sleeping fibers
        $startTime = \microtime(true);
        $endTime = $timeoutMs > 0 ? $startTime + ($timeoutMs / 1000) : null;

        while (!$fiber->isTerminated()) {
            self::runEventLoop();
            \usleep(1000); // 短暂休眠避免CPU占用过高
            
            // 检查超时
            if ($endTime && \microtime(true) > $endTime) {
                throw new \RuntimeException("Task timeout after {$timeoutMs}ms");
            }
        }

        if ($fiber->isTerminated()) {
            return $fiber->getReturn();
        }

        throw new \RuntimeException("Task did not complete properly");
    }

    public static function sleep(float|int $milliseconds): void
    {
        if ($milliseconds <= 0) {
            return;
        }

        $currentFiber = \Fiber::getCurrent();
        if ($currentFiber === null) {
            \usleep((int)($milliseconds * 1000));
        } else {
            $wakeTime = \microtime(true) + ($milliseconds / 1000);
            $id = \spl_object_id($currentFiber);
            self::$sleepingFibers[$id] = $currentFiber;
            self::$sleepWakeTimes[$id] = $wakeTime;
            \Fiber::suspend();
        }
    }

    public static function concurrent(array $callbacks, int $concurrency = 0): array
    {
        if ($concurrency <= 0) {
            $concurrency = \count($callbacks);
        }

        $results = [];
        $errors = [];
        $fibers = [];

        // 创建所有fiber
        foreach ($callbacks as $index => $callback) {
            $fiber = new \Fiber(function () use ($callback, $index, &$results, &$errors) {
                try {
                    $result = $callback();
                    $results[$index] = $result;
                } catch (\Throwable $e) {
                    $errors[$index] = [
                        'message' => $e->getMessage(),
                        'file' => $e->getFile(),
                        'line' => $e->getLine()
                    ];
                }
            });
            $fibers[] = $fiber;
        }

        // 分批启动fiber
        $runningFibers = [];
        $completed = 0;
        $total = \count($fibers);

        while ($completed < $total) {
            // 启动新的fiber直到达到并发限制
            while (\count($runningFibers) < $concurrency && !empty($fibers)) {
                $fiber = array_shift($fibers);
                $fiber->start();
                $runningFibers[] = $fiber;
            }

            // 检查运行中的fiber
            $toRemove = [];
            foreach ($runningFibers as $i => $fiber) {
                if (!$fiber->isRunning() && !$fiber->isSuspended()) {
                    $completed++;
                    $toRemove[] = $i;
                }
            }
            
            // 移除已完成的fiber
            foreach ($toRemove as $i) {
                unset($runningFibers[$i]);
            }

            // 运行事件循环处理sleeping fibers
            self::runEventLoop();
            \usleep(1000);
        }

        ksort($results);
        ksort($errors);

        return [
            'results' => $results,
            'errors' => $errors,
            'total_count' => $total,
            'success_count' => \count($results),
            'error_count' => \count($errors)
        ];
    }



    public static function map(array $items, callable $callback, int $concurrency = 0): array
    {
        $callbacks = [];
        foreach ($items as $index => $item) {
            $callbacks[$index] = function () use ($callback, $item) {
                return $callback($item);
            };
        }
        return self::concurrent($callbacks, $concurrency);
    }

    public static function all(array $callbacks, int $timeout = 0): array
    {
        $result = self::concurrent($callbacks, \count($callbacks));
        $result['all_completed'] = $result['success_count'] + $result['error_count'] === \count($callbacks);
        return $result;
    }

    public static function race(array $callbacks, int $timeout = 0): array
    {
        $startTime = \microtime(true);
        $firstResult = null;
        $firstError = null;
        $completed = false;
        $timedOut = false;
        $fibers = [];
        $cancelled = [];

        // 启动所有fiber
        foreach ($callbacks as $callback) {
            $fiber = new \Fiber(function () use ($callback, &$firstResult, &$firstError, &$completed) {
                try {
                    $result = $callback();
                    if (!$completed) {
                        $firstResult = $result;
                        $completed = true;
                    }
                } catch (\Throwable $e) {
                    if (!$completed && $firstError === null) {
                        $firstError = $e;
                    }
                }
            });
            $fiber->start();
            $fibers[] = $fiber;
        }

        $endTime = $timeout > 0 ? $startTime + ($timeout / 1000) : null;

        // 等待第一个任务完成或超时
        while (!$completed && !$timedOut) {
            if ($endTime && \microtime(true) > $endTime) {
                $timedOut = true;
                break;
            }
            
            // 检查是否有fiber已完成
            foreach ($fibers as $fiber) {
                if (!$fiber->isRunning() && !$fiber->isSuspended()) {
                    $completed = true;
                    break;
                }
            }
            
            self::runEventLoop();
            \usleep(1000);
        }

        // 取消仍在运行的 Fiber，避免资源浪费
        foreach ($fibers as $fiber) {
            if ($fiber->isRunning() || $fiber->isSuspended()) {
                try {
                    // 从 sleeping fibers 中移除
                    $id = \spl_object_id($fiber);
                    unset(self::$sleepingFibers[$id], self::$sleepWakeTimes[$id]);
                    // 尝试恢复并抛出取消异常
                    if ($fiber->isSuspended()) {
                        $fiber->resume(new \RuntimeException('Race cancelled'));
                    }
                } catch (\FiberError | \Throwable $e) {
                    // 忽略取消错误
                }
                $cancelled[] = true;
            }
        }

        return [
            'first_successful' => $firstResult,
            'first_error' => $firstError,
            'completed' => $completed,
            'timed_out' => $timedOut,
            'cancelled_count' => count($cancelled),
        ];
    }

    public static function promise(callable $callback): Promise
    {
        $promise = new Promise($callback);
        $promise->start();
        return $promise;
    }

    public static function await(Promise $promise, int $timeout = 0): mixed
    {
        return $promise->await($timeout);
    }

    public static function timeout(callable $callback, int $timeoutMs): mixed
    {
        $startTime = \microtime(true);
        $endTime = $startTime + ($timeoutMs / 1000);

        $fiber = new \Fiber($callback);
        $fiber->start();

        // 等待fiber完成，正确处理sleeping fibers
        while (!$fiber->isTerminated()) {
            if (\microtime(true) > $endTime) {
                throw new \Exception("操作超时");
            }
            self::runEventLoop();
            \usleep(1000);
        }

        return $fiber->getReturn();
    }

    public static function retry(callable $callback, int $maxAttempts = 3, int $delayMs = 100): mixed
    {
        $attempt = 0;
        $lastError = null;

        while ($attempt < $maxAttempts) {
            $attempt++;
            try {
                return $callback();
            } catch (\Throwable $e) {
                $lastError = $e;
                if ($attempt < $maxAttempts) {
                    self::sleep($delayMs);
                }
            }
        }

        throw $lastError;
    }

    public static function throttle(callable $callback, int $maxConcurrent = 1, int $timeWindowMs = 1000): callable
    {
        $lastCallTime = 0;
        $concurrentCount = 0;

        return function (...$args) use ($callback, $maxConcurrent, $timeWindowMs, &$lastCallTime, &$concurrentCount) {
            $currentTime = \microtime(true) * 1000;
            
            // 检查并发限制
            if ($concurrentCount >= $maxConcurrent) {
                throw new \Exception("Over concurrent limit");
            }

            // 检查时间窗口限制
            if ($currentTime - $lastCallTime < $timeWindowMs) {
                throw new \Exception("Over frequency limit");
            }

            $concurrentCount++;
            $lastCallTime = $currentTime;

            try {
                return $callback(...$args);
            } finally {
                $concurrentCount--;
            }
        };
    }

    public static function memoize(callable $callback): callable
    {
        $cache = [];
        return function (...$args) use ($callback, &$cache) {
            $key = \serialize($args);
            if (!isset($cache[$key])) {
                $cache[$key] = $callback(...$args);
            }
            return $cache[$key];
        };
    }

    public static function pipe(callable ...$callbacks): callable
    {
        return function ($initial) use ($callbacks) {
            return self::reduce($callbacks, function ($value, $callback) {
                return $callback($value);
            }, $initial);
        };
    }

    public static function reduce(array $items, callable $callback, $initial = null)
    {
        $result = $initial;
        foreach ($items as $item) {
            $result = $callback($result, $item);
        }
        return $result;
    }

    public static function schedule(callable $callback, int $delayMs, int $timeoutMs = 0): void
    {
        self::run(function () use ($callback, $delayMs) {
            self::sleep($delayMs);
            $callback();
        }, $timeoutMs);
    }

    public static function runEventLoop(): void
    {
        $now = \microtime(true);
        $toResume = [];

        foreach (self::$sleepingFibers as $id => $fiber) {
            if ($now >= self::$sleepWakeTimes[$id]) {
                $toResume[] = $fiber;
            }
        }

        foreach ($toResume as $fiber) {
            $id = \spl_object_id($fiber);
            unset(self::$sleepingFibers[$id]);
            unset(self::$sleepWakeTimes[$id]);
            try {
                $fiber->resume();
            } catch (\FiberError $e) {
                // 忽略fiber恢复错误
            }
        }
    }

    private static function runEventLoopUntilFiberComplete(\Fiber $fiber): void
    {
        while ($fiber->isRunning()) {
            self::runEventLoop();
            \usleep(1000); // 短暂休眠避免CPU占用过高
        }
    }

    /**
     * 异步非阻塞执行任务（基于ReactPHP）
     * 
     * @param callable $callback 要执行的回调函数
     * @return ReactPromise 返回ReactPHP Promise对象
     */
    public static function async(callable $callback): ReactPromise
    {
        return new ReactPromise(function (callable $resolve, callable $reject) use ($callback) {
            // 使用立即执行的定时器来模拟defer功能
            Loop::addTimer(0, function () use ($callback, $resolve, $reject) {
                try {
                    $result = $callback();
                    $resolve($result);
                } catch (\Throwable $e) {
                    $reject($e);
                }
            });
        });
    }

    /**
     * 异步非阻塞延迟执行
     * 
     * @param callable $callback 要执行的回调函数
     * @param float $delaySeconds 延迟秒数
     * @return ReactPromise 返回ReactPHP Promise对象
     */
    public static function asyncDelay(callable $callback, float $delaySeconds): ReactPromise
    {
        return new ReactPromise(function (callable $resolve, callable $reject) use ($callback, $delaySeconds) {
            Loop::addTimer($delaySeconds, function () use ($callback, $resolve, $reject) {
                try {
                    $result = $callback();
                    $resolve($result);
                } catch (\Throwable $e) {
                    $reject($e);
                }
            });
        });
    }

    /**
     * 异步非阻塞定时器（周期性执行）
     * 
     * @param callable $callback 要执行的回调函数
     * @param float $intervalSeconds 执行间隔秒数
     * @return \React\EventLoop\TimerInterface 返回定时器对象，可用于取消
     */
    public static function asyncInterval(callable $callback, float $intervalSeconds): \React\EventLoop\TimerInterface
    {
        return Loop::addPeriodicTimer($intervalSeconds, function () use ($callback) {
            try {
                $callback();
            } catch (\Throwable $e) {
                // 记录错误但不中断定时器
                error_log("Async interval error: " . $e->getMessage());
            }
        });
    }

    /**
     * 异步非阻塞执行外部命令
     * 
     * @param string $command 要执行的命令
     * @param array $options 选项数组
     * @return ReactPromise 返回ReactPHP Promise对象
     */
    public static function asyncExec(string $command, array $options = []): ReactPromise
    {
        return new ReactPromise(function (callable $resolve, callable $reject) use ($command, $options) {
            $process = new Process($command);
            
            $process->on('exit', function ($exitCode, $termSignal) use ($resolve, $reject) {
                if ($exitCode === 0) {
                    $resolve(['exitCode' => $exitCode, 'termSignal' => $termSignal]);
                } else {
                    $reject(new \Exception("Process exited with code: $exitCode"));
                }
            });

            $process->start(Loop::get());
        });
    }

    /**
     * 异步非阻塞并发执行多个任务
     * 
     * @param array $callbacks 回调函数数组
     * @return PromiseInterface 返回包含所有结果的Promise
     */
    public static function asyncAll(array $callbacks): PromiseInterface
    {
        $promises = [];
        foreach ($callbacks as $callback) {
            $promises[] = self::async($callback);
        }

        return \React\Promise\all($promises);
    }

    /**
     * 异步非阻塞竞速执行（第一个完成的任务返回）
     * 
     * @param array $callbacks 回调函数数组
     * @return PromiseInterface 返回第一个完成的任务结果
     */
    public static function asyncRace(array $callbacks): PromiseInterface
    {
        $promises = [];
        foreach ($callbacks as $callback) {
            $promises[] = self::async($callback);
        }

        return \React\Promise\race($promises);
    }

    /**
     * 获取ReactPHP事件循环实例
     * 
     * @return \React\EventLoop\LoopInterface
     */
    public static function getEventLoop(): \React\EventLoop\LoopInterface
    {
        return Loop::get();
    }

    /**
     * 运行ReactPHP事件循环（用于CLI模式）
     */
    public static function runEventLoopForever(): void
    {
        Loop::run();
    }

    /**
     * 停止ReactPHP事件循环
     */
    public static function stopEventLoop(): void
    {
        Loop::stop();
    }
}

class WaitGroup
{
    private int $count = 0;
    private ?\Fiber $waitingFiber = null;

    public function add(int $delta = 1): void
    {
        $this->count += $delta;
    }

    public function done(): void
    {
        $this->count--;
        if ($this->count <= 0 && $this->waitingFiber !== null) {
            if ($this->waitingFiber->isSuspended()) {
                $this->waitingFiber->resume();
            }
        }
    }

    public function wait(int $timeout = 0): void
    {
        if ($this->count > 0) {
            $this->waitingFiber = \Fiber::getCurrent();
            if ($this->waitingFiber !== null) {
                $startTime = \microtime(true);
                $endTime = $timeout > 0 ? $startTime + ($timeout / 1000) : null;
                
                while ($this->count > 0) {
                    if ($endTime && \microtime(true) > $endTime) {
                        break;
                    }
                    \Fiber::suspend();
                    Task::runEventLoop();
                    \usleep(1000);
                }
            }
        }
    }
}

class Channel
{
    private array $queue = [];
    private array $receivers = [];
    private array $senders = [];

    public function send($value): void
    {
        if (!empty($this->receivers)) {
            $receiver = array_shift($this->receivers);
            if ($receiver->isSuspended()) {
                $receiver->resume($value);
            }
        } else {
            $this->queue[] = $value;
        }
    }

    public function receive(int $timeout = 0)
    {
        if (!empty($this->queue)) {
            return array_shift($this->queue);
        }

        $currentFiber = \Fiber::getCurrent();
        if ($currentFiber !== null) {
            $this->receivers[] = $currentFiber;
            
            $startTime = \microtime(true);
            $endTime = $timeout > 0 ? $startTime + ($timeout / 1000) : null;
            
            $value = \Fiber::suspend();
            
            // 如果超时，返回null
            if ($endTime && \microtime(true) > $endTime) {
                return null;
            }
            
            return $value;
        }

        return null;
    }

    public function close(): void
    {
        // 通知所有等待的接收者
        foreach ($this->receivers as $receiver) {
            if ($receiver->isSuspended()) {
                $receiver->resume(null);
            }
        }
        $this->receivers = [];
        $this->senders = [];
    }

    public function getLength(): int
    {
        return \count($this->queue);
    }
}

class Promise
{
    private mixed $result = null;
    private ?\Throwable $error = null;
    private bool $resolved = false;
    private bool $rejected = false;
    private ?\Fiber $fiber = null;

    public function __construct(callable $callback)
    {
        $this->fiber = new \Fiber(function () use ($callback) {
            try {
                $callback(
                    function ($value) { $this->resolve($value); },
                    function ($error) { $this->reject($error); }
                );
            } catch (\Throwable $e) {
                $this->reject($e);
            }
        });
        // 不立即启动fiber，让调用者决定何时启动
    }
    
    public function start(): void
    {
        if (!$this->fiber->isStarted()) {
            $this->fiber->start();
        }
    }

    public function resolve(mixed $value): void
    {
        if ($this->resolved || $this->rejected) {
            return;
        }
        $this->result = $value;
        $this->resolved = true;
    }

    public function reject(\Throwable $error): void
    {
        if ($this->resolved || $this->rejected) {
            return;
        }
        $this->error = $error;
        $this->rejected = true;
    }

    public function await(int $timeout = 0): mixed
    {
        $startTime = \microtime(true);
        $endTime = $timeout > 0 ? $startTime + ($timeout / 1000) : null;

        while (!$this->resolved && !$this->rejected) {
            if ($endTime && \microtime(true) > $endTime) {
                throw new \Exception("Promise等待超时");
            }
            
            // 如果fiber被挂起，需要运行事件循环来恢复它
            if ($this->fiber && $this->fiber->isSuspended()) {
                try {
                    $this->fiber->resume();
                } catch (\FiberError $e) {
                    // 忽略fiber恢复错误
                }
            }
            
            Task::runEventLoop();
            \usleep(1000);
        }

        if ($this->rejected) {
            throw $this->error;
        }

        return $this->result;
    }

    public function then(callable $onFulfilled = null, callable $onRejected = null): self
    {
        return new self(function ($resolve, $reject) use ($onFulfilled, $onRejected) {
            try {
                $result = $this->await();
                if ($onFulfilled) {
                    $result = $onFulfilled($result);
                }
                $resolve($result);
            } catch (\Throwable $e) {
                if ($onRejected) {
                    $result = $onRejected($e);
                    $resolve($result);
                } else {
                    $reject($e);
                }
            }
        });
    }

    public function catch(callable $onRejected): self
    {
        return $this->then(null, $onRejected);
    }

    public function finally(callable $onFinally): self
    {
        return new self(function ($resolve, $reject) use ($onFinally) {
            try {
                $result = $this->await();
                $onFinally();
                $resolve($result);
            } catch (\Throwable $e) {
                $onFinally();
                $reject($e);
            }
        });
    }
}