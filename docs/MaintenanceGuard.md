# MaintenanceGuard

> A `before()` hook that answers every request with `503 Service Unavailable` and a `Retry-After` while planned maintenance is on.
> 一个在计划内维护期间把所有请求回以 `503 Service Unavailable` 与 `Retry-After` 的 `before()` 钩子。

## English

### The problem it solves

A deploy or a data migration is half-finished, leaving the application in a broken in-between state. Requests keep arriving, and instead of a clear answer they fail in confusing, inconsistent ways. You want a switch that tells every visitor "we are down right now, come back in X seconds" — one you can flip without shipping a new release.

**Terms**

- *maintenance mode* — a window in which the whole site stops serving the public.
- *503 Service Unavailable* — the status that says "the service cannot handle this right now".
- *Retry-After* — the response header that tells a client how long to wait before trying again.
- *bypass / allowlist* — the set of callers still allowed through during maintenance, such as an internal network or an admin.

### What it does
While the `enabled` callable returns `true`, `MaintenanceGuard` refuses every request with a `503` and a `Retry-After` that says when to come back. An optional `allowWhen` callable lets a chosen set of callers — an internal IP range, an admin session, a status endpoint — through while everyone else is held out. It runs before dispatch and short-circuits it, and it never touches a handler: it is a pure pre-decision, which is exactly why it is a guard and not a middleware — there is nothing to run on the way out.

### Quick start
Minimal:

```php
use MiGears\WebGuards\Maintenance\MaintenanceGuard;

$rest->before(new MaintenanceGuard(fn (): bool => is_file(__DIR__ . '/maintenance.flag')));
```

Full bootstrap:

```php
use MiGears\Web\MiRest;
use MiGears\Web\Request;
use MiGears\WebGuards\Maintenance\MaintenanceGuard;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

$rest = new MiRest(baseDir: __DIR__ . '/resources', namespace: 'App\\Resources');
$rest->set(LoggerInterface::class, fn () => new NullLogger());

$rest->before(new MaintenanceGuard(
    enabled: fn (): bool => is_file(__DIR__ . '/maintenance.flag'),
    allowWhen: static fn (Request $r): bool => str_starts_with($r->path, '/status'),
    retryAfterSeconds: 900,
));

$rest->handle(Request::fromGlobals())->send();
```

### API reference
Constructor:

| Parameter | Type | Default | Meaning |
|---|---|---|---|
| `$enabled` | `callable(): bool` | — (required) | `true` while maintenance is on |
| `$allowWhen` | `?callable(Request): bool` | `null` | `true` to bypass maintenance for this request |
| `$retryAfterSeconds` | `int` | `3600` | value sent in `Retry-After`; must be `>= 1` |

`__invoke(Request $request): ?Response`:

| Case | Result |
|---|---|
| `enabled()` is `false` | `null` — the request continues |
| `enabled()` is `true`, `allowWhen` is set, `allowWhen($request)` is `true` | `null` — the request continues |
| otherwise | `503`, header `Content-Type: application/json; charset=utf-8`, header `Retry-After: <retryAfterSeconds>`, body `{"error":"Service Unavailable"}` |

Throws `InvalidArgumentException` with the message `The retry-after must be at least 1 second.` when `$retryAfterSeconds < 1`.

### Pitfalls
- `enabled` is called with **no arguments** — `($this->enabled)()`. Passing a callable that requires a `Request`, such as `fn (Request $r) => ...`, fails with `ArgumentCountError` on the first request, not at construction. The switch is global, so it receives nothing.
- `allowWhen` is only consulted once `enabled()` is already `true`, and nothing about the maintenance state is passed to it; it answers "does this caller stay out?" from the request alone.
- `Retry-After` is a fixed number, not a deadline: it reports how many seconds to wait from *now*, and it is the same value on every request for as long as maintenance lasts.
- Both callables are wrapped with `Closure::fromCallable()` in the constructor, so a closure, an invokable object, or a `[$obj, 'method']` array all work — but an unmatched method name throws there.

### Composition & ordering
Register it **first** in the `before()` chain. Hooks run in registration order and the first `Response` wins, so putting maintenance ahead of auth, throttling and the rest means an outage answers every request — unauthenticated or malformed ones included — with the `503` and the `Retry-After`, and no later guard or handler spends work. A resource-level `before()` could do the same, but a planned outage is global by nature.

### Typical scenarios
- Flag file: `fn (): bool => is_file(__DIR__ . '/maintenance.flag')` — flip the file, no deploy.
- Scheduled window: `fn (): bool => time() >= $start && time() < $end`.
- Keep a status endpoint reachable: `allowWhen: fn (Request $r) => str_starts_with($r->path, '/status')`.
- Hold everyone but internal IPs: `allowWhen: fn (Request $r) => str_starts_with($r->server['REMOTE_ADDR'] ?? '', '10.')`.

---

## 中文

### 它解决什么问题

