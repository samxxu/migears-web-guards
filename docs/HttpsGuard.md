# HttpsGuard

> An HTTPS-enforcement `before()` hook: a request that arrived over plain HTTP is answered with a redirect to the same URL over HTTPS.
> 一个强制 HTTPS 的 `before()` 钩子：以明文 HTTP 到达的请求会被重定向到同一 URL 的 HTTPS 版本。

## English

### The problem it solves

As long as some traffic still travels over plain HTTP, passwords, session cookies and tokens cross the network in the clear, where anyone along the path can read or change them. Without this guard, a visitor who typed `http://` by hand or followed an old link simply stays on an unencrypted connection. The guard notices the insecure scheme and sends the browser back to the same URL over HTTPS.

**Terms**

- *HTTPS / TLS* — The transport layer that encrypts HTTP traffic.
- *redirect* — Telling the browser to load the page again from a different address.
- *301 vs 308* — Both are permanent redirects; `308` also keeps the request method and body, `301` does not.

### What it does
`HttpsGuard` looks at how the request arrived. An already-secure request continues untouched (`null`); a request that reached the application over an insecure scheme is answered with a `301` (by default) whose `Location` is the same URL rebuilt with the `https` scheme. It runs before dispatch, short-circuits it, and never touches a handler — a pure pre-decision, which is why it is a guard and not a middleware: there is nothing for it to run on the way out. A redirect is all it produces; it adds no `Strict-Transport-Security` header.

### Quick start
Minimal:

```php
use MiGears\WebGuards\Https\HttpsGuard;

$rest->before(new HttpsGuard());
```

Full bootstrap:

```php
use MiGears\Web\MiRest;
use MiGears\Web\Request;
use MiGears\WebGuards\Https\HttpsGuard;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

$rest = new MiRest(baseDir: __DIR__ . '/resources', namespace: 'App\\Resources');
$rest->set(LoggerInterface::class, fn () => new NullLogger());

// An http:// request is moved to https:// before any handler runs.
$rest->before(new HttpsGuard(status: 301));

$rest->handle(Request::fromGlobals())->send();
```

### API reference
Constructor:

| Parameter | Type | Default | Meaning |
|---|---|---|---|
| `$status` | `int` | `301` | the redirect status; must be a 3xx code |
| `$trustForwardedProto` | `bool` | `false` | read `X-Forwarded-Proto` to detect TLS termination at a proxy |

`__invoke(Request $request): ?Response`:

| Case | Result |
|---|---|
| the request is judged secure | `null` — the request continues |
| the request is judged insecure | `Response::redirect('https://' . host . path . query, $status)` |

The redirect:

| | |
|---|---|
| Status | `$status` (`301` by default) |
| `Location` | `https://<host><path>[?<query>]`; the query is appended only when `$request->query` is non-empty, encoded with `http_build_query()` |
| Body | none |

"Secure" is decided in this order:

| # | Check | Secure when |
|---|---|---|
| 1 | `X-Forwarded-Proto`, only if `$trustForwardedProto` is `true` | the first comma-separated value, trimmed and lower-cased, is `https`; a non-empty value decides immediately |
| 2 | `$request->server['HTTPS']` | non-empty string whose lower-cased value is not `off` |
| 3 | `$request->server['REQUEST_SCHEME']` | string equal to `https` (case-insensitive) |
| 4 | `$request->server['SERVER_PORT']` | cast to string equals `443` |

The redirect host is taken from the `Host` header when it is a non-empty string, else from `SERVER_NAME`, else the literal `localhost`.

Throws `InvalidArgumentException('The redirect status must be a 3xx code.')` when `$status < 300` or `$status > 399`.

### Pitfalls
- `$trustForwardedProto` is off by default on purpose: `X-Forwarded-Proto` is client-writable. Enable it only behind a proxy you trust to overwrite the header. When enabled, it wins outright — a non-empty value short-circuits the whole check, so a proxy sending `http` can make a genuinely secure request look insecure.
- Only the **first** comma-separated value of `X-Forwarded-Proto` is read; the rest of a chained list is ignored.
- The host comes from the `Host` header verbatim, so it reflects what the client sent (falling back to `SERVER_NAME`, then `localhost`). The scheme is the only part rewritten — the port embedded in the host, if any, is preserved.
- The query is rebuilt through `http_build_query()`, so it is normalized, not byte-identical to the original.
- `301` is cached permanently by browsers. Where the method and body must survive the hop (a POST), prefer `new HttpsGuard(status: 308)`.
- This hook only redirects; it does not add HSTS, and it does not decide routing. Behind a proxy that speaks HTTP to PHP, nothing but the forwarded header reveals TLS.

### Composition & ordering
- Register it **before `CanonicalGuard`** among the redirecting guards. Fixing the scheme first means the canonical host/trailing-slash decision is made on a request that is already HTTPS, and the two redirects do not ping-pong.
- Relative to refusing guards, a scheme check is cheap and reveals nothing, so an early slot is normal. It can sit before or after auth/throttle without changing the outcome, since a secure request returns `null` and lets the chain continue.

### Typical scenarios
- Force HTTPS site-wide: `$rest->before(new HttpsGuard());`
- Preserve method and body on the hop: `new HttpsGuard(status: 308)`
- Behind a TLS-terminating load balancer: `new HttpsGuard(trustForwardedProto: true)`

---

## 中文

### 它解决什么问题

