# 响应处理

PHPFrame 的 `Response` 类提供统一的响应构建方法，在 FPM / CLI / Shell 三种模式下均可用。

## JSON 响应

```php
// 成功响应
return $this->success($data, '操作成功');
// 输出: {"code": 200, "data": ..., "message": "操作成功"}

// 错误响应
return $this->error('参数错误', 400);
// 输出: {"code": 400, "data": null, "message": "参数错误"}

// 自定义响应
return $this->json($data, 200, '自定义消息');
// 输出: {"code": 200, "data": ..., "message": "自定义消息"}
```

## 静态方法

Response 也支持静态调用：

```php
use PHPFrame\Response;

Response::success($data, '成功');
Response::error('失败', 500);
Response::json($data, 200, '消息');
```

## 重定向

```php
// 在控制器中（推荐，自动适配模式）
return $this->redirect('/login', 302);
// FPM 模式：设置 Location header
// CLI 模式：返回 ReactResponse（含 Location header）
// Shell 模式：抛出 RuntimeException

// 静态调用（仅 FPM 模式可用，非 FPM 模式抛出 RuntimeException）
Response::redirect('/login', 302);
```

> 注意：控制器中的 `redirect()` 方法会根据运行模式自动适配行为，推荐使用。静态 `Response::redirect()` 仅适用于 FPM 模式。两种方式均不会强制终止脚本执行。

## 分页数据

```php
// 在控制器中（通过 BaseController 代理方法）
$paginator = Db::table('users')->paginate(15);
return $this->generatePagination($paginator);

// 静态调用
return Response::pagination($paginator);
// 输出:
// {
//   "current_page": 1,
//   "last_page": 10,
//   "per_page": 15,
//   "total": 150,
//   "from": 1,
//   "to": 15,
//   "links": { "first": "...", "last": "...", "prev": null, "next": "..." }
// }
```

## HTTP 头操作

```php
use PHPFrame\Response;

Response::setHeader('Content-Type', 'application/json');
Response::setStatusCode(404);
```

> 注意：HTTP 头操作仅在 FPM 模式下生效。

## 模板渲染

```php
// 使用 Twig 模板引擎
return $this->render('user/profile.html', [
    'name' => $user->name,
    'email' => $user->email,
]);
```

模板中自动注入 `current_uri` 变量。

## CLI 模式响应

CLI 模式下，控制器返回值会被自动转换为 `ReactResponse`：

- 数组/对象 → JSON 响应（`Content-Type: application/json`）
- 字符串 → HTML 响应（`Content-Type: text/html`）

```php
// CLI 模式下直接返回数组
return $this->success($data);
// 自动转为 ReactResponse(200, ['Content-Type' => 'application/json'], json_encode($data))
```
