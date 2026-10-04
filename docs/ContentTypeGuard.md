# ContentTypeGuard

> A request Content-Type `before()` hook: a body-carrying request whose media type is not allowed is refused with `415` before a handler tries to parse it.
> 一个检查请求 Content-Type 的 `before()` 钩子：带请求体、但媒体类型不被允许的请求，会在处理器试图解析它之前被 `415` 拒绝。

## English

### The problem it solves

A handler that expects only JSON may still receive a form-encoded or XML body. It then either parses the wrong shape or fails with a confusing `500`, burying the real cause. This guard refuses an unsupported body type with an explicit `415` before the body is read, so the client gets a clear answer instead of a broken request.

**Terms**

- *Content-Type / media type* — The header stating the body's format, e.g. `application/json`.
- *415 Unsupported Media Type* — "I do not accept this kind of body type".
- *body request* — A request method that carries a body, such as POST, PUT or PATCH.

### What it does
`ContentTypeGuard` inspects the `Content-Type` header of requests it is configured to care about — `POST`, `PUT`, `PATCH` by default — and refuses any whose media type is not in the allowed list with a `415 Unsupported Media Type`. Methods that carry no body (and any method you did not list) pass straight through. It runs before dispatch and short-circuits it, and never touches a handler: a pure pre-decision, so it is a guard and not a middleware — there is no response of its own to decorate on the way out.

### Quick start
Minimal:

```php
use MiGears\WebGuards\RequestCheck\ContentTypeGuard;

$rest->before(new ContentTypeGuard(['application/json']));
```

Full bootstrap:

```php
use MiGears\Web\MiRest;
use MiGears\Web\Request;
use MiGears\WebGuards\RequestCheck\ContentTypeGuard;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

$rest = new MiRest(baseDir: __DIR__ . '/resources', namespace: 'App\\Resources');
$rest->set(LoggerInterface::class, fn () => new NullLogger());

// A POST/PUT/PATCH must declare application/json (or any application/* type);
// otherwise it is refused with a 415 before routing runs.
$rest->before(new ContentTypeGuard(['application/*']));

$rest->handle(Request::fromGlobals())->send();
```

### API reference
Constructor:

| Parameter | Type | Default | Meaning |
|---|---|---|---|
| `$allowedTypes` | `list<string>` | — (required) | media types to accept, e.g. `['application/json']`; may include a trailing wildcard like `application/*` |
| `$methods` | `list<string>` | `['POST', 'PUT', 'PATCH']` | methods expected to carry a body; only these are checked |

`__invoke(Request $request): ?Response`:

| Case | Result |
|---|---|
| `strtoupper($request->method)` is not in `$methods` | `null` — the request continues |
| the method is checked and the declared media type is allowed | `null` — the request continues |
| the method is checked and the media type is not allowed | `415` with `Content-Type: application/json; charset=utf-8` and body `{"error":"Unsupported Media Type"}` |

Matching rules:

| Rule | Detail |
|---|---|
| which header is read | `Content-Type`; the media type is the part before the first `;`, trimmed and lower-cased, so `application/json; charset=utf-8` compares as `application/json` |
| exact match | the normalized media type equals a normalized allowed type |
| wildcard | an allowed type ending in `/*` matches any media type that starts with what precedes the `*` — `application/*` matches `application/xml` |
| missing header | a checked request with no `Content-Type` has media type `''`, which matches nothing, so it is refused |
| normalization | `$allowedTypes` are trimmed and lower-cased; `$methods` are trimmed and upper-cased |

Throws `InvalidArgumentException('At least one allowed content type is required.')` when `$allowedTypes` is `[]`, and `InvalidArgumentException('A content type must not be empty.')` / `InvalidArgumentException('A method must not be empty.')` when any entry in the respective list is whitespace-only.

### Pitfalls
- The check is on the **declared header only**. The body is never inspected, so a request that declares `application/json` but sends text is not caught here.
- A missing `Content-Type` on a protected method counts as "not allowed" and is refused with `415`. If some clients legitimately omit it, either add the method to a bypass or handle it downstream.
- The wildcard is a trailing `/*` only. A pattern such as `*/json` or a full glob is not supported and never matches.
- An empty `$methods` list is legal and makes the guard a no-op: nothing is ever checked.
- Method comparison is case-insensitive (both sides upper-cased), but the media-type comparison is exact after lower-casing — no parameters, no extra spaces survive.

### Composition & ordering
- It is an independent, cheap shape check, so it can sit anywhere in the `before()` chain. A common order is: redirects (`HttpsGuard`, `CanonicalGuard`) → refusals (`MaintenanceGuard`, auth, throttle) → request checks (`PayloadSizeGuard`, `ContentTypeGuard`) → dispatch.
- It pairs naturally with `PayloadSizeGuard`: both judge the request from headers alone, neither parses the body, and together they turn away an oversized or unparseable body before any handler pays for it. Register the size check first where rejecting a huge body early matters most.

### Typical scenarios
- JSON-only API: `new ContentTypeGuard(['application/json'])`
- Accept a family: `new ContentTypeGuard(['application/*'])`
- JSON or form posts: `new ContentTypeGuard(['application/json', 'application/x-www-form-urlencoded'])`
- Extend the checked methods: `new ContentTypeGuard(['application/json'], ['POST', 'PUT', 'PATCH', 'DELETE'])`

---

## 中文

### 它解决什么问题

