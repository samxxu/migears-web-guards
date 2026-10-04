# migears/web-guards

![Version](https://img.shields.io/badge/version-2.0.0-blue)

Guards for **migears/web**: small, independent objects that decide whether a request may continue, or refuse it with the response the situation calls for — `401`, `403`, `413`, `415`, `429`, `503`, or a redirect. The set covers authentication, authorization, CSRF, IP filtering, request signatures, rate limiting, maintenance and kill switches, user-agent filtering, HTTPS and canonical redirects, request checks, CORS and time windows.

> **Background**: miGears is the open-source successor of **TinyGears**, a self-developed PHP framework. It was renamed and open-sourced recently because the name *TinyGears* is already taken in the open-source community.

## How a guard works

### The contract

A guard is any object invokable as `callable(Request): ?Response`:

```php
public function __invoke(Request $request): ?Response;
```

Return `null` and the request continues down the chain. Return a `MiGears\Web\Response` and it is sent immediately — nothing further in the `before()` chain runs, and no resource handler ever does.

### A flat chain, not a pipeline

miGears' `before()` / `after()` are a **flat hook chain**, not a pipeline. The global `before` list runs first and short-circuits the moment one hook returns a `Response`; dispatch runs; then the global `after` list runs, in registration order. There is no `next()`: no unit can wrap the handler and run on both sides of it, and the `after` list is not unwound in reverse — so there is no onion for a "layer" to be part of.

That is why these classes are called **guards** and not middleware. A guard is a pure **pre-decision** — allow, or refuse — so it needs `before()` and never `after()`; calling it a middleware would promise a pipeline the framework does not have.

### What follows from the chain

- **Order is meaningful.** The chain stops at the first refusal, so the order you register decides which response wins. Register `AuthGuard` before `AuthorizationGuard` and an anonymous caller gets a `401`, not a `403`.
- **Guards are independent.** Each one takes its policy as a callable, or a small config object, and holds no shared state. None of them needs the container, and none knows about any other — so you compose exactly the ones you want.
- **Wiring is one line.** `$rest->before($guard)` is the whole integration.
- **Response decoration is not a guard.** A concern that must add headers to a response it did not produce (CORS on an actual response, a security header) belongs on the `after()` chain, because a `before()` hook cannot touch a response that is not its own. `CorsGuard` is the one guard shipped with such a partner — `CorsHeaders`.

### Global or resource-level

Two `before()` hooks accept a guard; they see different things:

| | `MiRest::before()` | a resource's `before()` |
|---|---|---|
| Runs | before the locator, on every request | inside the matched resource, its parameters already injected |
| Sees | the `Request` only | the `Request` and `static::class` — the endpoint |
| A path that matches nothing | still runs | never runs |

Register globally to guard every request (including ones that end in a 404); register inside a resource to guard only that endpoint.

## Guards at a glance

Every guard lives under `MiGears\WebGuards\...`. Each row links to its own tutorial and reference.

| Guard | Decides | Refuses with | Doc |
|---|---|---|---|
| `Throttle\ThrottleGuard` | is this key under its rate limit? | `429` | [ThrottleGuard.md](docs/ThrottleGuard.md) |
| `Auth\AuthGuard` | is the caller authenticated? | `401` | [AuthGuard.md](docs/AuthGuard.md) |
| `Authorization\AuthorizationGuard` | may the caller do this? | `403` | [AuthorizationGuard.md](docs/AuthorizationGuard.md) |
| `Csrf\CsrfGuard` | does a mutating request carry a valid token? | `403` | [CsrfGuard.md](docs/CsrfGuard.md) |
| `IpFilter\IpFilterGuard` | is the client IP allowed? | `403` | [IpFilterGuard.md](docs/IpFilterGuard.md) |
| `Signature\SignatureGuard` | valid HMAC and a fresh timestamp? | `401` | [SignatureGuard.md](docs/SignatureGuard.md) |
| `Maintenance\MaintenanceGuard` | is the app in maintenance (with a bypass)? | `503` | [MaintenanceGuard.md](docs/MaintenanceGuard.md) |
| `KillSwitch\KillSwitchGuard` | is this feature tripped off? | `503` | [KillSwitchGuard.md](docs/KillSwitchGuard.md) |
| `UserAgent\UserAgentGuard` | is the client's User-Agent allowed? | `403` | [UserAgentGuard.md](docs/UserAgentGuard.md) |
| `Https\HttpsGuard` | is the request already secure? | `301` | [HttpsGuard.md](docs/HttpsGuard.md) |
| `Canonical\CanonicalGuard` | is the host / trailing slash canonical? | `301` | [CanonicalGuard.md](docs/CanonicalGuard.md) |
| `RequestCheck\ContentTypeGuard` | allowed body type on a body request? | `415` | [ContentTypeGuard.md](docs/ContentTypeGuard.md) |
| `RequestCheck\PayloadSizeGuard` | is the body within the limit? | `413` | [PayloadSizeGuard.md](docs/PayloadSizeGuard.md) |
| `Cors\CorsGuard` (+ `Cors\CorsHeaders`) | what may a cross-origin caller do? | `204` | [CorsGuard.md](docs/CorsGuard.md) |
| `TimeWindow\TimeWindowGuard` | is now inside the allowed window? | `403` | [TimeWindowGuard.md](docs/TimeWindowGuard.md) |

`CorsGuard` is the one exception to "a guard is alone": it answers the preflight, while its partner `CorsHeaders` — an `after()` hook — adds the CORS headers to actual responses. Register both with the same `CorsPolicy`.

## Quick start

### One guard

```php
use MiGears\Web\MiRest;
use MiGears\Web\Request;
use MiGears\WebGuards\Auth\AuthGuard;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

$rest = new MiRest(baseDir: __DIR__ . '/resources', namespace: 'App\\Resources');
$rest->set(LoggerInterface::class, static fn (): LoggerInterface => new NullLogger());

// An unauthenticated request is refused with 401 before routing runs.
$rest->before(new AuthGuard(
    static fn (Request $request): bool => $request->header('Authorization') === 'Bearer ' . getenv('API_TOKEN'),
));

$rest->handle(Request::fromGlobals())->send();
```

### A typical stack

Order below is illustrative: an early redirect, then refusals, then request checks. Each guard's doc explains its own slot.

```php
use MiGears\Web\MiRest;
use MiGears\Web\Request;
use MiGears\WebGuards\Auth\AuthGuard;
use MiGears\WebGuards\Authorization\AuthorizationGuard;
use MiGears\WebGuards\Cors\CorsGuard;
use MiGears\WebGuards\Cors\CorsHeaders;
use MiGears\WebGuards\Cors\CorsPolicy;
use MiGears\WebGuards\Csrf\CsrfGuard;
use MiGears\WebGuards\Https\HttpsGuard;
use MiGears\WebGuards\IpFilter\IpFilterGuard;
use MiGears\WebGuards\RequestCheck\ContentTypeGuard;
use MiGears\WebGuards\Throttle\ThrottleGuard;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

$rest = new MiRest(baseDir: __DIR__ . '/resources', namespace: 'App\\Resources');
$rest->set(LoggerInterface::class, static fn (): LoggerInterface => new NullLogger());

$rest->before(new HttpsGuard());                          // http -> https
$rest->before(IpFilterGuard::blocking('203.0.113.0/24'));  // drop a known-bad range
$rest->before(new ThrottleGuard($throttler, $ipKey));      // coarse per-IP anti-flood gate
$rest->before(new AuthGuard($authenticate));               // who the caller is
$rest->before(new AuthorizationGuard($authorize));         // what they may do
$rest->before(new CsrfGuard($verifyToken));                // mutating methods only
$rest->before(new ContentTypeGuard(['application/json'])); // body shape

$cors = new CorsPolicy(['https://app.example.com']);
$rest->before(new CorsGuard($cors));                       // answers the preflight
$rest->after(new CorsHeaders($cors));                       // decorates the actual response

$rest->handle(Request::fromGlobals())->send();
```

## Installation

```bash
composer require migears/web-guards
```

Requires PHP 8.1+, `migears/web`, `migears/data-structure`.

## Scope

**In scope**

- The guard catalog above: each guard's decision, its callable or config seam, and the refusal it returns.
- The throttle guard's machinery: `Throttler`, `ThrottleState`, the `ThrottleStoreInterface` seam, and one store over a Redis-compatible structure.

**Not in scope (by design)**

- Routing, `Request` / `Response` and the container — `migears/web` owns them; this package only produces a `callable(Request): ?Response`.
- Sessions, users, credentials and ACL rules — the guards take those decisions as callables. `migears/security` and `migears/acl-feature` supply them, and are deliberately *not* dependencies here.
- Backend connections — a store is passed in; this package never opens a socket.
- Deciding who the caller is — the key / policy callable is the caller's policy: IP, user, endpoint, or a combination.

## Documentation

Detailed tutorials and references live in [`docs/`](docs/README.md):

- [`docs/README.md`](docs/README.md) — the index, grouped by category, with a recommended registration order.
- One file per guard (e.g. [`docs/ThrottleGuard.md`](docs/ThrottleGuard.md), [`docs/AuthGuard.md`](docs/AuthGuard.md)) — quick start, API reference, pitfalls, ordering and typical scenarios.
- [`docs/integration.md`](docs/integration.md) — wiring the auth guards to `migears/security` and `migears/acl-feature` without adding a dependency.

## License

MIT

---

# migears/web-guards

![Version](https://img.shields.io/badge/version-2.0.0-blue)

给 **migears/web** 的守卫:一批小而独立的对象,决定请求能不能继续,或在拒绝时给出当下情形应有的响应——`401`、`403`、`413`、`415`、`429`、`503`,或一次重定向。整套覆盖认证、授权、CSRF、IP 过滤、请求签名、限流、维护与熔断开关、User-Agent 过滤、HTTPS 与规范化跳转、请求体检、CORS 与时间窗。

> **背景**:miGears 源自自研 PHP 框架 **TinyGears**,因 TinyGears 这一名字已被开源社区占用,故近期更名并开源发布。

## 守卫如何工作

### 契约

守卫是任何可按 `callable(Request): ?Response` 调用的对象:

```php
public function __invoke(Request $request): ?Response;
```

返回 `null`,请求沿链继续;返回 `MiGears\Web\Response`,它立即被送出——`before()` 链上后面的钩子都不再跑,资源处理器更是从不执行。

### 平铺的链,不是管道

miGears 的 `before()` / `after()` 是一条**平铺的钩子链**,不是管道。全局 `before` 列表先跑,某个钩子一返回 `Response` 就立刻短路;接着分发;最后全局 `after` 列表按注册顺序跑。这里没有 `next()`:没有任何单元能包住处理器、在其前后各跑一段,`after` 列表也不是逆序退出的——所以根本不存在可供「一层」嵌进去的洋葱。

这也是这些类叫 **guard(守卫)** 而不叫 middleware 的原因。守卫是纯粹的**前置判定**——放行,或拒绝——所以它只需 `before()`、从不用 `after()`;叫它 middleware 等于承诺一个框架并不存在的管道。

### 从这条链推出的几件事

- **顺序有意义。**链在第一次拒绝处停下,所以注册顺序决定谁赢。把 `AuthGuard` 排在 `AuthorizationGuard` 之前,匿名调用方拿到的是 `401` 而非 `403`。
- **各守卫彼此独立。**每个守卫以回调(或一个小配置对象)接收策略,不持有共享状态,都不需要容器,也互不知晓——所以你可以只挑需要的那几个来组合。
- **接线只需一行。**`$rest->before($guard)` 就是全部集成。
- **响应装饰不是守卫。**凡是必须给「自己没产出的响应」加东西的需求(CORS 的实际响应头、安全响应头),属于 `after()` 链,因为 `before()` 钩子够不到一个不属于自己的响应。`CorsGuard` 是唯一自带这类搭档的守卫——搭档是 `CorsHeaders`。

### 全局还是资源级

有两个 `before()` 钩子接受守卫,它们能看到的东西不同:

| | `MiRest::before()` | 资源级 `before()` |
|---|---|---|
| 时机 | 定位器之前,每个请求都会经过 | 已定位的资源内,参数已注入 |
| 手里有什么 | 只有 `Request` | `$request` 与 `static::class`(端点) |
| 什么都没匹配上的路径 | 照样执行 | 根本不执行 |

挂在全局即可守卫每一个请求(含最终 404 的那些);挂在资源内则只守卫该端点。

## 守卫一览

所有守卫都位于 `MiGears\WebGuards\...` 之下。每行都链到它自己的教程与参考。

| 守卫 | 判定什么 | 拒绝响应 | 文档 |
|---|---|---|---|
| `Throttle\ThrottleGuard` | 该 key 是否在其限流额度内 | `429` | [ThrottleGuard.md](docs/ThrottleGuard.md) |
| `Auth\AuthGuard` | 调用方是否已认证 | `401` | [AuthGuard.md](docs/AuthGuard.md) |
| `Authorization\AuthorizationGuard` | 调用方是否有权做这件事 | `403` | [AuthorizationGuard.md](docs/AuthorizationGuard.md) |
| `Csrf\CsrfGuard` | 改动型请求是否携带有效 token | `403` | [CsrfGuard.md](docs/CsrfGuard.md) |
| `IpFilter\IpFilterGuard` | 客户端 IP 是否被允许 | `403` | [IpFilterGuard.md](docs/IpFilterGuard.md) |
| `Signature\SignatureGuard` | HMAC 是否有效、时间戳是否新鲜 | `401` | [SignatureGuard.md](docs/SignatureGuard.md) |
| `Maintenance\MaintenanceGuard` | 应用是否处于维护(含放行名单) | `503` | [MaintenanceGuard.md](docs/MaintenanceGuard.md) |
| `KillSwitch\KillSwitchGuard` | 某功能是否已被拉闸 | `503` | [KillSwitchGuard.md](docs/KillSwitchGuard.md) |
| `UserAgent\UserAgentGuard` | 客户端 User-Agent 是否被允许 | `403` | [UserAgentGuard.md](docs/UserAgentGuard.md) |
| `Https\HttpsGuard` | 请求是否已是安全连接 | `301` | [HttpsGuard.md](docs/HttpsGuard.md) |
| `Canonical\CanonicalGuard` | host / 尾斜杠是否规范 | `301` | [CanonicalGuard.md](docs/CanonicalGuard.md) |
| `RequestCheck\ContentTypeGuard` | 带体请求的类型是否被允许 | `415` | [ContentTypeGuard.md](docs/ContentTypeGuard.md) |
| `RequestCheck\PayloadSizeGuard` | 请求体是否在上限之内 | `413` | [PayloadSizeGuard.md](docs/PayloadSizeGuard.md) |
| `Cors\CorsGuard`(配 `Cors\CorsHeaders`) | 跨域调用方可以做什么 | `204` | [CorsGuard.md](docs/CorsGuard.md) |
| `TimeWindow\TimeWindowGuard` | 当前时间是否在允许的窗口内 | `403` | [TimeWindowGuard.md](docs/TimeWindowGuard.md) |

`CorsGuard` 是「守卫独来独往」的唯一例外:它应答预检,而它的搭档 `CorsHeaders`(一个 `after()` 钩子)给实际响应加 CORS 头。两者共用同一个 `CorsPolicy` 一起注册。

## 快速开始

### 一个守卫

```php
use MiGears\Web\MiRest;
use MiGears\Web\Request;
use MiGears\WebGuards\Auth\AuthGuard;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

$rest = new MiRest(baseDir: __DIR__ . '/resources', namespace: 'App\\Resources');
$rest->set(LoggerInterface::class, static fn (): LoggerInterface => new NullLogger());

// 未认证的请求在路由之前就被 401 拒绝。
$rest->before(new AuthGuard(
    static fn (Request $request): bool => $request->header('Authorization') === 'Bearer ' . getenv('API_TOKEN'),
));

$rest->handle(Request::fromGlobals())->send();
```

### 一套典型组合

下面的顺序只是示意:先跳转,再拒绝型,最后请求体检。每个守卫为什么排在那个位置,见它自己的文档。

```php
use MiGears\Web\MiRest;
use MiGears\Web\Request;
use MiGears\WebGuards\Auth\AuthGuard;
use MiGears\WebGuards\Authorization\AuthorizationGuard;
use MiGears\WebGuards\Cors\CorsGuard;
use MiGears\WebGuards\Cors\CorsHeaders;
use MiGears\WebGuards\Cors\CorsPolicy;
use MiGears\WebGuards\Csrf\CsrfGuard;
use MiGears\WebGuards\Https\HttpsGuard;
use MiGears\WebGuards\IpFilter\IpFilterGuard;
use MiGears\WebGuards\RequestCheck\ContentTypeGuard;
use MiGears\WebGuards\Throttle\ThrottleGuard;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

$rest = new MiRest(baseDir: __DIR__ . '/resources', namespace: 'App\\Resources');
$rest->set(LoggerInterface::class, static fn (): LoggerInterface => new NullLogger());

$rest->before(new HttpsGuard());                          // http -> https
$rest->before(IpFilterGuard::blocking('203.0.113.0/24'));  // 挡掉已知坏网段
$rest->before(new ThrottleGuard($throttler, $ipKey));      // 粗粒度按 IP 反洪泛闸门
$rest->before(new AuthGuard($authenticate));               // 调用方是谁
$rest->before(new AuthorizationGuard($authorize));         // 他能做什么
$rest->before(new CsrfGuard($verifyToken));                // 仅改动型方法
$rest->before(new ContentTypeGuard(['application/json'])); // 请求体形状

$cors = new CorsPolicy(['https://app.example.com']);
$rest->before(new CorsGuard($cors));                       // 应答预检
$rest->after(new CorsHeaders($cors));                      // 装饰实际响应

$rest->handle(Request::fromGlobals())->send();
```

## 安装

```bash
composer require migears/web-guards
```

要求:PHP 8.1+、`migears/web`、`migears/data-structure`。

## 边界

**范围内**

- 上面的守卫一览:每个守卫的判定、它的回调或配置接缝,以及它返回的拒绝响应。
- 限流守卫的机制:`Throttler`、`ThrottleState`、`ThrottleStoreInterface` 接缝,以及一个基于 Redis 兼容结构的实现。

**范围外(刻意不做)**

- 路由、`Request` / `Response` 与容器 —— 由 `migears/web` 负责;本包只产出 `callable(Request): ?Response`。
- 会话、用户、凭据与 ACL 规则 —— 守卫把这些判定以回调接收。`migears/security` 与 `migears/acl-feature` 提供它们,并被刻意**不**作为本包的依赖。
- 后端连接 —— 存储由调用方传入;本包绝不自己开连接。
- 判定调用方是谁 —— key / 策略回调是调用方的策略:IP、用户、端点,或它们的组合。

## 文档

详细的教程与参考在 [`docs/`](docs/README.md):

- [`docs/README.md`](docs/README.md) —— 索引,按类别分组,并给出一种推荐的全局注册顺序。
- 每个守卫一个文件(如 [`docs/ThrottleGuard.md`](docs/ThrottleGuard.md)、[`docs/AuthGuard.md`](docs/AuthGuard.md))——快速上手、API 参考、常见坑、组合与顺序、典型场景。
- [`docs/integration.md`](docs/integration.md) —— 如何在不加依赖的前提下,把认证类守卫接到 `migears/security` 与 `migears/acl-feature`。

## License

MIT
