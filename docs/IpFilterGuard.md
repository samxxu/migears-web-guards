# IpFilterGuard

> An IP allow/deny `before()` hook that refuses the clients a CIDR list excludes with a `403`.
> 一个 IP 允许/拒绝 `before()` 钩子：被 CIDR 名单排除的客户端以 `403` 被拒绝。

## English

### The problem it solves

You often want to keep a whole range of addresses out (or let only a known network in) before any other work happens — an internal network, an office range, a known-malicious subnet. Without this, you have no cheap way to block a subnet; you would have to repeat the logic in every handler. `IpFilterGuard` makes one CIDR list the whole answer, and refuses the clients it excludes with a `403`.

**Terms**

- *allowlist / denylist* — A list of what is allowed / a list of what is refused.
- *CIDR* — A way to write a network as "address/prefix length", e.g. `10.0.0.0/8`.
- *client IP / REMOTE_ADDR* — The address of the peer the request came from.
- *reverse proxy* — A proxy that makes `REMOTE_ADDR` the proxy's own address, so you must normalize it yourself.

### What it does

`IpFilterGuard` decides from the client's IP address whether a request may continue. Two named constructors spell out the intent: `allowing()` refuses every client *not* in the list, `blocking()` refuses every client *in* it. The list holds CIDR networks, so a whole subnet is one entry, and a bare address such as `'10.0.0.7'` is just a `/32` — or a `/128` for IPv6. A refused request gets a `403` and is short-circuited before routing. The client IP is read from `$request->server['REMOTE_ADDR']`; the guard deliberately does not read `X-Forwarded-For` on its own, because trusting that header without a known trusted-proxy list is just spoofing. It is a guard rather than a middleware because its decision is a pure pre-decision with nothing to run on the way out.

### Quick start

The smallest wiring — build the guard with a named constructor:

```php
use MiGears\WebGuards\IpFilter\IpFilterGuard;

$guard = IpFilterGuard::allowing('10.0.0.0/8', '192.168.0.0/16', '2001:db8::/32');

$rest->before($guard);
```

A complete bootstrap:

```php
use MiGears\Web\MiRest;
use MiGears\Web\Request;
use MiGears\WebGuards\IpFilter\IpFilterGuard;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

$rest = new MiRest(baseDir: __DIR__ . '/resources', namespace: 'App\\Resources');
$rest->set(LoggerInterface::class, static fn (): LoggerInterface => new NullLogger());

// Allow only the office networks; every other client is refused with a 403.
$rest->before(IpFilterGuard::allowing('203.0.113.0/24', '2001:db8::/32'));

$rest->handle(Request::fromGlobals())->send();
```

### API reference

Construction is through two static factories; the constructor is private.

| Factory | Return | Meaning |
|---|---|---|
| `IpFilterGuard::allowing(string ...$cidrs)` | `self` | Allow only clients inside `$cidrs`; everyone else is refused. |
| `IpFilterGuard::blocking(string ...$cidrs)` | `self` | Refuse clients inside `$cidrs`; everyone else continues. |

`__invoke(Request $request): ?Response`:

| Condition | Outcome | Status | Response headers | Body |
|---|---|---|---|---|
| allowlist, client IP matches | request continues | — | — | — |
| allowlist, client IP does not match (including no `REMOTE_ADDR`) | request refused | `403` | `Content-Type: application/json; charset=utf-8` | `{"error":"Forbidden"}` |
| denylist, client IP matches | request refused | `403` | `Content-Type: application/json; charset=utf-8` | `{"error":"Forbidden"}` |
| denylist, client IP does not match (including no `REMOTE_ADDR`) | request continues | — | — | — |

An empty allowlist refuses everybody; an empty denylist refuses nobody.

Exceptions (`InvalidArgumentException`, thrown from the factory / parsing, never from `__invoke()`):

- A value is not an IP address or CIDR network: `Not an IP address or CIDR network: '<value>'.`
- A CIDR prefix is empty or not all digits — e.g. `'10.0.0.0/'`, `'10.0.0.0/x'`, `'10.0.0.0/-1'`: `Not a valid CIDR prefix in '<value>'.`
- A CIDR prefix exceeds the address width (`32` for IPv4, `128` for IPv6): `CIDR prefix out of range in '<value>': 0..<max>.`

