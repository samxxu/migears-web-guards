# CsrfGuard

> A CSRF `before()` hook that verifies state-changing requests with your token policy and refuses a mismatch with a `403`.
> 一个 CSRF `before()` 钩子：用你的 token 策略校验改动型请求，不匹配时以 `403` 拒绝。

## English

### The problem it solves

A page on another website can make a victim's browser send a request to your site, and the browser automatically attaches the victim's cookies — so your site sees a logged-in user and trusts the request. A single click can change a password or delete data. A CSRF token forces the request to prove it came from your own page.

**Terms**

- *CSRF (cross-site request forgery)* — Abusing a user's logged-in browser to send a request from another site as if it were the user.
- *CSRF token* — A random value only your own pages can obtain, submitted with the request to prove its origin.
- *safe vs mutating method* — Safe methods such as `GET`/`HEAD` only read; mutating methods such as `POST`/`PUT`/`PATCH`/`DELETE` change state.
- *origin* — Which site the request came from.

### What it does

`CsrfGuard` runs your token verifier only on methods that can change state. A request whose method is not in the protected set (by default `POST`, `PUT`, `PATCH`, `DELETE`) passes through untouched; for every protected method the guard calls the verifier and returns a `403` when it says the token is invalid. The verifier is your policy — compare a form field, an `X-CSRF-Token` header, or a double-submit cookie against the session — so the guard decides only *when* to ask, never *how*. Method names are normalized with `strtoupper(trim(...))`, so `'post'` protects exactly the same requests as `'POST'`. It is a guard rather than a middleware because the check is a pure pre-decision with nothing to run on the way out.

### Quick start

The smallest wiring:

```php
use MiGears\Web\Request;
use MiGears\WebGuards\Csrf\CsrfGuard;

$guard = new CsrfGuard(
    static fn (Request $request): bool => hash_equals($_SESSION['csrf'] ?? '', (string) $request->header('X-CSRF-Token')),
);

$rest->before($guard);
```

A complete bootstrap:

```php
use MiGears\Web\MiRest;
use MiGears\Web\Request;
use MiGears\WebGuards\Csrf\CsrfGuard;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

$rest = new MiRest(baseDir: __DIR__ . '/resources', namespace: 'App\\Resources');
$rest->set(LoggerInterface::class, static fn (): LoggerInterface => new NullLogger());

$rest->before(new CsrfGuard(
    static fn (Request $request): bool => hash_equals($_SESSION['csrf'] ?? '', (string) $request->header('X-CSRF-Token')),
));

$rest->handle(Request::fromGlobals())->send();
```

### API reference

Constructor:

| Parameter | Type | Default | Meaning |
|---|---|---|---|
| `$verify` | `callable(Request): bool` | — (required) | Returns `true` when the request carries a valid token. |
| `$protectedMethods` | `list<string>` | `['POST', 'PUT', 'PATCH', 'DELETE']` | Methods to verify. Each name is trimmed and upper-cased at construction. |

`__invoke(Request $request): ?Response`:

| Condition | Outcome | Status | Response headers | Body |
|---|---|---|---|---|
| `strtoupper($request->method)` not in the protected set | request continues | — | — | — |
| protected method, `$verify` returns `true` | request continues | — | — | — |
| protected method, `$verify` returns `false` | request refused | `403` | `Content-Type: application/json; charset=utf-8` | `{"error":"CSRF token mismatch"}` |

Exceptions: the constructor throws `InvalidArgumentException` when a protected method name is empty after `trim()` (message: `A protected method name must not be empty.`). `__invoke()` throws nothing; an exception thrown *inside* your verifier is not caught and becomes a `500`.

### Pitfalls

- An empty `$protectedMethods` list protects nothing — every request passes, including mutations. This is dangerous but deliberate: the guard does not invent a default once you have supplied a list.
- Only the *method* is inspected. A `GET` that mutates state is never verified; keep state-changing reads out of the API, or protect the method explicitly.
- The verifier receives the `Request` only, so it must look the token up itself (form field, header, cookie) and compare it in constant time (`hash_equals`) against the session value.
- `'post'`, `' POST '` and `'POST'` all normalize to the same entry. Duplicates are not collapsed, but they are harmless.
- The `403` body is fixed at `{"error":"CSRF token mismatch"}`.

### Composition & ordering

- Register it after `AuthGuard` and `AuthorizationGuard`: CSRF only matters for a caller you have already identified and authorized, and a `401`/`403` for identity should be decided first.
- It is safe to register globally — `GET`, `HEAD` and `OPTIONS` are not touched — so a single registration covers every mutating route.
- Because a non-`null` return short-circuits the `before()` chain, nothing registered after a refusing `CsrfGuard` runs on that request.

### Typical scenarios

- **Header token** — a client sends the token in `X-CSRF-Token`:
  ```php
  new CsrfGuard(static fn (Request $r): bool => hash_equals($_SESSION['csrf'], (string) $r->header('X-CSRF-Token')));
  ```
- **Form field** — a classic hidden input on the parsed body:
  ```php
  new CsrfGuard(static fn (Request $r): bool => hash_equals($_SESSION['csrf'], (string) ($r->body['_token'] ?? '')));
  ```
- **Only mutations, custom set** — also protect `POST` performed via an unusual verb:
  ```php
  new CsrfGuard($verify, ['POST', 'PUT', 'PATCH', 'DELETE']);
  ```

## 中文

### 它解决什么问题

