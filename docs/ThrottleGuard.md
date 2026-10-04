# ThrottleGuard

> A fixed-window rate-limiting `before()` hook: `Throttler` counts and decides, `ThrottleGuard` turns a refusal into a `429`.
> 固定窗口限流的 `before()` 钩子：`Throttler` 负责计数与判定，`ThrottleGuard` 负责把拒绝变成 `429`。

## English

### The problem it solves

Without a guard, one client — a crawler, a runaway retry loop, a buggy script or an attacker — can send requests without any limit. Every request costs CPU, database queries and bandwidth, so a single client can slow the service down for everyone, and the server has no way to say "you are going too fast, slow down".

**Terms**

- *rate limit* — a cap on how many requests a client may make in a given time.
- *throttle key* — what the limit counts against, such as one IP, one user or one endpoint.
- *fixed window* — a counter that resets every N seconds.
- *store* — where the shared counter lives, for example Redis.

### What it does

`Throttler` counts hits against a key in a fixed window and decides whether each hit is allowed. `ThrottleGuard` is the web adapter around it: the callable a MiRest application registers as a `before()` hook, answering the only two things such a hook can — continue (`null`) or refuse. Because the limiter itself contains no HTTP, the same `Throttler` can limit an RPC method, a job or a CLI command; only `ThrottleGuard` knows about `Request` and `Response`. It is a guard and not a middleware on purpose: miGears' `before()`/`after()` is a flat hook chain with no `next()`, a limiter is a pure pre-decision, so `$rest->before($guard)` is the whole integration.

### Quick start

The whole wiring is one line once the `Throttler` exists:

```php
use MiGears\WebGuards\Throttle\ThrottleGuard;
use MiGears\WebGuards\Throttle\Throttler;

$rest->before(new ThrottleGuard(
    $throttler,
    static fn (Request $request): string => 'ip:' . ($request->server['REMOTE_ADDR'] ?? 'unknown'),
));
```

A full bootstrap, supplying the store and the logger:

```php
use MiGears\DataStructure\RedisDataStructure;
use MiGears\Web\MiRest;
use MiGears\Web\Request;
use MiGears\WebGuards\Throttle\DataStructureThrottleStore;
use MiGears\WebGuards\Throttle\ThrottleGuard;
use MiGears\WebGuards\Throttle\Throttler;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

$rest = new MiRest(baseDir: __DIR__ . '/resources', namespace: 'App\\Resources');
$rest->set(LoggerInterface::class, fn () => new NullLogger());

$redis = new Redis();
$redis->connect('127.0.0.1', 6379);

$throttler = new Throttler(
    new DataStructureThrottleStore(
        (new RedisDataStructure($redis, $rest->get(LoggerInterface::class)))->withPrefix('app:throttle:'),
    ),
    limit: 60,
    windowSeconds: 60,
);

// 60 requests per minute per client IP: under the limit dispatch continues,
// over it the request is refused with a 429 before routing runs.
$rest->before(new ThrottleGuard(
    $throttler,
    static fn (Request $request): string => 'ip:' . ($request->server['REMOTE_ADDR'] ?? 'unknown'),
));

$rest->handle(Request::fromGlobals())->send();
```

### API reference

| Member | Signature / type | Notes |
|---|---|---|
| `Throttler::__construct` | `(ThrottleStoreInterface $store, int $limit = 60, int $windowSeconds = 60)` | `$limit` and `$windowSeconds` must be `>= 1` |
| `Throttler::hit` | `hit(string $key): ThrottleState` | records one hit; `$key` must not be empty |
| `ThrottleState::$count` | `public readonly int` | hits in this window, this one included |
| `ThrottleState::$limit` | `public readonly int` | hits the window permits |
| `ThrottleState::$resetIn` | `public readonly int` | seconds until the window ends |
| `ThrottleState::allowed()` | `bool` | `count <= limit`; the hit that reaches the limit is the last allowed one |
| `ThrottleState::remaining()` | `int` | `limit - count`, never below `0` |
| `ThrottleState::retryAfter()` | `int` | `resetIn`, never below `0` |

| Member | Signature | Notes |
|---|---|---|
| `ThrottleStoreInterface::hit` | `hit(string $key, int $windowSeconds): array` (returns `array{count: int, resetIn: int}`) | `count` includes this hit; `resetIn` is seconds left; must be atomic per key |
| `DataStructureThrottleStore::__construct` | `(DataStructureInterface $structure)` | one hash field per key, incremented with `hashIncrBy()`; `expire()` armed by the first hit only |
| `ThrottleGuard::__construct` | `(Throttler $throttler, callable(Request): string $key)` | the key callable is the caller's policy |
| `ThrottleGuard::__invoke` | `__invoke(Request $request): ?Response` | `null` continues; a `Response` refuses and short-circuits |