### Pitfalls

- The client IP is `REMOTE_ADDR`. Behind a reverse proxy that is the proxy's own address, so normalize `REMOTE_ADDR` upstream if you need the real client. The guard never reads `X-Forwarded-For`; trusting it without a trusted-proxy list is spoofing.
- A missing or empty `REMOTE_ADDR`, or one that is not a string, counts as "no match": on an allowlist such a request is refused (nobody in the list), on a denylist it is allowed.
- Invalid CIDRs are rejected at construction, not at request time — a typo in your list fails loudly at bootstrap rather than silently matching or missing.
- IPv4 and IPv6 never match each other: a length mismatch between the packed addresses is treated as a family mismatch.
- A prefix of `0` covers the whole family; `'0.0.0.0/0'` (or an allowlist `allowing('0.0.0.0/0')`) matches every IPv4 client.

### Composition & ordering

- Register it early — often first — so disallowed clients are refused before any authentication, authorization or per-user work happens.
- Register it before `AuthGuard` when the IP list is itself the gate for a private or admin surface.
- Because a non-`null` return short-circuits the `before()` chain, nothing registered after a refusing `IpFilterGuard` runs on that request.

### Typical scenarios

- **Private admin surface** — only the office networks:
  ```php
  IpFilterGuard::allowing('203.0.113.0/24');
  ```
- **Block a subnet** — deny a specific range while leaving everything else open:
  ```php
  IpFilterGuard::blocking('198.51.100.0/24');
  ```
- **Single host** — a bare address is a `/32`:
  ```php
  IpFilterGuard::allowing('127.0.0.1', '::1');
  ```

## 中文

### 它解决什么问题

你常常想在任何其它工作之前，就先把一整段地址挡在外面（或只放行某个已知网段进来）——比如内网、办公网、已知的恶意网段。没有它，你无法廉价地封掉一个子网，只能把这段逻辑在每个处理里重复一遍。`IpFilterGuard` 让一份 CIDR 名单成为全部答案，把被排除的客户端以 `403` 拒掉。

**术语**

- *allowlist / denylist(允许名单 / 拒绝名单)* —— 列出允许的对象 / 列出拒绝的对象。
- *CIDR* —— 用「地址/前缀长度」表示一个网段，如 `10.0.0.0/8`。
- *client IP / REMOTE_ADDR* —— 请求对端地址。
- *reverse proxy(反向代理)* —— 它会让 `REMOTE_ADDR` 变成代理自己的地址，需要你自己规范化。

### 它做什么

`IpFilterGuard` 依据客户端 IP 地址判定请求能否继续。两个具名工厂道出了意图：`allowing()` 拒绝所有**不在**名单内的客户端，`blocking()` 拒绝所有**在**名单内的客户端。名单里放的是 CIDR 网段，所以整个子网就是一个条目，而像 `'10.0.0.7'` 这样的裸地址就等价于 `/32`——IPv6 则为 `/128`。被拒的请求收到 `403`，并在路由之前被短路。客户端 IP 取自 `$request->server['REMOTE_ADDR']`；守卫刻意不自行读取 `X-Forwarded-For`，因为在一份可信代理名单之外信任该头，等同于放任伪造。它之所以是 guard 而不是 middleware，是因为它的判定是纯粹的前置判定，回程无事可做。

### 快速上手

最小接线——用具名工厂构造守卫：

```php
use MiGears\WebGuards\IpFilter\IpFilterGuard;

$guard = IpFilterGuard::allowing('10.0.0.0/8', '192.168.0.0/16', '2001:db8::/32');

$rest->before($guard);
```

一个完整的启动示例：

