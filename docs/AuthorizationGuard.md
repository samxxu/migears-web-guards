# AuthorizationGuard

> An authorization `before()` hook that refuses a request the caller may not make with a `403`.
> 一个授权 `before()` 钩子：调用方无权发起的请求以 `403` 被拒绝。

## English

### The problem it solves

Being logged in is not the same as being allowed. A signed-in customer should not be able to read someone else's order, and an ordinary user should not be able to call an admin-only action. Without this layer, passing authentication is enough to do anything. `AuthorizationGuard` checks the request against your policy and refuses a caller who may not make it with a `403`.

**Terms**

- *authorization* — Deciding what an identified caller may do.
- *role / permission* — A label for a caller (role) or a specific action they may take (permission).
- *403 Forbidden* — The status meaning "I know who you are, but you may not do this".
- *policy* — Your rule for that decision.

### What it does

`AuthorizationGuard` decides what an already-identified caller *may do*. It calls the policy callable you supply with the `Request`; when the callable returns `true` the guard returns `null` and the request continues, and when it returns `false` the guard returns a `403` and short-circuits the `before()` chain before routing. The policy is entirely yours — a role, a permission, an ownership rule — so the guard carries no role model and no state. It stands on its own: it does not look at whether the caller is authenticated, so an anonymous caller that fails the policy gets a `403`, not a `401`. It is a guard rather than a middleware because the decision is a pure pre-decision with nothing to run on the way out.

### Quick start

The smallest wiring:

```php
use MiGears\Web\Request;
use MiGears\WebGuards\Authorization\AuthorizationGuard;

$guard = new AuthorizationGuard(
    static fn (Request $request): bool => in_array('admin', $_SESSION['roles'] ?? [], true),
);

$rest->before($guard);
```

A complete bootstrap:

```php
use MiGears\Web\MiRest;
use MiGears\Web\Request;
use MiGears\WebGuards\Auth\AuthGuard;
use MiGears\WebGuards\Authorization\AuthorizationGuard;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

$rest = new MiRest(baseDir: __DIR__ . '/resources', namespace: 'App\\Resources');
$rest->set(LoggerInterface::class, static fn (): LoggerInterface => new NullLogger());

// AuthGuard first: authentication says who; this says what they may do.
$rest->before(new AuthGuard(
    static fn (Request $request): bool => ($_SESSION['user'] ?? null) !== null,
));

$rest->before(new AuthorizationGuard(
    static fn (Request $request): bool => in_array('admin', $_SESSION['roles'] ?? [], true),
));

$rest->handle(Request::fromGlobals())->send();
```

### API reference

Constructor:

| Parameter | Type | Default | Meaning |
|---|---|---|---|
| `$authorize` | `callable(Request): bool` | — (required) | Returns `true` when the request may proceed. |

`__invoke(Request $request): ?Response`:

| Callable result | Outcome | Status | Response headers | Body |
|---|---|---|---|---|
| `true` | request continues | — | — | — |
| `false` | request refused | `403` | `Content-Type: application/json; charset=utf-8` | `{"error":"Forbidden"}` |

Exceptions: none. The constructor validates nothing and `__invoke()` throws nothing. An exception thrown *inside* your callable is not caught, propagates out of `MiRest::handle()`, and becomes a `500`.

### Pitfalls

- It sees only the `Request`. Values the locator captured as route parameters are handed to the matched resource's constructor, not placed on the `Request`, so an ownership check has to derive the id from `$request->path` / `$request->query` itself — or the check belongs in the resource's own `before()`.
- It does not authenticate. Used alone, an anonymous caller that fails the policy gets a `403`; register `AuthGuard` first if you want a `401` for unauthenticated callers.
- A `403` reveals that the resource exists but is forbidden. If resource existence itself is sensitive, answer `404` from your own policy path instead.
- The `403` body is fixed at `{"error":"Forbidden"}`. Wrap the guard if you need a different envelope.
- The callable runs on every request that reaches it, including ones that will end in a `404`.

### Composition & ordering

- Register it **after** `AuthGuard`, so that a `401` is decided before a `403` — authentication says who the caller is, authorization says what they may do.
- Register it **before** guards that assume an authorized caller (a per-user `ThrottleGuard`, for example), so unauthorized traffic is refused before that work happens.
- Like every `before()` hook, a non-`null` return short-circuits the chain, so nothing registered after a refusing `AuthorizationGuard` runs.

### Typical scenarios

- **Role check** — admit a fixed set of roles:
  ```php
  new AuthorizationGuard(static fn (Request $r): bool => in_array('editor', $_SESSION['roles'] ?? [], true));
  ```
- **Permission check** — a scope or permission carried by the caller:
  ```php
  new AuthorizationGuard(static fn (Request $r): bool => MyPolicy::can('orders:write', $r));
  ```
- **Ownership check** — the caller owns the id in the path:
  ```php
  new AuthorizationGuard(static fn (Request $r): bool => MyPolicy::owns($_SESSION['user']['id'], $r->path));
  ```

**See also:** [integration.md](integration.md) — driving this guard from `migears/acl-feature` (`AclFeature::allows()`), and why `AclFeature::assert()` turns a denial into a `500`.

## 中文

### 它解决什么问题