| Condition | Thrown by | Exception |
|---|---|---|
| `limit < 1` | `new Throttler(...)` | `InvalidArgumentException('The limit must be at least 1.')` |
| `windowSeconds < 1` | `new Throttler(...)` | `InvalidArgumentException('The window must be at least 1 second.')` |
| empty `$key` | `Throttler::hit()` | `InvalidArgumentException('A throttle key must not be empty.')` |

The refusal is returned without any handler running:

| | |
|---|---|
| Status | `429 Too Many Requests` |
| `Retry-After` | `(string) $state->retryAfter()` |
| `X-RateLimit-Limit` | `(string) $state->limit` |
| `X-RateLimit-Remaining` | `(string) $state->remaining()` (`0` on a refusal) |
| Body | `{"error":"Too Many Requests"}` |

### Pitfalls

- **A boundary burst of up to twice the limit.** A client can spend the whole limit at the end of one window and the whole limit again at the start of the next; a shorter window makes this cheaper.
- **The window is per key, not per client.** Whatever the key callable returns is the whole identity, so prefix the key (`'throttle:'`) to keep it apart from other data in a shared store.
- **`DataStructureThrottleStore` does `INCR` and `EXPIRE` in two round trips.** A process that dies between them leaves a counter with no expiry, and that key would then be counted forever; deleting the key is the recovery.
- **Allowed requests are not decorated.** No `X-RateLimit-*` headers are added to a `200`, because a `before()` hook cannot touch a response it did not produce.

### Composition & ordering

- On `MiRest::before()` the guard counts every request, including ones that end in a `404`. On a resource's `before()` it counts only the requests that reached that resource.
- Relative to `AuthGuard`, the order follows what the throttle keys on. Keyed per authenticated user, authenticate first, so the budget is spent by a known identity. Used as a coarse per-IP anti-flood gate, throttle first, so unauthenticated traffic is refused before it reaches the authentication work — and note the login endpoints are exactly the ones that need that protection.

### Typical scenarios

- **Global limit by client IP** — register on `MiRest::before()`, key on `$request->server['REMOTE_ADDR']`.
- **Limit one endpoint only** — return the guard's result from that resource's `before()`, keying on the endpoint.
- **Limit an RPC method or a job** — call `Throttler::hit()` directly and inspect `ThrottleState`; no `ThrottleGuard` and no HTTP involved.

---

## 中文

### 它解决什么问题

没有这个守卫时，一个客户端——爬虫、失控的重试循环、有 bug 的脚本、或攻击者——可以无限地发请求。每次请求都消耗 CPU、数据库查询和带宽，因此单个客户端就能把服务拖慢到影响所有人，而服务器没有任何办法说「你太快了，慢一点」。

**术语**

- *限流（rate limit）* —— 限制一个客户端在一段时间内最多能发多少请求。
- *限流键（throttle key）* —— 限流按什么来计数，例如一个 IP、一个用户或一个端点。
- *固定窗口（fixed window）* —— 一个每隔 N 秒归零的计数器。
- *存储（store）* —— 共享计数器放在哪里，例如 Redis。

### 它做什么

`Throttler` 在固定窗口内按 key 累计命中数，并判定每一次命中是否放行。`ThrottleGuard` 是它外面的 Web 适配器：MiRest 应用把它注册为 `before()` 钩子，它只回答这类钩子仅有的两种结果——继续（`null`）或拒绝。因为限流器本身不含 HTTP，同一个 `Throttler` 也能给 RPC 方法、任务或 CLI 命令限流；只有 `ThrottleGuard` 知道 `Request` 与 `Response`。它刻意是 guard 而不是 middleware：miGears 的 `before()`/`after()` 是一条没有 `next()` 的平铺钩子链，限流是纯粹的前置判定，所以 `$rest->before($guard)` 就是全部集成。

### 快速上手

只要 `$throttler` 就位，接线只需一行：

```php
use MiGears\WebGuards\Throttle\ThrottleGuard;
use MiGears\WebGuards\Throttle\Throttler;

$rest->before(new ThrottleGuard(
    $throttler,
    static fn (Request $request): string => 'ip:' . ($request->server['REMOTE_ADDR'] ?? 'unknown'),
));
```

完整的 bootstrap，提供存储与 Logger：

```php
use MiGears\DataStructure\RedisDataStructure;
use MiGears\Web\MiRest;
use MiGears\Web\Request;
use MiGears\WebGuards\Throttle\DataStructureThrottleStore;
use MiGears\WebGuards\Throttle\ThrottleGuard;
use MiGears\WebGuards\Throttle\Throttler;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

$rest = new MiRest(baseDir: __DIR__ . '/resources', namespace: 'App\\Resources');
$rest->set(LoggerInterface::class, fn () => new NullLogger());

$redis = new Redis();
$redis->connect('127.0.0.1', 6379);

$throttler = new Throttler(
    new DataStructureThrottleStore(
        (new RedisDataStructure($redis, $rest->get(LoggerInterface::class)))->withPrefix('app:throttle:'),
    ),
    limit: 60,
    windowSeconds: 60,
);

// 每个客户端 IP 每分钟 60 次：未超额继续分发，超额的在路由之前就被 429 拒绝。
$rest->before(new ThrottleGuard(
    $throttler,
    static fn (Request $request): string => 'ip:' . ($request->server['REMOTE_ADDR'] ?? 'unknown'),
));

$rest->handle(Request::fromGlobals())->send();
```

