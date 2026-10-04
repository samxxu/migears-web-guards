# PayloadSizeGuard

> A request-size `before()` hook: a request whose declared `Content-Length` exceeds the limit is refused with `413` before its body is read.
> 一个检查请求大小的 `before()` 钩子：声明的 `Content-Length` 超过上限的请求，会在请求体被读取之前被 `413` 拒绝。

## English

### The problem it solves

A single huge upload can exhaust memory or disk before the application ever looks at it, because the damage happens while the body is being read. Catching it inside a handler is already too late. This guard checks the declared size first and refuses anything over the limit with a `413`, before a single byte of the body is consumed.

**Terms**

- *Content-Length* — The header stating the body's size in bytes.
- *413 Payload Too Large* — "the body is too big".
- *chunked transfer* — A body sent without `Content-Length`, so its size cannot be judged up front.

### What it does
`PayloadSizeGuard` reads the `Content-Length` header and refuses a request that declares more than the allowed number of bytes with a `413 Payload Too Large`. It runs before dispatch and short-circuits it, so an oversized upload is turned away instead of being parsed into memory first. It never touches a handler and produces nothing on the way out: a pure pre-decision, which is why it is a guard and not a middleware.

### Quick start
Minimal:

```php
use MiGears\WebGuards\RequestCheck\PayloadSizeGuard;

$rest->before(new PayloadSizeGuard(maxBytes: 1_048_576));
```

Full bootstrap:

```php
use MiGears\Web\MiRest;
use MiGears\Web\Request;
use MiGears\WebGuards\RequestCheck\PayloadSizeGuard;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

$rest = new MiRest(baseDir: __DIR__ . '/resources', namespace: 'App\\Resources');
$rest->set(LoggerInterface::class, fn () => new NullLogger());

// 1 MiB cap: a body that declares more is refused with a 413 before routing runs.
$rest->before(new PayloadSizeGuard(maxBytes: 1_048_576));

$rest->handle(Request::fromGlobals())->send();
```

### API reference
Constructor:

| Parameter | Type | Default | Meaning |
|---|---|---|---|
| `$maxBytes` | `int` | — (required) | the largest allowed body, in bytes; must be `>= 1` |

`__invoke(Request $request): ?Response`:

| Case | Result |
|---|---|
| no `Content-Length` header, or the header is not a string of digits | `null` — the request continues |
| `Content-Length` is a digit string and `(int) $length <= $maxBytes` | `null` — the request continues |
| `Content-Length` is a digit string and `(int) $length > $maxBytes` | `413` with `Content-Type: application/json; charset=utf-8` and body `{"error":"Payload Too Large"}` |

The refusal:

| | |
|---|---|
| Status | `413 Payload Too Large` |
| `Content-Type` | `application/json; charset=utf-8` |
| Body | `{"error":"Payload Too Large"}` |

Throws `InvalidArgumentException('The maximum size must be at least 1 byte.')` when `$maxBytes < 1`.

### Pitfalls
- The size comes from `Content-Length` **and nowhere else** — it is the only figure available before the body is consumed. The declared value is what the client said; it is not verified against the bytes actually sent.
- A request **without** a usable `Content-Length` is let through: chunked transfer encoding sends no such header, and a header that is not all digits (`a-lot`, or a value with whitespace) fails `ctype_digit()` and is treated as absent. A web server's own `client_max_body_size` (or equivalent) is the backstop for those.
- The limit is inclusive: a body of exactly `$maxBytes` is allowed; only strictly more is refused.
- Negative values never reach the refusal branch (a leading `-` fails `ctype_digit()`), so a malformed `Content-Length` is silently treated as "no figure to judge by".

### Composition & ordering
- It judges from a single header and parses no body, so it is cheap and can sit anywhere in the `before()` chain. A common order is: redirects → refusals (maintenance, auth, throttle) → request checks (`PayloadSizeGuard`, `ContentTypeGuard`) → dispatch.
- It pairs naturally with `ContentTypeGuard`. Where rejecting a huge body early matters most, register the size check first; where the media type is the more specific policy, either order is fine, since neither consumes the body.
- It should not be relied on as the only size defense: behind a server without a body limit, a chunked request bypasses it entirely.

### Typical scenarios
- Cap JSON bodies: `new PayloadSizeGuard(maxBytes: 1_048_576)` (1 MiB)
- Cap an upload endpoint: `new PayloadSizeGuard(maxBytes: 25 * 1024 * 1024)` (25 MiB)
- Small form posts: `new PayloadSizeGuard(maxBytes: 65_536)` (64 KiB)

---

## 中文

### 它解决什么问题