一个只期望 JSON 的处理器，却可能收到表单或 XML 格式的请求体。于是它要么解析出错误的结构，要么抛出一个让人摸不着头脑的 `500`，把真正的原因埋掉。这个守卫在读取请求体之前，就用一个明确的 `415` 拒绝不支持的请求体类型，客户端因此拿到清楚的答复，而不是一个坏掉的请求。

**术语**

- *Content-Type / media type(媒体类型)* —— 声明请求体是什么格式的头，如 `application/json`。
- *415 Unsupported Media Type* —— 「我不接受这种请求体类型」。
- *body request(带请求体的请求)* —— 带请求体的请求方法，如 POST、PUT、PATCH。

### 它做什么
`ContentTypeGuard` 检查它被配置去关心的请求的 `Content-Type` 头——默认是 `POST`、`PUT`、`PATCH`——把媒体类型不在允许列表里的请求用 `415 Unsupported Media Type` 拒绝。不带请求体的方法（以及任何你没有列入的方法）直接通过。它在分发之前运行并短路分发，从不触碰处理器：一个纯粹的前置判定，所以是守卫而非中间件——出去的路上没有属于它自己的响应要装饰。

### 快速上手
最小片段：

```php
use MiGears\WebGuards\RequestCheck\ContentTypeGuard;

$rest->before(new ContentTypeGuard(['application/json']));
```

完整 bootstrap：

```php
use MiGears\Web\MiRest;
use MiGears\Web\Request;
use MiGears\WebGuards\RequestCheck\ContentTypeGuard;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

$rest = new MiRest(baseDir: __DIR__ . '/resources', namespace: 'App\\Resources');
$rest->set(LoggerInterface::class, fn () => new NullLogger());

// POST/PUT/PATCH 必须声明 application/json（或任意 application/* 类型）；
// 否则在路由之前被 415 拒绝。
$rest->before(new ContentTypeGuard(['application/*']));

$rest->handle(Request::fromGlobals())->send();
```

### API 参考
构造函数：

| 参数 | 类型 | 默认值 | 含义 |
|---|---|---|---|
| `$allowedTypes` | `list<string>` | —(必填) | 接受的媒体类型，如 `['application/json']`；可含 `application/*` 这类尾部通配 |
| `$methods` | `list<string>` | `['POST', 'PUT', 'PATCH']` | 预期带请求体的方法；只有这些会被检查 |

`__invoke(Request $request): ?Response`：

| 情形 | 结果 |
|---|---|
| `strtoupper($request->method)` 不在 `$methods` 内 | `null` —— 请求继续 |
| 方法被检查，且声明的媒体类型被允许 | `null` —— 请求继续 |
| 方法被检查，且媒体类型不被允许 | `415`，响应头 `Content-Type: application/json; charset=utf-8`，响应体 `{"error":"Unsupported Media Type"}` |

匹配规则：

| 规则 | 细节 |
|---|---|
| 读哪个头 | `Content-Type`；媒体类型取第一个 `;` 之前的部分，trim 并转小写，所以 `application/json; charset=utf-8` 按 `application/json` 比较 |
| 精确匹配 | 规范化后的媒体类型等于某个规范化后的允许类型 |
| 通配 | 以 `/*` 结尾的允许类型，可匹配任何以 `*` 之前部分开头的媒体类型——`application/*` 匹配 `application/xml` |
| 缺头 | 被检查的请求若无 `Content-Type`，媒体类型为 `''`，匹配不到任何允许类型，于是被拒 |
| 规范化 | `$allowedTypes` trim 并转小写；`$methods` trim 并转大写 |

当 `$allowedTypes` 为 `[]` 时抛出 `InvalidArgumentException('At least one allowed content type is required.')`；当相应列表中有条目只含空白字符时，抛出 `InvalidArgumentException('A content type must not be empty.')` / `InvalidArgumentException('A method must not be empty.')`。

### 常见坑与注意事项
- 只看**声明的头**。请求体从不检查，所以一个声明 `application/json` 却发送文本的请求在这里不会被抓到。
- 受保护方法上缺失 `Content-Type` 会被当作「不允许」，以 `415` 拒绝。若确有客户端合理地省略它，要么让该方法绕过，要么在下游处理。
- 通配只有尾部 `/*` 一种。像 `*/json` 或完整 glob 这样的模式不被支持，永远不会匹配。
- `$methods` 可以是空列表，此时守卫形同虚设：什么都不检查。
- 方法比较大小写不敏感（两边都转大写），但媒体类型比较在转小写后是精确的——参数、多余空格都不会残留。

### 组合与顺序
- 它是独立且廉价的形状检查，可以放在 `before()` 链的任何位置。常见顺序是：跳转（`HttpsGuard`、`CanonicalGuard`）→ 拒绝型（`MaintenanceGuard`、认证、限流）→ 请求体检（`PayloadSizeGuard`、`ContentTypeGuard`）→ 分发。
- 它与 `PayloadSizeGuard` 天然搭配：两者都仅凭头判定请求，都不解析请求体，合起来能在任何处理器付出代价之前把过大或不可解析的请求体挡在门外。在「尽早拒绝巨型请求体」更要紧的地方，把尺寸检查放在前面。

### 典型场景
- 只收 JSON 的 API：`new ContentTypeGuard(['application/json'])`
- 接受一族类型：`new ContentTypeGuard(['application/*'])`
- JSON 或表单提交：`new ContentTypeGuard(['application/json', 'application/x-www-form-urlencoded'])`
- 扩展被检查的方法：`new ContentTypeGuard(['application/json'], ['POST', 'PUT', 'PATCH', 'DELETE'])`
