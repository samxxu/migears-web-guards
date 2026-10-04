# UserAgentGuard

> A `before()` hook that turns clients away by User-Agent substring — `blocking()` refuses any that contains a pattern, `allowing()` refuses any that contains none.
> 一个按 User-Agent 子串把客户端挡在外面的 `before()` 钩子——`blocking()` 拒绝包含任一规则的,`allowing()` 拒绝一个都不包含的。

## English

### The problem it solves

Many scripts and crawlers announce themselves in the User-Agent header. Turning away the most obvious ones is a cheap first filter that costs almost nothing to add. But anyone can write anything in that header, so treat it as a speed bump, not a security boundary: it slows a lazy client down and stops nothing that is actually trying.

**Terms**

- *User-Agent* — the piece of text a client sends with its request to describe itself.
- *allowlist / denylist* — the list of User-Agents to let through / to refuse.
- *spoofing* — forging the User-Agent by simply writing something else.

### What it does
`UserAgentGuard` reads the request's `User-Agent` header and matches it, case-insensitively, as a substring against a list of patterns. `blocking(...)` refuses clients whose User-Agent contains any of them; `allowing(...)` refuses clients whose User-Agent contains none. A missing or empty User-Agent counts as **no match**, so `blocking()` lets it through and `allowing()` refuses it. The refusal is a `403` produced before dispatch — a pure pre-decision with no handler involved, so it is a guard, not a middleware.

### Quick start
Minimal:

```php
use MiGears\WebGuards\UserAgent\UserAgentGuard;

$rest->before(UserAgentGuard::blocking('curl/', 'wget/', 'python-requests'));
```

Full bootstrap:

```php
use MiGears\Web\MiRest;
use MiGears\Web\Request;
use MiGears\WebGuards\UserAgent\UserAgentGuard;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

$rest = new MiRest(baseDir: __DIR__ . '/resources', namespace: 'App\\Resources');
$rest->set(LoggerInterface::class, fn () => new NullLogger());

$rest->before(UserAgentGuard::blocking('curl/', 'wget/', 'python-requests', 'scrapy'));

$rest->handle(Request::fromGlobals())->send();
```

### API reference
Constructor — **private**; obtain an instance through one of the two named constructors:

```php
private function __construct(array $needles, bool $allow)
```

| Factory | `$allow` | Refuses when the User-Agent |
|---|---|---|
| `UserAgentGuard::blocking(string ...$needles): self` | `false` | contains any needle |
| `UserAgentGuard::allowing(string ...$needles): self` | `true` | contains none of the needles |

Matching: the header value is lower-cased, each needle is lower-cased at construction, and a needle hits if `str_contains()` finds it. A missing header, or a header value that is not a string, is treated as the empty string.

`__invoke(Request $request): ?Response`:

| Case | Result |
|---|---|
| The match verdict equals the allow flag | `null` — the request continues |
| The match verdict differs from the allow flag | `403`, header `Content-Type: application/json; charset=utf-8`, body `{"error":"Forbidden"}` |

Concretely: on a denylist a match refuses; on an allowlist a match continues. Throws `InvalidArgumentException` with the message `A User-Agent pattern must not be empty.` when a pattern is empty or whitespace-only.

### Pitfalls
- Matching is a plain case-insensitive **substring** test — `allow` matches `allowlist`, and `curl` would match any UA that happens to contain those four letters, not just `curl/`. Choose needles specific enough to avoid collateral, e.g. `curl/` rather than `curl`.
- A missing or empty User-Agent is "no match": `blocking()` lets it through, but `allowing()` refuses it. That is the opposite of what an allowlist often intends, so an allowlist will also turn away clients that send no UA at all.
- The emptiness check runs on `trim()`, but the stored needle is only lower-cased, not trimmed. A pattern with surrounding spaces that is not entirely whitespace keeps those spaces and becomes a needle that can never match as intended.
- A User-Agent is trivially forged, so this is a **speed bump, not a security boundary**. Anything that must actually be protected needs authentication. Do not use it to keep attackers out.
- `allowing()` with no patterns matches nothing, so it refuses *every* request; `blocking()` with no patterns matches nothing and lets everything through.

### Composition & ordering
Register it **early** in the `before()` chain — a cheap header test can discard known scrapers and CLI clients before authentication, throttling or any handler does work. But note it then runs on every request, including ones that end in a `404`; that is usually what a UA filter wants. If only one endpoint should care about the User-Agent, pull the guard into that resource's own `before()` so it only runs for requests that reached it. Keep it after `MaintenanceGuard` and `KillSwitchGuard`, which should be able to answer during an outage regardless of who the client claims to be.

### Typical scenarios
- Turn away scripted clients: `UserAgentGuard::blocking('curl/', 'wget/', 'python-requests', 'scrapy')`.
- Allow only browsers and named bots: `UserAgentGuard::allowing('mozilla', 'googlebot', 'bingbot')`.
- Refuse headless browsers: `UserAgentGuard::blocking('headlesschrome', 'phantomjs')`.
- Single endpoint: call the guard from the resource's `before()` so the check is scoped to that endpoint.

---

## 中文

### 它解决什么问题