```php
use MiGears\Web\MiRest;
use MiGears\Web\Request;
use MiGears\WebGuards\IpFilter\IpFilterGuard;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

$rest = new MiRest(baseDir: __DIR__ . '/resources', namespace: 'App\\Resources');
$rest->set(LoggerInterface::class, static fn (): LoggerInterface => new NullLogger());

// 只放行办公网段；其它客户端一律以 403 拒绝。
$rest->before(IpFilterGuard::allowing('203.0.113.0/24', '2001:db8::/32'));

$rest->handle(Request::fromGlobals())->send();
```

### API 参考

通过两个静态工厂构造；构造函数是私有的。

| 工厂 | 返回 | 含义 |
|---|---|---|
| `IpFilterGuard::allowing(string ...$cidrs)` | `self` | 只放行 `$cidrs` 内的客户端；其余一律拒绝。 |
| `IpFilterGuard::blocking(string ...$cidrs)` | `self` | 拒绝 `$cidrs` 内的客户端；其余继续。 |

`__invoke(Request $request): ?Response`：

| 条件 | 结果 | 状态码 | 响应头 | 响应体 |
|---|---|---|---|---|
| 允许名单，客户端 IP 命中 | 请求继续 | —— | —— | —— |
| 允许名单，客户端 IP 未命中（含没有 `REMOTE_ADDR`） | 请求被拒 | `403` | `Content-Type: application/json; charset=utf-8` | `{"error":"Forbidden"}` |
| 拒绝名单，客户端 IP 命中 | 请求被拒 | `403` | `Content-Type: application/json; charset=utf-8` | `{"error":"Forbidden"}` |
| 拒绝名单，客户端 IP 未命中（含没有 `REMOTE_ADDR`） | 请求继续 | —— | —— | —— |

空的允许名单拒绝所有人；空的拒绝名单不拒绝任何人。

异常（`InvalidArgumentException`，在工厂/解析阶段抛出，`__invoke()` 从不抛出）：

- 某个值不是 IP 地址或 CIDR 网段：`Not an IP address or CIDR network: '<value>'.`
- CIDR 前缀为空或不全是数字——例如 `'10.0.0.0/'`、`'10.0.0.0/x'`、`'10.0.0.0/-1'`：`Not a valid CIDR prefix in '<value>'.`
- CIDR 前缀超出地址宽度（IPv4 为 `32`，IPv6 为 `128`）：`CIDR prefix out of range in '<value>': 0..<max>.`

### 常见坑与注意事项

- 客户端 IP 取自 `REMOTE_ADDR`。位于反向代理之后时那是代理自身的地址，若你需要真实客户端，请在上游把 `REMOTE_ADDR` 规范化。守卫永不读取 `X-Forwarded-For`；在没有可信代理名单的情况下信任它就是放任伪造。
- `REMOTE_ADDR` 缺失、为空或不是字符串时，一律算作「未命中」：在允许名单下这种请求被拒（不在名单内），在拒绝名单下则被放行。
- 非法 CIDR 在构造时就被拒绝，而不是请求时才报错——名单里的笔误会在启动时大声失败，而不是悄悄命中或漏掉。
- IPv4 与 IPv6 永不互相命中：打包地址长度不一致即视为地址族不同。
- 前缀 `0` 覆盖整个地址族；`'0.0.0.0/0'`（或允许名单 `allowing('0.0.0.0/0')`）命中所有 IPv4 客户端。

### 组合与顺序

- 尽早注册——常常放在最前——让不被允许的客户端在任何认证、授权或按用户计算的工作之前就被拒绝。
- 当 IP 名单本身就是私有面或管理面的门槛时，注册在 `AuthGuard` 之前。
- 由于非 `null` 返回会短路 `before()` 链，`IpFilterGuard` 拒绝时，注册在其后的守卫都不会执行。

### 典型场景

- **私有管理面**——只放行办公网段：
  ```php
  IpFilterGuard::allowing('203.0.113.0/24');
  ```
- **封禁一个子网**——拒绝某个特定网段，其余保持开放：
  ```php
  IpFilterGuard::blocking('198.51.100.0/24');
  ```
- **单主机**——裸地址即 `/32`：
  ```php
  IpFilterGuard::allowing('127.0.0.1', '::1');
  ```
