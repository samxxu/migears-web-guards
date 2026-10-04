# KillSwitchGuard

> A `before()` hook that refuses matching requests with `503 Service Unavailable` the moment a tripped callable says so — the emergency stop, with no `Retry-After`.
> 一个在「已跳闸」回调判定成立时立即把匹配请求回以 `503 Service Unavailable` 的 `before()` 钩子——应急拉闸,不带 `Retry-After`。

## English

### The problem it solves

One feature is dragging the database down, or it is returning wrong data. You need to shut that one feature off within seconds — without a new release, and without taking the whole site down with it. Do not confuse this with maintenance mode: a kill switch turns off a single feature and makes no promise about when it comes back, while maintenance takes the whole site offline and does promise a return time.

**Terms**

- *kill switch* — the emergency master switch that turns one feature off.
- *trip* — to flip that switch to the "off" position.

### What it does
When the `tripped` callable returns `true` for a request, `KillSwitchGuard` refuses it with a `503` before it reaches a handler — the emergency stop for one feature that is burning the database, returning bad data, or being abused, without a deploy to turn it off. Because the switch is a callable over the request, the same guard serves one flag ("everything off") or many ("this endpoint only"): the caller decides what *tripped* means for the request in front of it. It refuses with no `Retry-After` on purpose — it reports that the feature is off, not when it will be back — and it never wraps the handler, so it is a guard, not a middleware.

### Quick start
Minimal:

```php
use MiGears\Web\Request;
use MiGears\WebGuards\KillSwitch\KillSwitchGuard;

// off for one endpoint only, while the flag is set
$rest->before(new KillSwitchGuard(
    static fn (Request $r): bool => $r->path === '/checkout' && getenv('KILL_CHECKOUT') === '1',
));
```

Full bootstrap:

```php
use MiGears\Web\MiRest;
use MiGears\Web\Request;
use MiGears\WebGuards\KillSwitch\KillSwitchGuard;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

$rest = new MiRest(baseDir: __DIR__ . '/resources', namespace: 'App\\Resources');
$rest->set(LoggerInterface::class, fn () => new NullLogger());

$rest->before(new KillSwitchGuard(
    static fn (Request $r): bool => $r->path === '/checkout' && getenv('KILL_CHECKOUT') === '1',
));

$rest->handle(Request::fromGlobals())->send();
```

### API reference
Constructor:

| Parameter | Type | Default | Meaning |
|---|---|---|---|
| `$tripped` | `callable(Request): bool` | — (required) | `true` when this request should be refused |

`__invoke(Request $request): ?Response`:

| Case | Result |
|---|---|
| `tripped($request)` is `false` | `null` — the request continues |
| `tripped($request)` is `true` | `503`, header `Content-Type: application/json; charset=utf-8`, body `{"error":"Service Unavailable"}` |

No `InvalidArgumentException` is thrown by this guard; the only parameter is the callable itself. The callable is wrapped with `Closure::fromCallable()` in the constructor.

### Pitfalls
- The refusal carries **no `Retry-After`.** Its `503` is byte-for-byte the same status and body as `MaintenanceGuard`'s, minus that header. Clients cannot tell the two apart except by the missing header — and that is deliberate: a kill switch says "off", not "back at time X".
- `tripped` receives the `Request`, so the same guard can gate one path, one authenticated user, or everything. If you register it globally, the callable must itself narrow the match; a bare `fn (Request $r): bool => true` takes the whole application down.
- Whether the switch is on lives in *your* callable — an env var, a flag file, a database row. The guard caches nothing, so the callable is consulted on every request and flips take effect immediately.
- A `503` returned from `before()` short-circuits dispatch, but the global `after` hooks still run, so any after-hook header or log line you rely on survives a tripped request.

### Composition & ordering
Where it goes depends on what it guards. For a global flag, register it early in the `before()` chain, **after** `MaintenanceGuard` if you use one: during a planned outage, maintenance's `503` with a `Retry-After` is the more informative answer, and whichever hook is registered first wins. For a single endpoint, pull the guard into that resource's own `before()` instead — there the request is already known to be that endpoint, so `tripped` can be a plain `fn (): bool => $flag`. Register it before any expensive guard or handler the feature must not reach.

### Typical scenarios
- Global "read-only" mode: `fn (Request $r): bool => $readOnly && $r->method !== 'GET'`.
- One feature behind a DB flag: `fn (Request $r): bool => (bool) $flagRepo->get('checkout')`.
- Emergency stop for a payment provider: `fn (Request $r): bool => $provider->isDegraded()`, scoped to the payment resource.
- Gate by authenticated user: `fn (Request $r): bool => in_array($user($r), $blocked, true)`.

---

## 中文

### 它解决什么问题

