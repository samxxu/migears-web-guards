# CorsGuard

> A CORS-preflight `before()` hook, paired with the `after()` hook `CorsHeaders`: the guard answers an OPTIONS preflight, the headers hook decorates the actual response.
> 一个 CORS 预检 `before()` 钩子，与 `after()` 搭档 `CorsHeaders` 成对使用：守卫应答 OPTIONS 预检，headers 钩子装饰实际响应。

## English

### The problem it solves

A browser's same-origin policy stops one page from calling another origin by default. When a single-page app is deployed on a different domain, the server has to say explicitly who may call it and by what means. The browser first sends an `OPTIONS` "preflight" to ask, and the actual response must carry the same answer too — otherwise the browser simply discards it, without telling the app why.

**Terms**

- *CORS* — Cross-origin resource sharing: a server explicitly declaring which other origins may call it.
- *origin* — The scheme + domain + port a request comes from.
- *preflight* — The `OPTIONS` request a browser sends before the real cross-origin call.
- *Access-Control-Allow-Origin* — The response header naming the origin that is allowed.
- *credentials* — The cookies / auth information sent along with a request.
- *Vary* — Tells caches to store the same URL separately for different `Origin` values.

### What it does
A browser sends an `OPTIONS` preflight ahead of a cross-origin call. `CorsGuard` answers it with `204` and the `Access-Control-*` headers the policy allows, before routing runs. An *actual* response needs those origin headers too, but a `before()` hook cannot touch a response it did not produce — so `CorsHeaders` is registered on the `after()` chain and adds them to whatever the resource returned. Both share one `CorsPolicy`, which decides who may call and computes the headers. The pair is the guard pattern at its clearest: a pure pre-decision on one side, response decoration on the other.

### Quick start
Minimal:

```php
use MiGears\WebGuards\Cors\CorsGuard;
use MiGears\WebGuards\Cors\CorsHeaders;
use MiGears\WebGuards\Cors\CorsPolicy;

$policy = new CorsPolicy(['https://app.example.com']);

$rest->before(new CorsGuard($policy));   // answers the preflight
$rest->after(new CorsHeaders($policy));  // decorates the actual response
```

Full bootstrap:

```php
use MiGears\Web\MiRest;
use MiGears\Web\Request;
use MiGears\WebGuards\Cors\CorsGuard;
use MiGears\WebGuards\Cors\CorsHeaders;
use MiGears\WebGuards\Cors\CorsPolicy;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

$rest = new MiRest(baseDir: __DIR__ . '/resources', namespace: 'App\\Resources');
$rest->set(LoggerInterface::class, fn () => new NullLogger());

$policy = new CorsPolicy(
    ['https://app.example.com'],
    ['GET', 'POST'],
    ['Content-Type', 'Authorization'],
    maxAge: 600,
    allowCredentials: true,
);

$rest->before(new CorsGuard($policy));
$rest->after(new CorsHeaders($policy));

$rest->handle(Request::fromGlobals())->send();
```

### API reference

**`CorsPolicy`** — the shared configuration.

| Parameter | Type | Default | Meaning |
|---|---|---|---|
| `$allowedOrigins` | `list<string>` | — (required) | origins that may call, or `['*']` for any |
| `$allowedMethods` | `list<string>` | `['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS']` | methods advertised on a preflight |
| `$allowedHeaders` | `list<string>` | `[]` | request headers advertised on a preflight |
| `$maxAge` | `int` | `0` | seconds a preflight may be cached; `0` omits the header |
| `$allowCredentials` | `bool` | `false` | whether the browser may send credentials |

Its members are `allowsOrigin(string $origin): bool` (`true` when `'*'` is allowed or `$origin` matches exactly), `originHeaders(string $origin): array<string, string>` (the headers an actual response carries) and `preflightHeaders(string $origin): array<string, string>` (`originHeaders()` plus the preflight-only headers). `originHeaders()` returns `Access-Control-Allow-Origin` (the `$origin` echoed, or `'*'` when a wildcard is allowed and credentials are off), `Vary: Origin` whenever the origin is echoed rather than wildcarded, and `Access-Control-Allow-Credentials: true` when `$allowCredentials` is on. `preflightHeaders()` adds `Access-Control-Allow-Methods` (always), `Access-Control-Allow-Headers` (only when `$allowedHeaders` is non-empty) and `Access-Control-Max-Age` (only when `$maxAge > 0`). Preflight-only headers never appear on an actual response.

