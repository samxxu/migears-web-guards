# CanonicalGuard

> A canonical-URL `before()` hook: a request whose host or trailing slash differs from the configured policy is answered with a redirect to the canonical URL.
> 一个规范化 URL 的 `before()` 钩子：host 或尾斜杠不符合既定策略的请求，会被重定向到规范化 URL。

## English

### The problem it solves

The same page often answers at more than one address: `example.com` and `www.example.com`, or `/orders` and `/orders/`. Search engines, caches and analytics then treat these as different pages, and a cookie set under one address is missing under the other. Pick one form and redirect the rest to it, so they all count as the single page they really are.

**Terms**

- *canonical URL* — The one address officially chosen for a page.
- *301 redirect* — A permanent redirect to that canonical address.
- *trailing slash* — The `/` at the end of a path, kept or dropped by policy.

### What it does
`CanonicalGuard` applies two independent normalizations, either of which can be turned on alone: a **canonical host** (so `example.com` and `www.example.com` are one site, not two) and a **trailing-slash policy** (so `/orders` and `/orders/` do not both answer). Only a request that actually differs is redirected; a canonical request returns `null` and continues untouched, which is what keeps it from looping. The query string is preserved across the hop and the scheme is left as it arrived. It is a pure pre-decision — a guard, not a middleware: it decides and either redirects or gets out of the way, with nothing to run on the way out.

### Quick start
Minimal:

```php
use MiGears\WebGuards\Canonical\CanonicalGuard;

$rest->before(new CanonicalGuard(canonicalHost: 'example.com'));
```

Full bootstrap:

```php
use MiGears\Web\MiRest;
use MiGears\Web\Request;
use MiGears\WebGuards\Canonical\CanonicalGuard;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

$rest = new MiRest(baseDir: __DIR__ . '/resources', namespace: 'App\\Resources');
$rest->set(LoggerInterface::class, fn () => new NullLogger());

// One host, no trailing slash: a differing request is redirected with a 301
// before any handler runs.
$rest->before(new CanonicalGuard(canonicalHost: 'example.com', trailingSlash: false));

$rest->handle(Request::fromGlobals())->send();
```

### API reference
Constructor:

| Parameter | Type | Default | Meaning |
|---|---|---|---|
| `$canonicalHost` | `?string` | `null` | the host every request should use, or `null` to leave the host alone |
| `$trailingSlash` | `?bool` | `null` | `true` to require a trailing slash, `false` to remove it, `null` to ignore |
| `$status` | `int` | `301` | the redirect status; must be a 3xx code |

`__invoke(Request $request): ?Response`:

| Case | Result |
|---|---|
| neither the host nor the path differs | `null` — the request continues |
| the host differs and/or the path differs | `Response::redirect($scheme . '://' . $host . $path . $query, $status)` |

How each difference is decided:

| Piece | Rule |
|---|---|
| host changed | `$canonicalHost !== null` and `strcasecmp($requestHost, $canonicalHost) !== 0` (comparison is case-insensitive) |
| path changed | after applying the trailing-slash policy, the path differs from `$request->path` |
| trailing slash | `null` leaves the path as-is; `/` (the root) is always left as-is; otherwise the path is `rtrim($path, '/')` and, when the policy is `true`, one `/` is appended |
| scheme | `https` when the request is secure (`HTTPS` non-empty/non-`off`, else `REQUEST_SCHEME === 'https'`, else port `443`), otherwise `http` |
| query | appended as `'?' . http_build_query($request->query)` only when `$request->query` is non-empty |

The redirect:

| | |
|---|---|
| Status | `$status` (`301` by default) |
| `Location` | `<scheme>://<canonicalHost or requestHost><canonicalPath>[?<query>]` |
| Body | none |

Throws `InvalidArgumentException('The redirect status must be a 3xx code.')` when `$status` is not a 3xx code, and `InvalidArgumentException('The canonical host must not be empty.')` when `$canonicalHost` is a non-null but whitespace-only string.

