# 异步任务

PHPFrame 基于 PHP Fiber 和 ReactPHP 提供异步任务能力，包括并发执行、定时器、Promise 等。

## Fiber 模式（同步风格异步）

### 基本执行

```php
use PHPFrame\Task;

$result = Task::run(function () {
    // 在 Fiber 中执行
    return 'hello';
});
// $result = 'hello'
```

### 超时控制

```php
$result = Task::run(function () {
    Task::sleep(1000); // 休眠 1 秒
    return 'done';
}, 5000); // 5 秒超时
```

### 协程休眠

```php
Task::sleep(1000); // 休眠 1000 毫秒（不阻塞其他 Fiber）
```

## 并发控制

### 并发执行

```php
$results = Task::concurrent([
    fn() => Db::select('SELECT * FROM users'),
    fn() => Db::select('SELECT * FROM orders'),
    fn() => Cache::get('stats'),
], concurrency: 2); // 最多 2 个并发

// $results = [
//     'results' => [0 => [...], 1 => [...], 2 => ...],
//     'errors' => [],
//     'total_count' => 3,
//     'success_count' => 3,
//     'error_count' => 0,
// ]
```

### Map 并发

```php
$ids = [1, 2, 3, 4, 5];
$results = Task::map($ids, function ($id) {
    return Db::selectOne('SELECT * FROM users WHERE id = ?', [$id]);
}, concurrency: 3);
```

### 全部完成

```php
$results = Task::all([
    fn() => fetchApi('/users'),
    fn() => fetchApi('/orders'),
    fn() => fetchApi('/products'),
]);
```

### 竞速

```php
$result = Task::race([
    fn() => fetchFromCache('key'),
    fn() => fetchFromDb('key'),
]);
// 返回第一个完成的结果
// 其余未完成的 Fiber 会被自动取消，避免资源浪费
```

返回值包含 `cancelled_count` 字段，表示被取消的 Fiber 数量：

```php
[
    'first_successful' => 'result',
    'first_error' => null,
    'completed' => true,
    'timed_out' => false,
    'cancelled_count' => 1,  // 被取消的 Fiber 数量
]
```

## 超时与重试

### 超时

```php
$result = Task::timeout(function () {
    return slowOperation();
}, 3000); // 3 秒超时，抛出异常
```

### 重试

```php
$result = Task::retry(function () {
    return unreliableApiCall();
}, maxAttempts: 3, delayMs: 500); // 最多重试 3 次，间隔 500ms
```

## 节流与缓存

### 节流

```php
$throttled = Task::throttle(function ($url) {
    return file_get_contents($url);
}, maxConcurrent: 1, timeWindowMs: 1000); // 1 秒内最多 1 次调用

$result = $throttled('https://api.example.com/data');
```

### 记忆化

```php
$expensive = Task::memoize(function ($n) {
    return fibonacci($n);
});

$expensive(100); // 计算一次
$expensive(100); // 从缓存返回
```

## 管道

```php
$transform = Task::pipe(
    fn($s) => trim($s),
    fn($s) => strtolower($s),
    fn($s) => str_replace(' ', '-', $s),
);

$result = $transform('  Hello World  '); // 'hello-world'
```

## 定时调度

```php
Task::schedule(function () {
    Log::info('Scheduled task executed');
}, delayMs: 5000); // 5 秒后执行
```

## ReactPHP 异步模式

基于 ReactPHP 事件循环的非阻塞异步操作：

```php
// 异步执行
$promise = Task::async(function () {
    return computeResult();
});

// 异步延迟
$promise = Task::asyncDelay(function () {
    return computeResult();
}, 2.0); // 2 秒后执行

// 异步定时器
$timer = Task::asyncInterval(function () {
    Log::info('Heartbeat');
}, 5.0); // 每 5 秒执行

// 异步命令执行
$promise = Task::asyncExec('ls -la');

// 异步全部完成
$promise = Task::asyncAll([
    fn() => fetchApi('/users'),
    fn() => fetchApi('/orders'),
]);

// 异步竞速
$promise = Task::asyncRace([
    fn() => fetchFromPrimary(),
    fn() => fetchFromSecondary(),
]);
```

## WaitGroup

```php
$wg = new WaitGroup();

$wg->add(2);

Task::run(function () use ($wg) {
    // 处理任务 1
    $wg->done();
});

Task::run(function () use ($wg) {
    // 处理任务 2
    $wg->done();
});

$wg->wait(); // 等待所有任务完成
```

## Channel

Fiber 间的通信通道：

```php
$channel = new Channel();

// 发送
Task::run(function () use ($channel) {
    $channel->send('hello');
});

// 接收
Task::run(function () use ($channel) {
    $value = $channel->receive(); // 'hello'
});
```

## Promise

```php
$promise = Task::promise(function ($resolve, $reject) {
    // 异步操作
    $resolve('result');
});

// 等待结果
$result = Task::await($promise);

// 链式调用
$promise->then(
    fn($value) => strtoupper($value),
    fn($error) => 'error'
);
```

## Task API 总览

| 方法 | 说明 |
|------|------|
| `run($callback, $timeoutMs)` | 在 Fiber 中执行 |
| `sleep($ms)` | 协程休眠 |
| `concurrent($callbacks, $concurrency)` | 并发执行 |
| `map($items, $callback, $concurrency)` | 并发映射 |
| `all($callbacks, $timeout)` | 全部完成 |
| `race($callbacks, $timeout)` | 竞速 |
| `timeout($callback, $timeoutMs)` | 超时执行 |
| `retry($callback, $maxAttempts, $delayMs)` | 重试 |
| `throttle($callback, $maxConcurrent, $timeWindowMs)` | 节流 |
| `memoize($callback)` | 记忆化 |
| `pipe(...$callbacks)` | 管道 |
| `schedule($callback, $delayMs, $timeoutMs)` | 定时调度 |
| `async($callback)` | ReactPHP 异步 |
| `asyncDelay($callback, $seconds)` | 异步延迟 |
| `asyncInterval($callback, $seconds)` | 异步定时器 |
| `asyncExec($command)` | 异步命令 |
| `asyncAll($callbacks)` | 异步全部 |
| `asyncRace($callbacks)` | 异步竞速 |
| `promise($callback)` | 创建 Promise |
| `await($promise, $timeout)` | 等待 Promise |