### API 参考

| 成员 | 签名 / 类型 | 说明 |
|---|---|---|
| `Throttler::__construct` | `(ThrottleStoreInterface $store, int $limit = 60, int $windowSeconds = 60)` | `$limit` 与 `$windowSeconds` 必须 `>= 1` |
| `Throttler::hit` | `hit(string $key): ThrottleState` | 记一次命中；`$key` 不能为空 |
| `ThrottleState::$count` | `public readonly int` | 本窗口内的命中数，含本次 |
| `ThrottleState::$limit` | `public readonly int` | 窗口允许的命中数 |
| `ThrottleState::$resetIn` | `public readonly int` | 距窗口结束的秒数 |
| `ThrottleState::allowed()` | `bool` | `count <= limit`；计数恰好到达上限的那次是最后一次放行 |
| `ThrottleState::remaining()` | `int` | `limit - count`，不低于 `0` |
| `ThrottleState::retryAfter()` | `int` | `resetIn`，不低于 `0` |

| 成员 | 签名 | 说明 |
|---|---|---|
| `ThrottleStoreInterface::hit` | `hit(string $key, int $windowSeconds): array`（返回 `array{count: int, resetIn: int}`） | `count` 含本次；`resetIn` 为剩余秒数；必须对每个 key 原子 |
| `DataStructureThrottleStore::__construct` | `(DataStructureInterface $structure)` | 每个 key 一个哈希字段，用 `hashIncrBy()` 自增；`expire()` 只在首次命中时装上 |
| `ThrottleGuard::__construct` | `(Throttler $throttler, callable(Request): string $key)` | key 回调就是调用方的策略 |
| `ThrottleGuard::__invoke` | `__invoke(Request $request): ?Response` | 返回 `null` 继续；返回 `Response` 拒绝并短路 |

| 条件 | 抛出方 | 异常 |
|---|---|---|
| `limit < 1` | `new Throttler(...)` | `InvalidArgumentException('The limit must be at least 1.')` |
| `windowSeconds < 1` | `new Throttler(...)` | `InvalidArgumentException('The window must be at least 1 second.')` |
| `$key` 为空 | `Throttler::hit()` | `InvalidArgumentException('A throttle key must not be empty.')` |

拒绝响应（不跑任何处理器即返回）：

| | |
|---|---|
| 状态码 | `429 Too Many Requests` |
| `Retry-After` | `(string) $state->retryAfter()` |
| `X-RateLimit-Limit` | `(string) $state->limit` |
| `X-RateLimit-Remaining` | `(string) $state->remaining()`（被拒时为 `0`） |
| Body | `{"error":"Too Many Requests"}` |

### 常见坑与注意事项

- **边界处最多可突发两倍上限。** 客户端可以在一个窗口末尾用完整个上限，再在下一个窗口开头用完整个上限；窗口越短，这个代价越小。
- **窗口是按 key、不是按客户端。** key 回调返回什么，什么就是完整的身份，所以要给 key 加前缀（`'throttle:'`），让它在共享存储里与其它数据互不干扰。
- **`DataStructureThrottleStore` 的 `INCR` 与 `EXPIRE` 是两次往返。** 进程在两者之间死掉会留下一个没有过期时间的计数器，那个 key 就会被永久计数；删除该 key 即为恢复手段。
- **放行的请求不会被装饰。** 不会给 `200` 加 `X-RateLimit-*` 头，因为 `before()` 钩子够不到一个并非自己产出的响应。

### 组合与顺序

- 挂在 `MiRest::before()` 上会统计所有请求，含最终 `404` 的那些；挂在资源级 `before()` 上只统计到达该资源的请求。
- 相对 `AuthGuard`，顺序取决于限流以什么为键。按已认证用户为键时，先认证，让预算由确定的身份消耗；作为粗粒度的按 IP 反洪泛闸门时，先限流，让未认证流量在触达认证工作之前就被拒——注意登录端点恰恰最需要这层保护。

### 典型场景

- **按客户端 IP 全局限流** —— 注册在 `MiRest::before()`，以 `$request->server['REMOTE_ADDR']` 为键。
- **只对某端点限流** —— 在该资源的 `before()` 里返回守卫的结果，以端点为键。
- **给 RPC 方法或后台任务限流** —— 直接调用 `Throttler::hit()` 并检查 `ThrottleState`；不经过 `ThrottleGuard`，也不涉及 HTTP。