### Pitfalls
- The host comparison is case-insensitive, but the **redirect uses the configured `$canonicalHost` exactly as written** — so `canonicalHost: 'EXAMPLE.com'` makes every request redirect to `EXAMPLE.com`, not to a lower-cased host.
- The root `/` is never redirected by the trailing-slash policy, under either setting; it is the one path the policy does not touch.
- The trailing-slash policy collapses **all** trailing slashes: `/orders//` becomes `/orders` (policy `false`) or `/orders/` (policy `true`).
- The scheme is preserved, never upgraded. If you need HTTPS, run `HttpsGuard` as well; this guard will happily redirect an `http://` request to `http://`.
- The host is taken from the `Host` header (falling back to `SERVER_NAME`, then `localhost`); a host containing a port keeps that port in the redirect.
- Nothing is rewritten on a request that already matches, so a canonical URL is never re-redirected — the loop is broken by the "only if it differs" check.

### Composition & ordering
- Register it **after `HttpsGuard`**. HttpsGuard fixes the scheme first, so CanonicalGuard's decision runs on an HTTPS request and the two do not bounce a client between an HTTP-scheme redirect and a host redirect.
- It is a redirect, not a refusal, so it can precede or follow refusing guards. Placing both redirects early keeps malformed-host traffic short-circuited before any work is spent on it.

### Typical scenarios
- Pick `www`: `new CanonicalGuard(canonicalHost: 'www.example.com')`
- Pick the bare host: `new CanonicalGuard(canonicalHost: 'example.com')`
- Require trailing slashes: `new CanonicalGuard(trailingSlash: true)`
- Strip them: `new CanonicalGuard(trailingSlash: false)`
- Both at once: `new CanonicalGuard(canonicalHost: 'example.com', trailingSlash: false)`

---

## 中文

### 它解决什么问题

同一个页面往往能从多个地址到达：`example.com` 与 `www.example.com`，或 `/orders` 与 `/orders/`。搜索引擎、缓存与分析系统会把它们当成不同的页面，在一个地址上设下的 cookie 到了另一个地址就不见了。选定一种形态，把其余的跳转过去，它们才算作本来那一个页面。

**术语**

- *canonical URL(规范地址)* —— 同一个页面被官方认定的那一个地址。
- *301 redirect* —— 指向该规范地址的永久重定向。
- *trailing slash(结尾斜杠)* —— 路径末尾的 `/`，按策略保留或去掉。

### 它做什么
`CanonicalGuard` 施加两种彼此独立的规范化，任一种都可以单独开启：**规范化 host**（让 `example.com` 与 `www.example.com` 是一个站点而不是两个），以及**尾斜杠策略**（让 `/orders` 与 `/orders/` 不会都作答）。只有确实存在差异的请求才会被重定向；已经规范的请求返回 `null` 原样继续，这正是它不会陷入循环的原因。query 会跨跳保留，scheme 保持原样。它是一个纯粹的前置判定——是守卫而非中间件：它做决定，要么重定向，要么让路，出去的路上没有东西要跑。

### 快速上手
最小片段：

```php
use MiGears\WebGuards\Canonical\CanonicalGuard;

$rest->before(new CanonicalGuard(canonicalHost: 'example.com'));
```

完整 bootstrap：

```php
use MiGears\Web\MiRest;
use MiGears\Web\Request;
use MiGears\WebGuards\Canonical\CanonicalGuard;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

$rest = new MiRest(baseDir: __DIR__ . '/resources', namespace: 'App\\Resources');
$rest->set(LoggerInterface::class, fn () => new NullLogger());

// 单一 host、不要尾斜杠：有差异的请求在任何处理器运行之前被 301 重定向。
$rest->before(new CanonicalGuard(canonicalHost: 'example.com', trailingSlash: false));

$rest->handle(Request::fromGlobals())->send();
```

### API 参考
构造函数：

