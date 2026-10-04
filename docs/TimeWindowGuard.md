# TimeWindowGuard

> A time-window `before()` hook: a request is allowed only while the local clock is inside the configured window, and refused with `403` outside it.
> 一个时间窗 `before()` 钩子：只有本地时钟处于配置的窗口内时请求才被放行，窗口外一律以 `403` 拒绝。

## English

### The problem it solves

Some endpoints should answer only during certain hours: a back office open only in business hours, or a batch endpoint that runs only at night. Outside the chosen span, those requests have no business being served at all. This guard compares the local clock against the window and refuses everything outside it with a `403`.

**Terms**

- *time window* — The span of time during which access is allowed.
- *overnight window* — A window that crosses midnight, e.g. 22:00–06:00.
- *timezone* — This guard reads `HH:MM` in the process time zone.

### What it does
`TimeWindowGuard` compares the current local time against a start and an end and refuses any request that falls outside with a `403 Forbidden`. A back office that answers only during business hours, or an endpoint open only overnight, is expressed as one window. It runs before dispatch, short-circuits it, and never touches a handler — a pure pre-decision, so it is a guard and not a middleware. Time comes from an injected clock, so a test decides what "now" is.

### Quick start
Minimal:

```php
use MiGears\WebGuards\TimeWindow\TimeWindowGuard;

$rest->before(new TimeWindowGuard('09:00', '17:00'));
```

Full bootstrap:

```php
use MiGears\Web\MiRest;
use MiGears\Web\Request;
use MiGears\WebGuards\TimeWindow\TimeWindowGuard;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

$rest = new MiRest(baseDir: __DIR__ . '/resources', namespace: 'App\\Resources');
$rest->set(LoggerInterface::class, fn () => new NullLogger());

// The back office answers only between 09:00 and 17:00, local process time;
// outside that it is refused with a 403 before routing runs.
$rest->before(new TimeWindowGuard('09:00', '17:00'));

$rest->handle(Request::fromGlobals())->send();
```

A pinned clock makes the window testable:

```php
// "now" is fixed to 2024-01-15 23:00 local time.
$at = static fn (): int => mktime(23, 0, 0, 1, 15, 2024);

$rest->before(new TimeWindowGuard('22:00', '06:00', $at));
```

### API reference
Constructor:

| Parameter | Type | Default | Meaning |
|---|---|---|---|
| `$start` | `string` | — (required) | the minute the window opens, `'HH:MM'` |
| `$end` | `string` | — (required) | the minute the window closes, `'HH:MM'` |
| `$clock` | `?callable(): int` | `null` | the current unix time; defaults to `time()` |

`__invoke(Request $request): ?Response`:

| Case | Result |
|---|---|
| `date('H:i', clock())` is inside the window | `null` — the request continues |
| it is outside the window | `403` with `Content-Type: application/json; charset=utf-8` and body `{"error":"Forbidden"}` |

Membership is decided on the `'HH:MM'` strings (a fixed-width form compares chronologically as text):

| Relationship | Window | Inside when |
|---|---|---|
| `$start <= $end` | same day, e.g. `'09:00'`–`'17:00'` | `now >= $start && now <= $end` |
| `$start > $end` | wraps past midnight, e.g. `'22:00'`–`'06:00'` | `now >= $start || now <= $end` |

Both bounds are **inclusive to the minute**: `09:00` as the start and `17:00` as the end are both inside a `09:00`–`17:00` window.

Throws `InvalidArgumentException` when `$start` or `$end` is not exactly `HH:MM` in 24-hour form (the message names the offending bound and value, e.g. `The start time must be in HH:MM 24-hour form, got '25:00'.`). The accepted form is a two-digit hour `00`–`23`, a colon, and a two-digit minute `00`–`59`.

### Pitfalls
- Times are in the **process time zone** — `date('H:i')` uses the application's default timezone. A server set to UTC serving a local business-hours window will be off by its offset, and DST shifts the wall-clock minute.
- Resolution is one minute; seconds are ignored (`date('H:i')` truncates them). A window boundary at `09:00` opens for the whole `09:00` minute.
- The bounds are inclusive, so a `09:00`–`17:00` window contains both `09:00` and `17:00` exactly.
- A start **later than** the end wraps past midnight by design (`22:00`–`06:00` is the night shift). The reverse-reading mistake — assuming it is an error — is not how this guard behaves.
- `$start === $end` is **not** a full day: with `$start <= $end` it is a single-minute window that is inside only at that exact minute. There is no "all day" shortcut.
- The clock callable must return an **int unix timestamp**; the default is `static fn (): int => time()`. It is wrapped with `Closure::fromCallable()` in the constructor, so a closure, an invokable object, or a `[$obj, 'method']` array all work.
- The format is strict and zero-padded: `'9:00'` is rejected, `'09:00'` is accepted.

### Composition & ordering
- It is a cheap, independent check, so its slot among the refusals is a policy choice. Putting it before auth and throttle denies off-hours traffic the least work; putting it after authentication lets an off-hours request still be attributed to a user in logs.
- It composes with `MaintenanceGuard` without overlap: maintenance is an event-driven outage, a time window is a recurring schedule. Keep the two separate so neither has to encode the other.

### Typical scenarios
- Business-hours back office: `new TimeWindowGuard('09:00', '17:00')`
- An overnight jobs endpoint: `new TimeWindowGuard('22:00', '06:00')`
- Freeze writes to a single minute a day: `new TimeWindowGuard('03:00', '03:00')`
- Test with a pinned clock: `new TimeWindowGuard('09:00', '17:00', $at)`

---

## 中文

### 它解决什么问题