**`CorsGuard`** — the `before()` hook. `__construct(CorsPolicy $policy)`; `__invoke(Request $request): ?Response`, so it is invokable and `$rest->before($guard)` is the wiring.

| Case | Result |
|---|---|
| method is not `OPTIONS` (case-insensitive) | `null` — the request continues |
| `OPTIONS` without an `Origin` header (an ordinary OPTIONS) | `null` — left to the resource |
| `OPTIONS` with an `Origin` the policy allows | `204` with the `preflightHeaders()` |
| `OPTIONS` with an `Origin` the policy does not allow | `204` with **no** CORS headers |

The preflight response is `Response::empty(204)`; the headers are added with `withHeader()`.

**`CorsHeaders`** — the `after()` hook. `__construct(CorsPolicy $policy)`; `__invoke(Request $request, Response $response): Response`, which mutates and returns the same response per the `after()` contract.

| Case | Result |
|---|---|
| no `Origin`, or the origin is not allowed | the response is returned unchanged |
| the origin is allowed | `originHeaders()` are added, and the same response is returned |

Exceptions, all `InvalidArgumentException`: an empty `$allowedOrigins` throws `At least one allowed origin is required (use ['*'] to allow any).`; `$maxAge < 0` throws `The max age must not be negative.`; a whitespace-only origin / method / header entry throws `A CORS origin must not be empty.` / `A CORS method must not be empty.` / `A CORS header must not be empty.`

### Pitfalls
- **Register both hooks.** `CorsGuard` alone answers preflights but puts nothing on a real response; `CorsHeaders` alone never answers a preflight. Omitting either half is the most common mistake.
- A preflight from a **disallowed** origin is still answered with `204` — deliberately, and with no CORS headers — so the browser is told "no" without the application having to know. Do not read the `204` as approval.
- A wildcard origin is emitted literally as `'*'` only when credentials are off. With `allowCredentials: true`, the request's own origin is echoed and `Vary: Origin` is added, because `'*'` and credentials are not allowed together.
- `Vary: Origin` rides along whenever the origin is echoed (a specific origin list, or a wildcard with credentials), and is set with `withHeader()`, so it replaces any existing `Vary`.
- The preflight ignores `Access-Control-Request-Method` and `Access-Control-Request-Headers`: it advertises the configured `allowedMethods` / `allowedHeaders`. Make the policy match your routes.
- `maxAge: 0` **omits** `Access-Control-Max-Age`; it does not mean "cache for zero seconds".
- `allowedOrigins` and `allowedHeaders` are trimmed but not case-normalized, and origin matching is exact — keep the scheme/host casing in the policy consistent with the `Origin` header.
- Only `OPTIONS` with an `Origin` is claimed; a plain `OPTIONS` falls through to the resource, so a route that answers `OPTIONS` itself still does.

### Composition & ordering
- Put `CorsGuard` in the `before()` list and `CorsHeaders` in the `after()` list, both built from the same `$policy`. The `after` chain is flat and ordered, so there is no ordering trick — just register both.
- In the `before()` chain, a preflight should be answered **before** guards that would refuse it for unrelated reasons. It carries no credentials or body, so auth, CSRF, content-type and payload checks have nothing to judge; ahead of them, the browser gets its answer instead of a `401`/`415`.

### Typical scenarios
- Single-page app on one origin: `new CorsPolicy(['https://app.example.com'])`
- Public, credential-free API: `new CorsPolicy(['*'])`
- Cookie-based auth cross-origin: `new CorsPolicy(['https://app.example.com'], allowCredentials: true)`
- Cache preflights for ten minutes: `new CorsPolicy(['*'], maxAge: 600)`

---

## 中文

### 它解决什么问题