| 参数 | 类型 | 默认值 | 含义 |
|---|---|---|---|
| `$canonicalHost` | `?string` | `null` | 每个请求都应使用的 host；`null` 表示不管 host |
| `$trailingSlash` | `?bool` | `null` | `true` 要求尾斜杠，`false` 去掉尾斜杠，`null` 表示忽略 |
| `$status` | `int` | `301` | 重定向状态码；须为 3xx |

`__invoke(Request $request): ?Response`：

| 情形 | 结果 |
|---|---|
| host 与路径都无差异 | `null` —— 请求继续 |
| host 有差异，和 / 或路径有差异 | `Response::redirect($scheme . '://' . $host . $path . $query, $status)` |

两类差异的判定规则：

| 部分 | 规则 |
|---|---|
| host 变了 | `$canonicalHost !== null`，且 `strcasecmp($requestHost, $canonicalHost) !== 0`（大小写不敏感比较） |
| path 变了 | 套用尾斜杠策略后，路径与 `$request->path` 不同 |
| 尾斜杠 | `null` 原样不动；`/`（根路径）始终不动；其余先 `rtrim($path, '/')`，策略为 `true` 时再补一个 `/` |
| scheme | 请求安全时为 `https`（`HTTPS` 非空且非 `off`，否则 `REQUEST_SCHEME === 'https'`，否则端口 `443`），否则为 `http` |
| query | 仅当 `$request->query` 非空时，追加 `'?' . http_build_query($request->query)` |

重定向：

| | |
|---|---|
| 状态码 | `$status`（默认 `301`） |
| `Location` | `<scheme>://<canonicalHost 或 requestHost><canonicalPath>[?<query>]` |
| Body | 无 |

当 `$status` 不是 3xx 时抛出 `InvalidArgumentException('The redirect status must be a 3xx code.')`；当 `$canonicalHost` 非 `null` 但只含空白字符时抛出 `InvalidArgumentException('The canonical host must not be empty.')`。

### 常见坑与注意事项
- host 比较是大小写不敏感的，但**重定向逐字使用配置里的 `$canonicalHost`**——所以 `canonicalHost: 'EXAMPLE.com'` 会让每个请求都跳到 `EXAMPLE.com`，而不是小写化的 host。
- 根路径 `/` 永远不会因尾斜杠策略被重定向，两种设置下都是；它是该策略唯一不碰的路径。
- 尾斜杠策略会压掉**所有**尾部斜杠：`/orders//` 会变成 `/orders`（策略 `false`）或 `/orders/`（策略 `true`）。
- scheme 保持原样，绝不升级。若要 HTTPS，请同时挂上 `HttpsGuard`；本守卫会很乐意把一个 `http://` 请求重定向到 `http://`。
- host 取自 `Host` 头（回退到 `SERVER_NAME`，再回退到 `localhost`）；带端口的 host 会在重定向里保留该端口。
- 已经匹配的请求不会被改写，所以规范化的 URL 不会再被重定向——循环由「仅在存在差异时」这一检查阻断。

### 组合与顺序
- 注册在 **`HttpsGuard` 之后**。HttpsGuard 先把 scheme 定下来，于是 CanonicalGuard 的决策在 HTTPS 请求上做出，两者不会把客户端在「HTTP scheme 重定向」与「host 重定向」之间来回弹。
- 它是重定向而非拒绝，因此放在拒绝型守卫之前或之后都可以。把两个跳转都放靠前，能让 host 畸形的流量在做任何功之前就被短路。

### 典型场景
- 统一到 `www`：`new CanonicalGuard(canonicalHost: 'www.example.com')`
- 统一到裸域：`new CanonicalGuard(canonicalHost: 'example.com')`
- 要求带尾斜杠：`new CanonicalGuard(trailingSlash: true)`
- 去掉尾斜杠：`new CanonicalGuard(trailingSlash: false)`
- 两者同时：`new CanonicalGuard(canonicalHost: 'example.com', trailingSlash: false)`