有些接口只应在特定时段应答：比如后台只在工作时间内开放，或某个批处理接口只在夜间运行。在选定的时段之外，这些请求本就不该被服务。这个守卫把本地时钟与窗口比较，把窗口外的一切以 `403` 拒绝。

**术语**

- *time window(时间窗)* —— 允许访问的那一段时段。
- *overnight window(跨午夜窗口)* —— 跨过午夜的窗口，如 22:00–06:00。
- *timezone(时区)* —— 本守卫按进程时区解释 `HH:MM`。

### 它做什么
`TimeWindowGuard` 把当前本地时间与起点、终点比较，把落在窗口之外的请求以 `403 Forbidden` 拒绝。只在工作时间内应答的后台，或只在夜间开放的端点，都用一个窗口来表达。它在分发之前运行并短路分发，从不触碰处理器——一个纯粹的前置判定，所以是守卫而非中间件。时间来自注入的时钟，因此测试可以决定「现在」是什么时候。

### 快速上手
最小片段：

```php
use MiGears\WebGuards\TimeWindow\TimeWindowGuard;

$rest->before(new TimeWindowGuard('09:00', '17:00'));
```

完整 bootstrap：

```php
use MiGears\Web\MiRest;
use MiGears\Web\Request;
use MiGears\WebGuards\TimeWindow\TimeWindowGuard;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

$rest = new MiRest(baseDir: __DIR__ . '/resources', namespace: 'App\\Resources');
$rest->set(LoggerInterface::class, fn () => new NullLogger());

// 后台只在本地进程时间 09:00 到 17:00 之间应答；
// 之外的在路由之前被 403 拒绝。
$rest->before(new TimeWindowGuard('09:00', '17:00'));

$rest->handle(Request::fromGlobals())->send();
```

固定时钟让窗口可测：

```php
// 把「现在」钉在本地时间 2024-01-15 23:00。
$at = static fn (): int => mktime(23, 0, 0, 1, 15, 2024);

$rest->before(new TimeWindowGuard('22:00', '06:00', $at));
```

### API 参考
构造函数：

| 参数 | 类型 | 默认值 | 含义 |
|---|---|---|---|
| `$start` | `string` | —(必填) | 窗口开启的那一分钟，`'HH:MM'` |
| `$end` | `string` | —(必填) | 窗口关闭的那一分钟，`'HH:MM'` |
| `$clock` | `?callable(): int` | `null` | 当前 unix 时间；默认 `time()` |

`__invoke(Request $request): ?Response`：

| 情形 | 结果 |
|---|---|
| `date('H:i', clock())` 在窗口内 | `null` —— 请求继续 |
| 在窗口外 | `403`，响应头 `Content-Type: application/json; charset=utf-8`，响应体 `{"error":"Forbidden"}` |

归属用 `'HH:MM'` 字符串判定（固定宽度的形式按文本比较即为按时间先后比较）：

| 关系 | 窗口 | 在窗内的条件 |
|---|---|---|
| `$start <= $end` | 同日，如 `'09:00'`–`'17:00'` | `now >= $start && now <= $end` |
| `$start > $end` | 跨午夜，如 `'22:00'`–`'06:00'` | `now >= $start \|\| now <= $end` |

两个边界都**精确到分钟且含端**：`09:00`–`17:00` 的窗口里，`09:00` 与 `17:00` 本身都在窗内。

当 `$start` 或 `$end` 不是恰好 `HH:MM` 的 24 小时制形式时，抛出 `InvalidArgumentException`（消息会点名出错的边界与取值，例如 `The start time must be in HH:MM 24-hour form, got '25:00'.`）。接受的形式是两位小时 `00`–`23`、一个冒号、两位分钟 `00`–`59`。

### 常见坑与注意事项
- 时间是**进程时区**的——`date('H:i')` 使用应用的默认时区。一台设为 UTC、却要服务本地工作时间窗口的服务器会偏掉它的时差，DST 也会挪动那个墙钟分钟。
- 精度是一分钟；秒被忽略（`date('H:i')` 会截断它们）。`09:00` 的边界，整一分钟 `09:00` 都算开门。
- 边界含端，所以 `09:00`–`17:00` 的窗口恰好包含 `09:00` 与 `17:00`。
- 起点**晚于**终点会按设计跨午夜（`22:00`–`06:00` 是夜班）。把这种写法误读成「非法」，并非本守卫的行为。
- `$start === $end` **不是**一整天：因走 `$start <= $end` 分支，它是一个只在那一分钟才算窗内的单分钟窗口。没有「全天」的快捷写法。
- 时钟回调必须返回 **int 的 unix 时间戳**；默认是 `static fn (): int => time()`。它在构造函数里经 `Closure::fromCallable()` 包装，所以闭包、可调用对象、`[$obj, 'method']` 数组都行。
- 格式严格且要求补零：`'9:00'` 会被拒绝，`'09:00'` 才接受。

### 组合与顺序
- 它是廉价、独立的检查，放在拒绝型守卫中的哪一档属于策略选择。放在认证与限流之前，能让非营业时间的流量付出最少的工作;放在认证之后，则能让一条非营业时间的请求在日志里仍归属到某个用户。
- 它与 `MaintenanceGuard` 组合而不重叠：维护是事件驱动的停机，时间窗是循环排班。两者分开，免得任何一方要去编码另一方。

### 典型场景
- 工作时间的后台：`new TimeWindowGuard('09:00', '17:00')`
- 夜间跑批端点：`new TimeWindowGuard('22:00', '06:00')`
- 每天冻结写入到某一分钟：`new TimeWindowGuard('03:00', '03:00')`
- 用固定时钟测试：`new TimeWindowGuard('09:00', '17:00', $at)`