浏览器的同源策略默认禁止一个页面去调用另一个来源。当一个单页应用被部署在另一个域名上时，服务器必须明确说明「谁可以来调、可以用什么方式调」。浏览器会先发一个 `OPTIONS`「预检」来问，而且实际响应也必须带上同样的答复——否则浏览器会直接把它丢弃，还不告诉应用原因。

**术语**

- *CORS* —— 跨源资源共享：服务器显式声明允许哪些其它来源来调用。
- *origin* —— 来源：由协议 + 域名 + 端口组成。
- *preflight* —— 预检：浏览器在真正的跨源请求之前先发的那次 `OPTIONS`。
- *Access-Control-Allow-Origin* —— 声明允许哪个来源的响应头。
- *credentials* —— 凭证：随请求一起发送的 cookie / 认证信息。
- *Vary* —— 告诉缓存同一个 URL 对不同的 `Origin` 要分别缓存。

### 它做什么
浏览器在发起跨源调用之前会先发一个 `OPTIONS` 预检。`CorsGuard` 在路由运行之前用 `204` 加上策略允许的 `Access-Control-*` 响应头应答它。*实际*响应也需要那些来源响应头，但 `before()` 钩子够不到一个并非自己产出的响应——于是 `CorsHeaders` 注册在 `after()` 链上，把它们加到资源返回的响应上。两者共享同一个 `CorsPolicy`，由它判定谁能调用、并算出响应头。这一对最能说明守卫模式：一边是纯粹的前置判定，另一边是响应装饰。

### 快速上手
最小片段：

```php
use MiGears\WebGuards\Cors\CorsGuard;
use MiGears\WebGuards\Cors\CorsHeaders;
use MiGears\WebGuards\Cors\CorsPolicy;

$policy = new CorsPolicy(['https://app.example.com']);

$rest->before(new CorsGuard($policy));   // 应答预检
$rest->after(new CorsHeaders($policy));  // 装饰实际响应
```

完整 bootstrap：

```php
use MiGears\Web\MiRest;
use MiGears\Web\Request;
use MiGears\WebGuards\Cors\CorsGuard;
use MiGears\WebGuards\Cors\CorsHeaders;
use MiGears\WebGuards\Cors\CorsPolicy;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

$rest = new MiRest(baseDir: __DIR__ . '/resources', namespace: 'App\\Resources');
$rest->set(LoggerInterface::class, fn () => new NullLogger());

$policy = new CorsPolicy(
    ['https://app.example.com'],
    ['GET', 'POST'],
    ['Content-Type', 'Authorization'],
    maxAge: 600,
    allowCredentials: true,
);

$rest->before(new CorsGuard($policy));
$rest->after(new CorsHeaders($policy));

$rest->handle(Request::fromGlobals())->send();
```

### API 参考

**`CorsPolicy`** —— 共享配置。

| 参数 | 类型 | 默认值 | 含义 |
|---|---|---|---|
| `$allowedOrigins` | `list<string>` | —(必填) | 允许调用的来源，或 `['*']` 表示任意 |
| `$allowedMethods` | `list<string>` | `['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS']` | 预检时通告的方法 |
| `$allowedHeaders` | `list<string>` | `[]` | 预检时通告的请求头 |
| `$maxAge` | `int` | `0` | 预检可缓存秒数；`0` 表示省略该头 |
| `$allowCredentials` | `bool` | `false` | 浏览器是否可携带凭证 |

其成员为 `allowsOrigin(string $origin): bool`（允许 `'*'` 或 `$origin` 精确匹配时为 `true`）、`originHeaders(string $origin): array<string, string>`（实际响应携带的头）、`preflightHeaders(string $origin): array<string, string>`（`originHeaders()` 加预检专用头）。`originHeaders()` 返回 `Access-Control-Allow-Origin`（回显 `$origin`；当允许通配来源且未开启凭证时用 `'*'`），只要来源是被回显而非通配就附 `Vary: Origin`，并在 `$allowCredentials` 开启时加 `Access-Control-Allow-Credentials: true`。`preflightHeaders()` 在其上增加 `Access-Control-Allow-Methods`（总是）、`Access-Control-Allow-Headers`（仅当 `$allowedHeaders` 非空）、`Access-Control-Max-Age`（仅当 `$maxAge > 0`）。预检专用头绝不会出现在实际响应上。

