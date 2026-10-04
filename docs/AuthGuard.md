# AuthGuard

> An authentication `before()` hook that refuses a request it cannot authenticate with a `401` and a `WWW-Authenticate` challenge.
> 一个认证 `before()` 钩子：无法通过认证的请求以 `401` 加 `WWW-Authenticate` 质询被拒绝。

## English

### The problem it solves

A private endpoint should never answer a stranger. Without any check, anyone who knows the URL can fetch that data — the endpoint has no way to tell a legitimate caller from an intruder. `AuthGuard` asks one yes/no question — is this caller authenticated? — and refuses with a `401` when the answer is no, so the request never reaches your resource.

**Terms**

- *authentication* — Establishing who the caller is.
- *credential* — Something that proves identity, such as a token, password, or cookie.
- *bearer token* — A token that grants access to whoever holds it.
- *401 Unauthorized* — The status meaning "you are not authenticated".
- *WWW-Authenticate* — The header on a `401` telling the client how it should authenticate.

### What it does

`AuthGuard` answers exactly one question — is this caller authenticated? — by calling the policy callable you supply with the `Request`. When the callable returns `true` the guard returns `null` and the request continues down the `before()` chain; when it returns `false` the guard returns a `401` and the request is short-circuited before routing runs. What "authenticated" means is entirely your policy — a bearer token, a session, a signed cookie, an API key — so the guard itself parses no credential and keeps no state. It is a guard rather than a middleware because the decision is a pure pre-decision: allow or refuse, with nothing to do on the way back out, so `before()` is the only hook it needs.

### Quick start

The smallest wiring — a callable plus `before()`:

```php
use MiGears\Web\Request;
use MiGears\WebGuards\Auth\AuthGuard;

$guard = new AuthGuard(
    static fn (Request $request): bool => $request->header('Authorization') === 'Bearer ' . getenv('API_TOKEN'),
);

$rest->before($guard);
```

A complete bootstrap:

```php
use MiGears\Web\MiRest;
use MiGears\Web\Request;
use MiGears\WebGuards\Auth\AuthGuard;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

$rest = new MiRest(baseDir: __DIR__ . '/resources', namespace: 'App\\Resources');
$rest->set(LoggerInterface::class, static fn (): LoggerInterface => new NullLogger());

$rest->before(new AuthGuard(
    static fn (Request $request): bool => $request->header('Authorization') === 'Bearer ' . getenv('API_TOKEN'),
    challenge: 'Bearer',
));

$rest->handle(Request::fromGlobals())->send();
```

### API reference

Constructor:

| Parameter | Type | Default | Meaning |
|---|---|---|---|
| `$authenticate` | `callable(Request): bool` | — (required) | Returns `true` when the request is authenticated. |
| `$challenge` | `string` | `'Bearer'` | The value of the `WWW-Authenticate` header sent with a `401`. |

`__invoke(Request $request): ?Response`:

| Callable result | Outcome | Status | Response headers | Body |
|---|---|---|---|---|
| `true` | request continues | — | — | — |
| `false` | request refused | `401` | `WWW-Authenticate: <challenge>` and `Content-Type: application/json; charset=utf-8` | `{"error":"Unauthorized"}` |

Exceptions: none. The constructor validates nothing and `__invoke()` throws nothing. An exception thrown *inside* your callable is not caught, propagates out of `MiRest::handle()`, and the framework's error handling turns it into a `500`.

### Pitfalls

- The callable runs on every request that reaches the guard — including ones that will end in a `404` — so keep it cheap. A network round-trip in the policy callable is a latency and availability risk on every request.
- `$challenge` is written to the header verbatim. A `401` is required to say how to authenticate, so set it to the real scheme (`'Bearer'`, `'Basic realm="api"'`) rather than a placeholder.
- The guard compares nothing itself. `WWW-Authenticate` tells the client how it *may* authenticate; the guard does not verify that the request actually used that scheme — your callable does.
- The `401` body is fixed at `{"error":"Unauthorized"}`. If you need a different envelope, wrap the guard and translate the response.

### Composition & ordering

- Register it **before** `AuthorizationGuard`. Authentication says who the caller is; authorization says what they may do, and a `403` only makes sense once a `401` would not. With `AuthGuard` first, an anonymous caller is stopped by a `401` before the authorization policy ever runs.
- Relative to `ThrottleGuard`, the order follows what the throttle keys on: a coarse per-IP anti-flood limit belongs before `AuthGuard`, so it also protects the authentication work; a limit keyed per authenticated user belongs after it. See `docs/ThrottleGuard.md`.
- The `before()` chain short-circuits on the first non-`null` return, so no guard registered after a refusing `AuthGuard` runs on that request.

### Typical scenarios

- **Static API token** — compare the `Authorization` header against an environment secret:
  ```php
  new AuthGuard(static fn (Request $r): bool => $r->header('Authorization') === 'Bearer ' . getenv('API_TOKEN'));
  ```
- **Session login** — continue only when a session user is present:
  ```php
  new AuthGuard(static fn (Request $r): bool => ($_SESSION['user'] ?? null) !== null);
  ```
- **Signed cookie** — verify an HMAC cookie at the edge:
  ```php
  new AuthGuard(static fn (Request $r): bool => Cookie::verify($r->header('Cookie')));
  ```

**See also:** [integration.md](integration.md) — wiring this guard with `migears/security` (`AuthInterface` / `MiAuth`) without adding a dependency.

## 中文

### 它解决什么问题

