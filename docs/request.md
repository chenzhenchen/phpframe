# 请求处理

PHPFrame 的 `Request` 类封装了请求数据，支持从超全局变量或 PSR-7 请求构建，在 FPM / CLI / Shell 三种模式下提供统一的访问接口。

## 获取 Request 实例

### 在控制器中

`BaseController` 自动创建 `$this->request`：

```php
class UserController extends BaseController
{
    public function listAction()
    {
        $page = $this->getParam('page', 1);
        $all = $this->getParams();
    }
}
```

### 从容器获取

```php
$request = app('request');
```

### 工厂方法创建（推荐用于测试和 CLI 模式）

```php
// 从超全局变量创建（FPM 模式）
$request = Request::createFromGlobals();

// 从 PSR-7 请求创建（CLI 模式）
$request = Request::createFromServerRequest($psr7Request);
```

## 请求参数访问

### 通用方法

```php
// 获取单个参数（自动合并 GET/POST/路由参数）
$request->get('key');
$request->get('key', 'default');

// 获取所有参数
$request->all();

// 检查参数是否存在
$request->has('key');

// 仅获取指定参数
$request->only(['name', 'email']);

// 排除指定参数
$request->except(['password', 'token']);
```

### 指定来源访问

```php
// 显式获取 GET 参数
$request->query('page', 1);

// 获取所有 GET 参数（不传 key）
$request->query();

// 显式获取 POST 参数
$request->post('email');

// 获取所有 POST 参数（不传 key）
$request->post();

// 获取 Server 参数
$request->server('HTTP_HOST');

// 获取全部 Server 参数（不传 key）
$request->server();

// 获取上传文件
$request->files('avatar');

// 获取全部上传文件（不传 key）
$request->files();

// 获取 Cookie
$request->cookie('session_id');
```

### 类型转换方法

```php
$request->getInt('page', 1);        // 强制 int
$request->getFloat('price', 0.0);   // 强制 float
$request->getBool('active', false); // 强制 bool（支持 'true'/'1'/'yes'/'on'）
$request->getString('name', '');     // 强制 string
$request->getArray('ids', []);      // 强制 array（支持 JSON 字符串和逗号分隔）
```

## 请求信息

```php
// 请求方法
$request->getMethod();    // 'GET', 'POST', 'PUT', 'DELETE'

// 请求 URI
$request->getUri();       // '/api/users'

// 客户端 IP
$request->getClientIp();  // '192.168.1.1'

// Bearer Token
$request->getBearerToken(); // 'eyJhbGciOiJIUzI1NiIs...'

// JSON 请求体
$request->getJsonBody();  // ['key' => 'value']
```

## 三模式行为差异

| 方法 | FPM 模式 | CLI 模式 | Shell 模式 |
|------|----------|----------|------------|
| `get()` | 合并 GET/POST/路由参数 | 从注入参数获取 | 从命令行参数获取 |
| `all()` | 合并 `$_REQUEST` + 路由参数 | 注入参数 | 注入参数 |
| `getMethod()` | `$_SERVER['REQUEST_METHOD']` | `__method__` 参数，默认 `GET` | `SHELL` |
| `getUri()` | `$_SERVER['REQUEST_URI']` | `__uri__` 参数，默认 `/` | `$argv[1]` |
| `getClientIp()` | `$_SERVER['REMOTE_ADDR']` | `cli` | `unknown` |
| `getBearerToken()` | `HTTP_AUTHORIZATION` 头 | `__bearer_token__` 参数 | `null` |

## 超全局变量解耦

Request 支持两种数据来源：

1. **注入数据**（`createFromGlobals()` / `createFromServerRequest()`）：数据封装在 Request 内部属性中，不依赖超全局变量
2. **超全局变量降级**（`new Request()`）：当未通过工厂方法创建时，自动降级读取 `$_GET`/`$_POST`/`$_SERVER` 等

```php
// 注入模式（推荐，可测试）
$request = Request::createFromGlobals();
$request->get('name');  // 从注入数据读取

// 降级模式（向后兼容）
$request = new Request();
$request->get('name');  // 从 $_REQUEST 读取
```

> 注意：降级模式下 `getJsonBody()` 会直接返回 `$_POST`（因为旧版框架曾将 JSON 数据写入 `$_POST`），建议始终使用 `createFromGlobals()` 创建 Request 实例。

## JSON 请求体自动解析

`createFromGlobals()` 和 `createFromServerRequest()` 会自动检测 `Content-Type: application/json` 请求，将 JSON 数据合并到 `injectedPost` 中，使 `post()` 和 `get()` 方法可直接访问 JSON 字段，无需手动解析：

```php
// 客户端发送 JSON 请求
// Content-Type: application/json
// {"name": "John", "email": "john@example.com"}

$request = Request::createFromGlobals();
$request->post('name');   // 'John'
$request->get('email');   // 'john@example.com'
$request->getJsonBody();  // ['name' => 'John', 'email' => 'john@example.com']
```

> 注意：框架不再直接修改 `$_POST` / `$_REQUEST` 超全局变量，JSON 数据仅通过 Request 对象的注入属性提供，保持了请求封装的完整性。

## Request 门面扩展方法

`PHPFrame\Facades\Request` 除了代理 Request 实例方法外，还提供了以下扩展静态方法，自动适配 FPM/CLI/Shell 三种模式：

```php
use PHPFrame\Facades\Request;

// 请求方法判断
Request::isPost();
Request::isGet();
Request::isPut();
Request::isDelete();
Request::isPatch();

// AJAX 检测
Request::isAjax();

// 请求头
Request::getHeader('x-request-id');
Request::getUserAgent();
Request::getReferer();
Request::getContentType();

// 增强客户端 IP（支持代理头解析）
Request::getClientIpAdvanced();

// 真实客户端 IP（不考虑代理）
Request::getRealClientIp();

// 设置请求参数（测试用）
Request::setParams(['key' => 'value']);
```

## 在 BaseController 中的使用

```php
class UserController extends BaseController
{
    public function createAction()
    {
        // 获取参数
        $name = $this->getParam('name');
        $email = $this->getParam('email');

        // 获取 JSON 请求体
        $data = $this->getJsonRequestBody();

        // 获取 Bearer Token
        $token = $this->getBearerToken();

        // 验证参数
        $validated = $this->validate([
            'name' => 'required',
            'email' => 'required|email',
            'age' => 'integer|max:150',
        ]);

        // 检查参数
        if ($this->hasParam('debug')) {
            // ...
        }

        // 模式判断
        if ($this->isFpmMode()) {
            // FPM 专属逻辑
        } elseif ($this->isCliMode()) {
            // CLI 专属逻辑
        }
    }
}
```