另一个网站上的页面可以让受害者的浏览器向你的站点发请求，而浏览器会自动带上受害者的 cookie——于是你的站点看到的是一个已登录用户，并信任了这个请求。一次点击就可能改掉密码或删掉数据。CSRF token 让请求必须证明它来自你自己的页面。

**术语**

- *CSRF(跨站请求伪造)* —— 借用户已登录的浏览器，从别的站点冒充用户发请求。
- *CSRF token* —— 一枚只有你自己的页面能拿到的随机值，随请求提交以自证来源。
- *safe vs mutating method(安全方法 vs 改动方法)* —— 安全方法如 `GET`/`HEAD` 只读取，改动方法如 `POST`/`PUT`/`PATCH`/`DELETE` 会改变状态。
- *origin(来源)* —— 请求来自哪个站点。

### 它做什么

`CsrfGuard` 只对可能改动状态的方法运行你的 token 校验器。方法不在受保护集合内的请求（默认集合为 `POST`、`PUT`、`PATCH`、`DELETE`）原样通过；对每一个受保护方法，守卫调用校验器，并在它判定 token 无效时返回 `403`。校验器是你的策略——把表单字段、`X-CSRF-Token` 头或双提交 cookie 与会话比对——所以守卫只决定**何时**发问，从不决定**如何**校验。方法名以 `strtoupper(trim(...))` 归一化，因此 `'post'` 与 `'POST'` 保护的是同一批请求。它之所以是 guard 而不是 middleware，是因为这项检查是纯粹的前置判定，回程无事可做。

### 快速上手

最小接线：

```php
use MiGears\Web\Request;
use MiGears\WebGuards\Csrf\CsrfGuard;

$guard = new CsrfGuard(
    static fn (Request $request): bool => hash_equals($_SESSION['csrf'] ?? '', (string) $request->header('X-CSRF-Token')),
);

$rest->before($guard);
```

一个完整的启动示例：

```php
use MiGears\Web\MiRest;
use MiGears\Web\Request;
use MiGears\WebGuards\Csrf\CsrfGuard;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

$rest = new MiRest(baseDir: __DIR__ . '/resources', namespace: 'App\\Resources');
$rest->set(LoggerInterface::class, static fn (): LoggerInterface => new NullLogger());

$rest->before(new CsrfGuard(
    static fn (Request $request): bool => hash_equals($_SESSION['csrf'] ?? '', (string) $request->header('X-CSRF-Token')),
));

$rest->handle(Request::fromGlobals())->send();
```

### API 参考

构造函数：

| 参数 | 类型 | 默认值 | 含义 |
|---|---|---|---|
| `$verify` | `callable(Request): bool` | ——（必填） | 请求携带有效 token 时返回 `true`。 |
| `$protectedMethods` | `list<string>` | `['POST', 'PUT', 'PATCH', 'DELETE']` | 需要校验的方法。每个名字在构造时被 trim 并转为大写。 |

`__invoke(Request $request): ?Response`：

| 条件 | 结果 | 状态码 | 响应头 | 响应体 |
|---|---|---|---|---|
| `strtoupper($request->method)` 不在受保护集合内 | 请求继续 | —— | —— | —— |
| 受保护方法，`$verify` 返回 `true` | 请求继续 | —— | —— | —— |
| 受保护方法，`$verify` 返回 `false` | 请求被拒 | `403` | `Content-Type: application/json; charset=utf-8` | `{"error":"CSRF token mismatch"}` |

异常：受保护方法名在 `trim()` 之后为空时，构造函数抛 `InvalidArgumentException`（消息：`A protected method name must not be empty.`）。`__invoke()` 不抛异常；若你的校验器内部抛出异常，本守卫不会捕获，它会成为 `500`。

### 常见坑与注意事项

- 空的 `$protectedMethods` 列表什么都不保护——每个请求都会通过，包括改动型请求。这很危险但属有意为之：一旦你提供了列表，守卫不会替你臆造一个默认值。
- 只检查**方法**。一个会改动状态的 `GET` 永远不会被校验；别让「读」接口改动状态，或者显式把该方法纳入保护。
- 校验器只拿到 `Request`，所以它必须自己去取 token（表单字段、响应头、cookie），并用常量时间比较（`hash_equals`）与会话值比对。
- `'post'`、`' POST '` 与 `'POST'` 都归一化为同一条目。重复项不会被去重，但无害。
- `403` 的响应体固定为 `{"error":"CSRF token mismatch"}`。

### 组合与顺序

- 注册在 `AuthGuard` 与 `AuthorizationGuard` **之后**：CSRF 只对已经识别并授权的调用方才有意义，身份层面的 `401`/`403` 应先判定。
- 可以安全地全局注册——`GET`、`HEAD`、`OPTIONS` 都不会被触碰——因此一次注册即可覆盖所有改动型路由。
- 由于非 `null` 返回会短路 `before()` 链，`CsrfGuard` 拒绝时，注册在其后的守卫都不会执行。

### 典型场景

- **请求头 token**——客户端在 `X-CSRF-Token` 里带上 token：
  ```php
  new CsrfGuard(static fn (Request $r): bool => hash_equals($_SESSION['csrf'], (string) $r->header('X-CSRF-Token')));
  ```
- **表单字段**——经典隐藏输入，落在已解析的请求体上：
  ```php
  new CsrfGuard(static fn (Request $r): bool => hash_equals($_SESSION['csrf'], (string) ($r->body['_token'] ?? '')));
  ```
- **只保护改动型方法，自定义集合**——把以非常规动词执行的 `POST` 也保护起来：
  ```php
  new CsrfGuard($verify, ['POST', 'PUT', 'PATCH', 'DELETE']);
  ```