某个功能正在把数据库拖垮,或者它返回的是错误数据。你需要在几秒内只关掉这一个功能——既不发新版本,也不至于把整个站点一起停掉。别把它和维护模式混为一谈:拉闸只关掉一个功能,而且不承诺它何时恢复;维护模式关的是整站,并且承诺一个恢复时间。

**术语**

- *kill switch(拉闸开关)* —— 应急关闭某一个功能的总开关。
- *trip(跳闸)* —— 把开关拨到「关」的状态。

### 它做什么
当 `tripped` 回调对某个请求返回 `true`,`KillSwitchGuard` 会在请求抵达处理器之前把它回以 `503`——这是针对某个正在压垮数据库、返回坏数据或被人滥用的功能的应急拉闸,不必靠部署来关掉它。开关是作用在请求上的回调,所以同一个守卫既能服务一个总闸(「全部关掉」),也能服务很多个(「只关这个端点」):由调用方决定「已跳闸」对眼前这个请求意味着什么。它刻意**不带 `Retry-After`**——它表达的是「功能关掉了」,而不是「何时恢复」——并且它从不包住处理器,所以它是守卫而非中间件。

### 快速上手
最小片段:

```php
use MiGears\Web\Request;
use MiGears\WebGuards\KillSwitch\KillSwitchGuard;

// 仅对单个端点拉闸,只要开关处于打开状态
$rest->before(new KillSwitchGuard(
    static fn (Request $r): bool => $r->path === '/checkout' && getenv('KILL_CHECKOUT') === '1',
));
```

完整 bootstrap:

```php
use MiGears\Web\MiRest;
use MiGears\Web\Request;
use MiGears\WebGuards\KillSwitch\KillSwitchGuard;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

$rest = new MiRest(baseDir: __DIR__ . '/resources', namespace: 'App\\Resources');
$rest->set(LoggerInterface::class, fn () => new NullLogger());

$rest->before(new KillSwitchGuard(
    static fn (Request $r): bool => $r->path === '/checkout' && getenv('KILL_CHECKOUT') === '1',
));

$rest->handle(Request::fromGlobals())->send();
```

### API 参考
构造函数:

| 参数 | 类型 | 默认值 | 含义 |
|---|---|---|---|
| `$tripped` | `callable(Request): bool` | —(必填) | 返回 `true` 表示该请求应被拒绝 |

`__invoke(Request $request): ?Response`:

| 情形 | 结果 |
|---|---|
| `tripped($request)` 为 `false` | `null` —— 请求继续 |
| `tripped($request)` 为 `true` | `503`,响应头 `Content-Type: application/json; charset=utf-8`,响应体 `{"error":"Service Unavailable"}` |

本守卫不抛 `InvalidArgumentException`;唯一的参数就是那个回调。回调在构造函数里经 `Closure::fromCallable()` 包装。

### 常见坑与注意事项
- 拒绝响应**不带 `Retry-After`。** 它的 `503` 在状态码和响应体上与 `MaintenanceGuard` 完全一致,只少了那个头。客户端除「少一个响应头」外无法区分两者——而这正是刻意的:拉闸说的是「关了」,不是「X 时刻恢复」。
- `tripped` 拿到的是 `Request`,所以同一个守卫能只卡一条路径、一个已认证用户,或全部。全局注册时,收窄匹配是回调自己的责任;一个光秃秃的 `fn (Request $r): bool => true` 会把整个应用拉下线。
- 开关是否打开存在于*你的*回调里——环境变量、开关文件、数据库行。守卫本身不缓存任何东西,所以每个请求都会询问回调,翻转立即生效。
- 从 `before()` 返回的 `503` 会短路分发,但全局 `after` 钩子仍会执行,所以你所依赖的 after 钩子加的头或日志,在被拉闸的请求上依然生效。

### 组合与顺序
放在哪里取决于它守的是什么。若是全局总闸,在 `before()` 链里注册得早一些——如果你用了 `MaintenanceGuard`,则排在它**之后**:计划内停机时,维护那条带 `Retry-After` 的 `503` 是信息量更大的回答,而先注册的钩子胜出。若只卡单个端点,则改为把这个守卫拉进该资源自己的 `before()`——在那里请求已被确知就是该端点,`tripped` 可以简化成 `fn (): bool => $flag`。无论哪种,都要把它排在该功能不可触碰的任何昂贵守卫或处理器之前。

### 典型场景
- 全局「只读」模式:`fn (Request $r): bool => $readOnly && $r->method !== 'GET'`。
- 单个功能挂 DB 开关:`fn (Request $r): bool => (bool) $flagRepo->get('checkout')`。
- 支付渠道应急拉闸:`fn (Request $r): bool => $provider->isDegraded()`,限定在支付资源上。
- 按已认证用户放闸:`fn (Request $r): bool => in_array($user($r), $blocked, true)`。