只要还有一部分流量走明文 HTTP，密码、会话 cookie 与令牌就在网络上裸奔，途中任何人都能读取或篡改它们。没有这个守卫，一个手敲了 `http://`、或点了旧链接进来的访客就会一直停在未加密连接上。这个守卫发现非安全的 scheme 后，把浏览器换到同一 URL 的 HTTPS 版本。

**术语**

- *HTTPS / TLS* —— 给 HTTP 加密的传输层。
- *redirect(重定向)* —— 让浏览器换一个地址重新访问。
- *301 与 308* —— 两者都是永久重定向；`308` 还会保留请求方法与请求体，`301` 不会。

### 它做什么
`HttpsGuard` 看请求是怎么到达的。已经是安全连接的请求原样继续（`null`）；以非安全 scheme 到达应用的请求，会被回以一个 `301`（默认），其 `Location` 是把同一个 URL 换成 `https` scheme 重建的结果。它在分发之前运行并短路分发，从不触碰处理器——一个纯粹的前置判定，这也正说明它是守卫而非中间件：出去的路上没有东西要跑。它只产出一个重定向，不添加 `Strict-Transport-Security` 响应头。

### 快速上手
最小片段：

```php
use MiGears\WebGuards\Https\HttpsGuard;

$rest->before(new HttpsGuard());
```

完整 bootstrap：

```php
use MiGears\Web\MiRest;
use MiGears\Web\Request;
use MiGears\WebGuards\Https\HttpsGuard;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

$rest = new MiRest(baseDir: __DIR__ . '/resources', namespace: 'App\\Resources');
$rest->set(LoggerInterface::class, fn () => new NullLogger());

// http:// 请求在任何处理器运行之前被移到 https://。
$rest->before(new HttpsGuard(status: 301));

$rest->handle(Request::fromGlobals())->send();
```

### API 参考
构造函数：

| 参数 | 类型 | 默认值 | 含义 |
|---|---|---|---|
| `$status` | `int` | `301` | 重定向状态码；须为 3xx |
| `$trustForwardedProto` | `bool` | `false` | 读取 `X-Forwarded-Proto`，以识别代理处的 TLS 终止 |

`__invoke(Request $request): ?Response`：

| 情形 | 结果 |
|---|---|
| 判定为安全请求 | `null` —— 请求继续 |
| 判定为非安全请求 | `Response::redirect('https://' . host . path . query, $status)` |

重定向：

| | |
|---|---|
| 状态码 | `$status`（默认 `301`） |
| `Location` | `https://<host><path>[?<query>]`；仅当 `$request->query` 非空时追加 query，用 `http_build_query()` 编码 |
| Body | 无 |

「安全」的判定顺序如下：

| # | 检查 | 视为安全的条件 |
|---|---|---|
| 1 | `X-Forwarded-Proto`，仅当 `$trustForwardedProto` 为 `true` | 第一个逗号分隔值 trim、转小写后为 `https`；只要值非空即立刻决定 |
| 2 | `$request->server['HTTPS']` | 非空字符串，且小写后不为 `off` |
| 3 | `$request->server['REQUEST_SCHEME']` | 字符串等于 `https`（大小写不敏感） |
| 4 | `$request->server['SERVER_PORT']` | 转成字符串后等于 `443` |

重定向的 host 依次取：`Host` 头（非空字符串时）→ `SERVER_NAME` → 字面量 `localhost`。

当 `$status < 300` 或 `$status > 399` 时，抛出 `InvalidArgumentException('The redirect status must be a 3xx code.')`。

### 常见坑与注意事项
- `$trustForwardedProto` 默认关闭是刻意的：`X-Forwarded-Proto` 可被客户端写入。仅在你信得过、会覆写该头的代理后面才开启。开启后它一票定音——只要值非空就短路整个判定，所以代理若发来 `http`，一个本已安全的请求也会被误判为不安全。
- 只读取 `X-Forwarded-Proto` 的**第一个**逗号分隔值；链式列表的其余部分被忽略。
- host 逐字取自 `Host` 头，所以它反映的是客户端发来的内容（回退到 `SERVER_NAME`，再回退到 `localhost`）。只有 scheme 被改写——host 里内嵌的端口（若有）会被保留。
- query 会经 `http_build_query()` 重建，因此是被规范化的，而非与原串逐字节相同。
- `301` 会被浏览器永久缓存。方法体与请求体必须跨跳存续（例如 POST）时，请改用 `new HttpsGuard(status: 308)`。
- 这个钩子只做重定向；不加 HSTS，也不决定路由。在代理以 HTTP 与 PHP 通信的场景里，除了转发的头以外没有任何东西能揭示 TLS。

### 组合与顺序
- 在几个跳转型守卫中，把它注册在 **`CanonicalGuard` 之前**。先定下 scheme，意味着 canonical 的 host / 尾斜杠决策是在一个已经是 HTTPS 的请求上做出的，两个重定向不会来回打乒乓。
- 相对拒绝型守卫，scheme 检查很廉价也不泄露任何信息，所以放在靠前的位置很常见。放在认证 / 限流之前或之后都不改变结果，因为安全请求会返回 `null` 让链继续。

### 典型场景
- 全站强制 HTTPS：`$rest->before(new HttpsGuard());`
- 跳转时保留方法体与请求体：`new HttpsGuard(status: 308)`
- 位于做 TLS 终止的负载均衡之后：`new HttpsGuard(trustForwardedProto: true)`