**`CorsGuard`** —— `before()` 钩子。`__construct(CorsPolicy $policy)`；`__invoke(Request $request): ?Response`，可调用，故 `$rest->before($guard)` 即接线。

| 情形 | 结果 |
|---|---|
| 方法不是 `OPTIONS`（大小写不敏感） | `null` —— 请求继续 |
| `OPTIONS` 不带 `Origin` 头（普通 OPTIONS） | `null` —— 交给资源处理 |
| `OPTIONS` 携带策略允许的 `Origin` | `204`，附 `preflightHeaders()` |
| `OPTIONS` 携带策略不允许的 `Origin` | `204`，且**不带**任何 CORS 头 |

预检响应用 `Response::empty(204)` 构造；上述响应头用 `withHeader()` 加上。

**`CorsHeaders`** —— `after()` 钩子。`__construct(CorsPolicy $policy)`；`__invoke(Request $request, Response $response): Response`，就原地修改并返回同一个响应，符合 `after()` 契约。

| 情形 | 结果 |
|---|---|
| 无 `Origin`，或来源不被允许 | 原样返回该响应 |
| 来源被允许 | 加上 `originHeaders()`，并返回同一个响应 |

异常均为 `InvalidArgumentException`：`$allowedOrigins` 为空时抛 `At least one allowed origin is required (use ['*'] to allow any).`；`$maxAge < 0` 时抛 `The max age must not be negative.`；来源 / 方法 / 头条目只含空白字符时抛 `A CORS origin must not be empty.` / `A CORS method must not be empty.` / `A CORS header must not be empty.`。

### 常见坑与注意事项
- **两个钩子都要注册。** 只挂 `CorsGuard` 能应答预检，却不会给真实响应加任何东西；只挂 `CorsHeaders` 则永远不应答预检。少挂一半是最常见的错误。
- 来自**不被允许**来源的预检仍会被回以 `204`——这是刻意的，且不带任何 CORS 头——好让浏览器被告知「不行」而应用无需知晓。别把这个 `204` 读成批准。
- 通配来源只有在未开启凭证时才被字面输出为 `'*'`。当 `allowCredentials: true` 时，会回显请求自身的来源并加上 `Vary: Origin`，因为 `'*'` 与凭证不能并存。
- 只要来源是被回显（具体来源列表，或通配加凭证），`Vary: Origin` 就会随行，且用 `withHeader()` 设置，因此会替换掉已有的 `Vary`。
- 预检忽略 `Access-Control-Request-Method` 与 `Access-Control-Request-Headers`：它通告的是配置里的 `allowedMethods` / `allowedHeaders`。请让策略与你的路由一致。
- `maxAge: 0` 会**省略** `Access-Control-Max-Age`；它不是「缓存零秒」的意思。
- `allowedOrigins` 与 `allowedHeaders` 只做 trim、不做大小写归一，来源匹配是精确的——请让策略里的 scheme/host 大小写与 `Origin` 头保持一致。
- 只有带 `Origin` 的 `OPTIONS` 会被认领；普通 `OPTIONS` 会落到资源上，因此自行应答 `OPTIONS` 的路由依然生效。

### 组合与顺序
- 把 `CorsGuard` 放进 `before()` 列表、`CorsHeaders` 放进 `after()` 列表，两者用同一个 `$policy` 构造。`after` 链是平铺有序的，没有顺序窍门——都注册上即可。
- 在 `before()` 链里，预检应当在那些会因无关理由拒绝它的守卫**之前**被应答。它不携带凭证与请求体，所以认证、CSRF、content-type 与请求体检查都没有可判定的东西；放在它们前面，浏览器才能拿到答案，而不是一个 `401`/`415`。

### 典型场景
- 单一来源的单页应用：`new CorsPolicy(['https://app.example.com'])`
- 公开、无凭证的 API：`new CorsPolicy(['*'])`
- 跨源携带 Cookie 认证：`new CorsPolicy(['https://app.example.com'], allowCredentials: true)`
- 预检缓存十分钟：`new CorsPolicy(['*'], maxAge: 600)`