很多脚本和爬虫会在 User-Agent 请求头里自报家门。把最明显的那一批挡在门外,是一层几乎不花什么代价就能加上的初筛。但这个头谁都能随手编造,所以要把它当成减速带,而不是安全边界:它能拖慢一个偷懒的客户端,却挡不住任何真正有心为之的人。

**术语**

- *User-Agent* —— 客户端随请求发送、用来自报身份的一串文本。
- *allowlist / denylist(允许名单 / 拒绝名单)* —— 允许通过 / 拒绝的 User-Agent 名单。
- *spoofing(伪造)* —— 把 User-Agent 随意写成别的东西。

### 它做什么
`UserAgentGuard` 读取请求的 `User-Agent` 请求头,以大小写不敏感的子串方式与一组规则比对。`blocking(...)` 拒绝 User-Agent 包含其中任意一条的客户端;`allowing(...)` 拒绝一条都不包含的客户端。缺失或为空的 User-Agent 算作**不匹配**,所以 `blocking()` 放行它,而 `allowing()` 拒绝它。拒绝响应是在分发之前产出的 `403`——一个不牵涉任何处理器的纯粹前置判定,所以它是守卫而非中间件。

### 快速上手
最小片段:

```php
use MiGears\WebGuards\UserAgent\UserAgentGuard;

$rest->before(UserAgentGuard::blocking('curl/', 'wget/', 'python-requests'));
```

完整 bootstrap:

```php
use MiGears\Web\MiRest;
use MiGears\Web\Request;
use MiGears\WebGuards\UserAgent\UserAgentGuard;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

$rest = new MiRest(baseDir: __DIR__ . '/resources', namespace: 'App\\Resources');
$rest->set(LoggerInterface::class, fn () => new NullLogger());

$rest->before(UserAgentGuard::blocking('curl/', 'wget/', 'python-requests', 'scrapy'));

$rest->handle(Request::fromGlobals())->send();
```

### API 参考
构造函数——**私有**;只能通过下面两个具名工厂取得实例:

```php
private function __construct(array $needles, bool $allow)
```

| 工厂方法 | `$allow` | 何时拒绝该 User-Agent |
|---|---|---|
| `UserAgentGuard::blocking(string ...$needles): self` | `false` | 包含任意一条规则 |
| `UserAgentGuard::allowing(string ...$needles): self` | `true` | 一条规则都不包含 |

匹配方式:请求头的值转小写,每条规则在构造时也转小写,规则命中当且仅当 `str_contains()` 找得到它。请求头缺失、或值不是字符串时,一律按空字符串处理。

`__invoke(Request $request): ?Response`:

| 情形 | 结果 |
|---|---|
| 匹配结论与 allow 标志相同 | `null` —— 请求继续 |
| 匹配结论与 allow 标志不同 | `403`,响应头 `Content-Type: application/json; charset=utf-8`,响应体 `{"error":"Forbidden"}` |

具体说:在拒绝名单上命中即拒绝;在允许名单上命中即放行。当某条规则为空或仅含空白时,抛出 `InvalidArgumentException`,消息为 `A User-Agent pattern must not be empty.`。

### 常见坑与注意事项
- 匹配是纯粹的大小写不敏感**子串**比对——`allow` 会命中 `allowlist`,`curl` 也会命中任何恰好含这四个字母的 UA,而不只是 `curl/`。规则要选得足够具体以免误伤,例如用 `curl/` 而非 `curl`。
- 缺失或为空的 User-Agent 算「不匹配」:`blocking()` 放行它,但 `allowing()` 拒绝它。这往往与允许名单的本意相反,所以允许名单也会把完全不发 UA 的客户端挡在外面。
- 空值检查走的是 `trim()`,但存下来的规则只做了小写转换、并没有 trim。一条带首尾空格、但并非全空白的规则会把这些空格一起存进去,于是它永远无法按预期命中。
- User-Agent 极易伪造,所以这只是**减速带,不是安全边界**。真正需要保护的东西必须依靠认证。不要指望它把攻击者挡在外面。
- `allowing()` 不传任何规则时什么都不匹配,于是拒绝**每一个**请求;`blocking()` 不传任何规则时什么都不匹配,于是全部放行。

### 组合与顺序
把它注册在 `before()` 链的**靠前**位置——一次廉价的请求头检查,可以在认证、限流或任何处理器做功之前就把已知的爬虫和命令行客户端丢掉。但要注意它此后会对每个请求都运行,包括最终 `404` 的那些;这通常正是 UA 过滤想要的。若只有一个端点该关心 User-Agent,就把守卫拉进该资源自己的 `before()`,让它只为到达该资源的请求运行。把它排在 `MaintenanceGuard` 与 `KillSwitchGuard` 之后——停机期间无论客户端自称是谁,那两个守卫都应当能给出回答。

### 典型场景
- 挡掉脚本化客户端:`UserAgentGuard::blocking('curl/', 'wget/', 'python-requests', 'scrapy')`。
- 只放行浏览器与指定爬虫:`UserAgentGuard::allowing('mozilla', 'googlebot', 'bingbot')`。
- 拒绝无头浏览器:`UserAgentGuard::blocking('headlesschrome', 'phantomjs')`。
- 单个端点:在资源的 `before()` 里调用该守卫,让检查只作用于该端点。