「已登录」不等于「被允许」。一个已登录的顾客不该读到别人的订单，一个普通用户不该调用管理员操作。没有这层检查，只要通过认证就什么都能做。`AuthorizationGuard` 拿请求去对照你的策略，把无权发起的调用方以 `403` 拒掉。

**术语**

- *authorization(授权)* —— 决定已识别的调用方能做什么。
- *role / permission(角色 / 权限)* —— 角色是给调用方贴的标签，权限是他可以执行的具体动作。
- *403 Forbidden* —— 表示「我知道你是谁，但你无权做这件事」的状态码。
- *policy(策略)* —— 你用来做这项判断的规则。

### 它做什么

`AuthorizationGuard` 判定一个已被识别的调用方**能做什么**。它把你提供的策略回调连同 `Request` 一起调用；回调返回 `true` 时守卫返回 `null`，请求继续；返回 `false` 时守卫返回 `403`，并在路由之前短路 `before()` 链。策略完全由你决定——角色、权限、归属规则——守卫本身不带任何角色模型或状态。它独立成立：它不关心调用方是否已认证，所以未通过策略的匿名调用方拿到的是 `403` 而非 `401`。它之所以是 guard 而不是 middleware，是因为这个判定是纯粹的前置判定，回程无事可做。

### 快速上手

最小接线：

```php
use MiGears\Web\Request;
use MiGears\WebGuards\Authorization\AuthorizationGuard;

$guard = new AuthorizationGuard(
    static fn (Request $request): bool => in_array('admin', $_SESSION['roles'] ?? [], true),
);

$rest->before($guard);
```

一个完整的启动示例：

```php
use MiGears\Web\MiRest;
use MiGears\Web\Request;
use MiGears\WebGuards\Auth\AuthGuard;
use MiGears\WebGuards\Authorization\AuthorizationGuard;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

$rest = new MiRest(baseDir: __DIR__ . '/resources', namespace: 'App\\Resources');
$rest->set(LoggerInterface::class, static fn (): LoggerInterface => new NullLogger());

// AuthGuard 在前：认证说明是谁；本守卫说明他能做什么。
$rest->before(new AuthGuard(
    static fn (Request $request): bool => ($_SESSION['user'] ?? null) !== null,
));

$rest->before(new AuthorizationGuard(
    static fn (Request $request): bool => in_array('admin', $_SESSION['roles'] ?? [], true),
));

$rest->handle(Request::fromGlobals())->send();
```

### API 参考

构造函数：

| 参数 | 类型 | 默认值 | 含义 |
|---|---|---|---|
| `$authorize` | `callable(Request): bool` | ——（必填） | 请求可以继续时返回 `true`。 |

`__invoke(Request $request): ?Response`：

| 回调结果 | 结果 | 状态码 | 响应头 | 响应体 |
|---|---|---|---|---|
| `true` | 请求继续 | —— | —— | —— |
| `false` | 请求被拒 | `403` | `Content-Type: application/json; charset=utf-8` | `{"error":"Forbidden"}` |

异常：无。构造函数不做任何校验，`__invoke()` 也不抛异常。若你的回调内部抛出异常，本守卫不会捕获，它会穿出 `MiRest::handle()`，成为 `500`。

### 常见坑与注意事项

- 它只能看到 `Request`。定位器捕获为路由参数的值是交给匹配到的资源构造函数的，并不会放到 `Request` 上，因此归属检查得自己从 `$request->path` / `$request->query` 里取出 id——或者把这项检查放进资源自己的 `before()`。
- 它不做认证。单独使用时，未通过策略的匿名调用方拿到的是 `403`；若希望匿名调用方得到 `401`，请先注册 `AuthGuard`。
- `403` 会泄露「资源存在但被禁止」。若资源是否存在本身就敏感，应在你自己的策略路径上改答 `404`。
- `403` 的响应体固定为 `{"error":"Forbidden"}`。若需要别的结构，请包一层守卫。
- 回调在每一个到达它的请求上都会执行，包括最终会 404 的那些。

### 组合与顺序

- 注册在 `AuthGuard` **之后**，让 `401` 先于 `403` 被判定——认证说明调用方是谁，授权说明他能做什么。
- 注册在「假定调用方已获授权」的守卫**之前**（例如按用户计的 `ThrottleGuard`），使未授权流量在该工作发生之前就被拒绝。
- 与所有 `before()` 钩子一样，非 `null` 返回会短路链，所以 `AuthorizationGuard` 拒绝时，注册在其后的守卫都不会执行。

### 典型场景

- **角色检查**——只放行一组固定角色：
  ```php
  new AuthorizationGuard(static fn (Request $r): bool => in_array('editor', $_SESSION['roles'] ?? [], true));
  ```
- **权限检查**——调用方携带的 scope 或权限：
  ```php
  new AuthorizationGuard(static fn (Request $r): bool => MyPolicy::can('orders:write', $r));
  ```
- **归属检查**——调用方拥有路径里的那个 id：
  ```php
  new AuthorizationGuard(static fn (Request $r): bool => MyPolicy::owns($_SESSION['user']['id'], $r->path));
  ```

**另见:**[integration.md](integration.md) —— 如何用 `migears/acl-feature`(`AclFeature::allows()`)驱动本守卫,以及为什么 `AclFeature::assert()` 会把拒绝变成 `500`。