一个返回私有数据的接口，绝不该回答一个陌生人。没有任何检查时，任何人只要知道那个 URL 就能拿到数据——接口无从分辨合法调用方和闯入者。`AuthGuard` 只问一个「是／否」问题——这个调用方通过认证了吗？——答否就回 `401`，请求因此根本到不了你的资源。

**术语**

- *authentication(认证)* —— 确认调用方是谁。
- *credential(凭据)* —— 证明身份的东西，如 token、密码、cookie。
- *bearer token(持有者令牌)* —— 谁拿着它谁就被当作已认证。
- *401 Unauthorized* —— 表示「你没通过认证」的状态码。
- *WWW-Authenticate* —— `401` 上告诉客户端该怎么认证的响应头。

### 它做什么

`AuthGuard` 只回答一个问题——这个调用方通过认证了吗？——做法是把你提供的策略回调连同 `Request` 一起调用。回调返回 `true` 时守卫返回 `null`，请求沿 `before()` 链继续；返回 `false` 时守卫返回 `401`，请求在路由执行之前就被短路。什么算「通过认证」完全由你的策略决定——bearer token、会话、签名 cookie、API key——所以守卫自身不解析任何凭据，也不持有任何状态。它之所以是 guard 而不是 middleware，是因为这个判定是纯粹的前置判定：放行或拒绝，回程无事可做，因此它只需 `before()` 这一个钩子。

### 快速上手

最小接线——一个回调加 `before()`：

```php
use MiGears\Web\Request;
use MiGears\WebGuards\Auth\AuthGuard;

$guard = new AuthGuard(
    static fn (Request $request): bool => $request->header('Authorization') === 'Bearer ' . getenv('API_TOKEN'),
);

$rest->before($guard);
```

一个完整的启动示例：

```php
use MiGears\Web\MiRest;
use MiGears\Web\Request;
use MiGears\WebGuards\Auth\AuthGuard;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

$rest = new MiRest(baseDir: __DIR__ . '/resources', namespace: 'App\\Resources');
$rest->set(LoggerInterface::class, static fn (): LoggerInterface => new NullLogger());

$rest->before(new AuthGuard(
    static fn (Request $request): bool => $request->header('Authorization') === 'Bearer ' . getenv('API_TOKEN'),
    challenge: 'Bearer',
));

$rest->handle(Request::fromGlobals())->send();
```

### API 参考

构造函数：

| 参数 | 类型 | 默认值 | 含义 |
|---|---|---|---|
| `$authenticate` | `callable(Request): bool` | ——（必填） | 请求已通过认证时返回 `true`。 |
| `$challenge` | `string` | `'Bearer'` | 随 `401` 一起发送的 `WWW-Authenticate` 头的值。 |

`__invoke(Request $request): ?Response`：

| 回调结果 | 结果 | 状态码 | 响应头 | 响应体 |
|---|---|---|---|---|
| `true` | 请求继续 | —— | —— | —— |
| `false` | 请求被拒 | `401` | `WWW-Authenticate: <challenge>` 与 `Content-Type: application/json; charset=utf-8` | `{"error":"Unauthorized"}` |

异常：无。构造函数不做任何校验，`__invoke()` 也不抛异常。若你的回调内部抛出异常，本守卫不会捕获，它会穿出 `MiRest::handle()`，由框架的异常处理转成 `500`。

### 常见坑与注意事项

- 回调在每一个到达守卫的请求上都会执行——包括最终会 404 的那些——所以务必让它便宜。在策略回调里做一次网络往返，等于给每个请求都加上延迟与可用性风险。
- `$challenge` 会原样写入响应头。`401` 必须说明如何认证，所以要设成真实的方案（`'Bearer'`、`'Basic realm="api"'`），不要用占位符。
- 守卫自身什么都不比对。`WWW-Authenticate` 只是告诉客户端可以怎样认证；守卫不校验请求是否真的用了该方案——那是你的回调的事。
- `401` 的响应体固定为 `{"error":"Unauthorized"}`。若需要别的结构，请包一层守卫并转换响应。

### 组合与顺序

- 注册在 `AuthorizationGuard` **之前**。认证说明调用方是谁，授权说明他能做什么，只有在 `401` 不成立时 `403` 才说得通。`AuthGuard` 在前，匿名调用方会在授权策略运行之前就被 `401` 拦下。
- 相对 `ThrottleGuard`，顺序取决于限流以什么为键：粗粒度的按 IP 反洪泛限制应排在 `AuthGuard` 之前，这样它同时也保护了认证工作；按已认证用户为键的限制则排在其后。参见 `docs/ThrottleGuard.md`。
- `before()` 链在第一个非 `null` 返回值处短路，所以 `AuthGuard` 拒绝时，注册在其后的守卫都不会执行。

### 典型场景

- **静态 API token**——把 `Authorization` 头与环境密钥比对：
  ```php
  new AuthGuard(static fn (Request $r): bool => $r->header('Authorization') === 'Bearer ' . getenv('API_TOKEN'));
  ```
- **会话登录**——仅当会话中存在用户时放行：
  ```php
  new AuthGuard(static fn (Request $r): bool => ($_SESSION['user'] ?? null) !== null);
  ```
- **签名 cookie**——在边缘校验一枚 HMAC cookie：
  ```php
  new AuthGuard(static fn (Request $r): bool => Cookie::verify($r->header('Cookie')));
  ```

**另见:**[integration.md](integration.md) —— 如何在不加依赖的前提下,把本守卫与 `migears/security`(`AuthInterface` / `MiAuth`)接起来。
