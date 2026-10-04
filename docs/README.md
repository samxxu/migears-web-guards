# migears/web-guards documentation

> Tutorials and references for every guard in `migears/web-guards`, one file per guard.
> `migears/web-guards` 每个守卫的教程与参考,一个守卫一个文件。

Every guard is a `before()` hook with the same contract: `callable(Request): ?Response` — return `null` to let the request continue, or a `MiGears\Web\Response` to refuse it and short-circuit the chain. See the [main README](../README.md) for the package overview and the throttle deep-dive.
每个守卫都是契约为 `callable(Request): ?Response` 的 `before()` 钩子——返回 `null` 放行,返回 `MiGears\Web\Response` 拒绝并短路。包概览与限流的展开示例见[主 README](../README.md)。

Each guard's page opens with a plain-language statement of the problem it solves and a short glossary of the terms it uses — so it reads even if the domain is new to you.
每篇守卫文档开篇都用大白话说明它解决什么问题,并附一小段术语解释——即便你没接触过这个领域也能读懂。

- **[integration.md](integration.md)** — wiring the auth guards to `migears/security` and `migears/acl-feature` by composition, with no new dependency.
- **[integration.md](integration.md)** — 如何用组合(不加依赖)把认证类守卫接到 `migears/security` 与 `migears/acl-feature`。

---

## English

### Security

| Guard | Refuses with | Doc |
|---|---|---|
| `Auth\AuthGuard` | `401` | [AuthGuard.md](AuthGuard.md) |
| `Authorization\AuthorizationGuard` | `403` | [AuthorizationGuard.md](AuthorizationGuard.md) |
| `Csrf\CsrfGuard` | `403` | [CsrfGuard.md](CsrfGuard.md) |
| `IpFilter\IpFilterGuard` | `403` | [IpFilterGuard.md](IpFilterGuard.md) |
| `Signature\SignatureGuard` | `401` | [SignatureGuard.md](SignatureGuard.md) |

### Traffic & protection

| Guard | Refuses with | Doc |
|---|---|---|
| `Throttle\ThrottleGuard` | `429` | [ThrottleGuard.md](ThrottleGuard.md) |
| `Maintenance\MaintenanceGuard` | `503` | [MaintenanceGuard.md](MaintenanceGuard.md) |
| `KillSwitch\KillSwitchGuard` | `503` | [KillSwitchGuard.md](KillSwitchGuard.md) |
| `UserAgent\UserAgentGuard` | `403` | [UserAgentGuard.md](UserAgentGuard.md) |

### Protocol & hygiene

| Guard | Refuses with | Doc |
|---|---|---|
| `Https\HttpsGuard` | `301` | [HttpsGuard.md](HttpsGuard.md) |
| `Canonical\CanonicalGuard` | `301` | [CanonicalGuard.md](CanonicalGuard.md) |
| `RequestCheck\ContentTypeGuard` | `415` | [ContentTypeGuard.md](ContentTypeGuard.md) |
| `RequestCheck\PayloadSizeGuard` | `413` | [PayloadSizeGuard.md](PayloadSizeGuard.md) |
| `Cors\CorsGuard` (+ `Cors\CorsHeaders`) | `204` | [CorsGuard.md](CorsGuard.md) |
| `TimeWindow\TimeWindowGuard` | `403` | [TimeWindowGuard.md](TimeWindowGuard.md) |

Note that `CorsGuard` is the one guard that needs a partner: register `CorsGuard` on `before()` and `CorsHeaders` on `after()` with the same `CorsPolicy`. A `before()` hook cannot add headers to a response it did not produce, so the actual-response half must live on the `after()` chain.

### A common registration order

Order is not a rule but a consequence of each guard's decision. A shape that suits a public JSON API:

```php
$rest->before(new HttpsGuard());                                  // move to HTTPS first
$rest->before(new CanonicalGuard(canonicalHost: 'api.example.com'));
$rest->before(new IpFilterGuard::blocking('203.0.113.0/24'));      // drop known-bad clients
$rest->before(new MaintenanceGuard($maintenanceFlag));             // planned downtime
$rest->before(new ThrottleGuard($throttler, $ipKey));              // coarse anti-flood gate
$rest->before(new AuthGuard($authenticate));                       // who the caller is
$rest->before(new AuthorizationGuard($authorize));                 // what they may do
$rest->before(new CsrfGuard($verifyToken));                        // mutating methods only
$rest->before(new ContentTypeGuard(['application/json']));         // body shape
$rest->before(new PayloadSizeGuard(maxBytes: 1_000_000));          // body size

$rest->after(new CorsHeaders($corsPolicy));                        // CORS on the way out
```

Read each guard's **Composition & ordering** section for the reasoning behind its slot.

---

## 中文

### 安全

| 守卫 | 拒绝响应 | 文档 |
|---|---|---|
| `Auth\AuthGuard` | `401` | [AuthGuard.md](AuthGuard.md) |
| `Authorization\AuthorizationGuard` | `403` | [AuthorizationGuard.md](AuthorizationGuard.md) |
| `Csrf\CsrfGuard` | `403` | [CsrfGuard.md](CsrfGuard.md) |
| `IpFilter\IpFilterGuard` | `403` | [IpFilterGuard.md](IpFilterGuard.md) |
| `Signature\SignatureGuard` | `401` | [SignatureGuard.md](SignatureGuard.md) |

### 流量与保护

| 守卫 | 拒绝响应 | 文档 |
|---|---|---|
| `Throttle\ThrottleGuard` | `429` | [ThrottleGuard.md](ThrottleGuard.md) |
| `Maintenance\MaintenanceGuard` | `503` | [MaintenanceGuard.md](MaintenanceGuard.md) |
| `KillSwitch\KillSwitchGuard` | `503` | [KillSwitchGuard.md](KillSwitchGuard.md) |
| `UserAgent\UserAgentGuard` | `403` | [UserAgentGuard.md](UserAgentGuard.md) |

### 协议与卫生

| 守卫 | 拒绝响应 | 文档 |
|---|---|---|
| `Https\HttpsGuard` | `301` | [HttpsGuard.md](HttpsGuard.md) |
| `Canonical\CanonicalGuard` | `301` | [CanonicalGuard.md](CanonicalGuard.md) |
| `RequestCheck\ContentTypeGuard` | `415` | [ContentTypeGuard.md](ContentTypeGuard.md) |
| `RequestCheck\PayloadSizeGuard` | `413` | [PayloadSizeGuard.md](PayloadSizeGuard.md) |
| `Cors\CorsGuard`（配 `Cors\CorsHeaders`） | `204` | [CorsGuard.md](CorsGuard.md) |
| `TimeWindow\TimeWindowGuard` | `403` | [TimeWindowGuard.md](TimeWindowGuard.md) |

注意 `CorsGuard` 是唯一需要搭档的守卫:把 `CorsGuard` 注册到 `before()`、`CorsHeaders` 注册到 `after()`,两者共用同一个 `CorsPolicy`。`before()` 钩子无法给它自己没产出的响应加头,所以「实际响应」那一半必须放在 `after()` 链上。

### 一种常见的注册顺序

顺序不是规则,而是每个守卫判定的后果。下面这个形状适合一个公开的 JSON API:

```php
$rest->before(new HttpsGuard());                                  // 先切到 HTTPS
$rest->before(new CanonicalGuard(canonicalHost: 'api.example.com'));
$rest->before(new IpFilterGuard::blocking('203.0.113.0/24'));      // 挡掉已知坏来源
$rest->before(new MaintenanceGuard($maintenanceFlag));             // 计划内停机
$rest->before(new ThrottleGuard($throttler, $ipKey));              // 粗粒度反洪泛闸门
$rest->before(new AuthGuard($authenticate));                       // 调用方是谁
$rest->before(new AuthorizationGuard($authorize));                 // 他能做什么
$rest->before(new CsrfGuard($verifyToken));                        // 仅改动型方法
$rest->before(new ContentTypeGuard(['application/json']));         // 请求体形状
$rest->before(new PayloadSizeGuard(maxBytes: 1_000_000));          // 请求体大小

$rest->after(new CorsHeaders($corsPolicy));                        // 出站时加 CORS 头
```

每个守卫为什么排在那个位置,见各自文档的 **组合与顺序** 一节。