一次上线部署或数据迁移只做了一半,应用正处在半成品状态。请求还在不断涌进来,它们得不到清楚的回答,而是以各种令人困惑、前后不一的方式失败。你需要一个开关:打开时,它对每个访客回答「我们现在下线了,请 X 秒后再来」;而且你希望能随时拨动它,不用为此发一个新版本。

**术语**

- *maintenance mode(维护模式)* —— 整站暂停对外服务的一段时间。
- *503 Service Unavailable* —— 表示「服务此刻无法处理请求」的状态码。
- *Retry-After* —— 告诉客户端多久之后再试的响应头。
- *bypass / allowlist(放行名单)* —— 维护期间仍允许访问的调用方,例如内网或管理员。

### 它做什么
只要 `enabled` 回调返回 `true`,`MaintenanceGuard` 就把每个请求回以 `503` 和一条 `Retry-After`,告诉客户端何时再回来。可选的 `allowWhen` 回调能让选定的一批调用方——内网 IP 段、管理员会话、状态端点——在其他人被挡在外面时通过。它在分发之前运行并短路分发,从不触碰处理器:它是一个纯粹的前置判定,这也正说明它是守卫而非中间件——出去的路上没有东西要跑。

### 快速上手
最小片段:

```php
use MiGears\WebGuards\Maintenance\MaintenanceGuard;

$rest->before(new MaintenanceGuard(fn (): bool => is_file(__DIR__ . '/maintenance.flag')));
```

完整 bootstrap:

```php
use MiGears\Web\MiRest;
use MiGears\Web\Request;
use MiGears\WebGuards\Maintenance\MaintenanceGuard;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

$rest = new MiRest(baseDir: __DIR__ . '/resources', namespace: 'App\\Resources');
$rest->set(LoggerInterface::class, fn () => new NullLogger());

$rest->before(new MaintenanceGuard(
    enabled: fn (): bool => is_file(__DIR__ . '/maintenance.flag'),
    allowWhen: static fn (Request $r): bool => str_starts_with($r->path, '/status'),
    retryAfterSeconds: 900,
));

$rest->handle(Request::fromGlobals())->send();
```

### API 参考
构造函数:

| 参数 | 类型 | 默认值 | 含义 |
|---|---|---|---|
| `$enabled` | `callable(): bool` | —(必填) | 维护开启期间返回 `true` |
| `$allowWhen` | `?callable(Request): bool` | `null` | 返回 `true` 表示该请求放行、绕过维护 |
| `$retryAfterSeconds` | `int` | `3600` | 写入 `Retry-After` 的值;须 `>= 1` |

`__invoke(Request $request): ?Response`:

| 情形 | 结果 |
|---|---|
| `enabled()` 为 `false` | `null` —— 请求继续 |
| `enabled()` 为 `true`,且 `allowWhen` 存在、`allowWhen($request)` 为 `true` | `null` —— 请求继续 |
| 其余情形 | `503`,响应头 `Content-Type: application/json; charset=utf-8`,响应头 `Retry-After: <retryAfterSeconds>`,响应体 `{"error":"Service Unavailable"}` |

当 `$retryAfterSeconds < 1` 时,抛出 `InvalidArgumentException`,消息为 `The retry-after must be at least 1 second.`。

### 常见坑与注意事项
- `enabled` 是**不带参数**调用的——`($this->enabled)()`。传一个要求 `Request` 的回调,例如 `fn (Request $r) => ...`,会在第一个请求上抛 `ArgumentCountError`,而不是在构造时。这个开关是全局的,拿不到请求。
- `allowWhen` 只在 `enabled()` 已经为 `true` 之后才会被询问,而且维护状态不会传给它;它只能凭请求本身回答「这个调用方是否留在外面」。
- `Retry-After` 是个固定数字,不是截止时刻:它报告的是「从现在起还要等多少秒」,只要维护持续,每个请求拿到的都是同一个值。
- 两个回调都在构造函数里经 `Closure::fromCallable()` 包装,所以闭包、可调用对象、`[$obj, 'method']` 数组都行——但方法名对不上时会当场抛异常。

### 组合与顺序
把它注册在 `before()` 链的**最前面**。钩子按注册顺序执行,第一个返回的 `Response` 胜出;把维护放在认证、限流等等之前,意味着停机期间每个请求——包括未认证或畸形的请求——都拿到那条 `503` 与 `Retry-After`,后面的守卫和处理器一律不做功。挂在资源级 `before()` 也能达到同样效果,但计划内停机本来就是全局的。

### 典型场景
- 开关文件:`fn (): bool => is_file(__DIR__ . '/maintenance.flag')` —— 改文件即生效,无需部署。
- 定时窗口:`fn (): bool => time() >= $start && time() < $end`。
- 留一个状态端点可用:`allowWhen: fn (Request $r) => str_starts_with($r->path, '/status')`。
- 只对内网放行:`allowWhen: fn (Request $r) => str_starts_with($r->server['REMOTE_ADDR'] ?? '', '10.')`。