一个超大的上传，可以在应用真正去看它之前就把内存或磁盘耗尽，因为损害就发生在请求体被读取的过程中。等处理器里发现时已经太晚。这个守卫先读声明的体积，把超过上限的请求以 `413` 拒绝，此时请求体一个字节都还没被消费。

**术语**

- *Content-Length* —— 声明请求体字节数的头。
- *413 Payload Too Large* —— 「请求体太大」。
- *chunked transfer(分块传输)* —— 不带 `Content-Length` 发送的请求体，无法提前判断大小。

### 它做什么
`PayloadSizeGuard` 读取 `Content-Length` 头，把声明字节数超过上限的请求以 `413 Payload Too Large` 拒绝。它在分发之前运行并短路分发，所以超大的上传会被挡下，而不是先被解析进内存。它从不触碰处理器，出去的路上也不产出任何东西：一个纯粹的前置判定，所以是守卫而非中间件。

### 快速上手
最小片段：

```php
use MiGears\WebGuards\RequestCheck\PayloadSizeGuard;

$rest->before(new PayloadSizeGuard(maxBytes: 1_048_576));
```

完整 bootstrap：

```php
use MiGears\Web\MiRest;
use MiGears\Web\Request;
use MiGears\WebGuards\RequestCheck\PayloadSizeGuard;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

$rest = new MiRest(baseDir: __DIR__ . '/resources', namespace: 'App\\Resources');
$rest->set(LoggerInterface::class, fn () => new NullLogger());

// 1 MiB 上限：声明超过它的请求体，在路由之前被 413 拒绝。
$rest->before(new PayloadSizeGuard(maxBytes: 1_048_576));

$rest->handle(Request::fromGlobals())->send();
```

### API 参考
构造函数：

| 参数 | 类型 | 默认值 | 含义 |
|---|---|---|---|
| `$maxBytes` | `int` | —(必填) | 允许的最大请求体字节数；须 `>= 1` |

`__invoke(Request $request): ?Response`：

| 情形 | 结果 |
|---|---|
| 无 `Content-Length` 头，或该头不是纯数字字符串 | `null` —— 请求继续 |
| `Content-Length` 是数字字符串，且 `(int) $length <= $maxBytes` | `null` —— 请求继续 |
| `Content-Length` 是数字字符串，且 `(int) $length > $maxBytes` | `413`，响应头 `Content-Type: application/json; charset=utf-8`，响应体 `{"error":"Payload Too Large"}` |

拒绝响应：

| | |
|---|---|
| 状态码 | `413 Payload Too Large` |
| `Content-Type` | `application/json; charset=utf-8` |
| Body | `{"error":"Payload Too Large"}` |

当 `$maxBytes < 1` 时，抛出 `InvalidArgumentException('The maximum size must be at least 1 byte.')`。

### 常见坑与注意事项
- 尺寸**只**来自 `Content-Length`——它是在请求体被消费之前唯一可得的数字。这个声明值是客户端说的；它不会与实际发送的字节做核对。
- **没有**可用 `Content-Length` 的请求会被放行：分块传输编码不发这个头，而头若不是全为数字（`a-lot`，或带空白的值）会让 `ctype_digit()` 失败，从而被当作不存在。这类请求由 Web 服务器自身的 `client_max_body_size`（或等价配置）兜底。
- 上限是含端点的：恰好 `$maxBytes` 的请求体放行，只有严格超过才被拒。
- 负数永远走不到拒绝分支（前导 `-` 会让 `ctype_digit()` 失败），所以畸形的 `Content-Length` 会被静默当作「没有可判定的数字」。

### 组合与顺序
- 它只凭一个头判定、不解析请求体，所以很廉价，可放在 `before()` 链的任何位置。常见顺序是：跳转 → 拒绝型（维护、认证、限流）→ 请求体检（`PayloadSizeGuard`、`ContentTypeGuard`）→ 分发。
- 它与 `ContentTypeGuard` 天然搭配。在「尽早拒绝巨型请求体」更要紧的地方，把尺寸检查放前面；在媒体类型是更具体策略的地方，两者先后都行，因为都不消费请求体。
- 不应把它当作唯一的尺寸防线：在没有请求体上限的服务器后面，一个分块请求会完全绕过它。

### 典型场景
- 限制 JSON 请求体：`new PayloadSizeGuard(maxBytes: 1_048_576)`（1 MiB）
- 限制上传端点：`new PayloadSizeGuard(maxBytes: 25 * 1024 * 1024)`（25 MiB）
- 小型表单提交：`new PayloadSizeGuard(maxBytes: 65_536)`（64 KiB）
