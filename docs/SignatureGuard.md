# SignatureGuard

> A request-signature `before()` hook that refuses a request without a fresh, valid HMAC with a `401`.
> 一个请求签名 `before()` 钩子：没有新鲜、有效的 HMAC 的请求以 `401` 被拒绝。

## English

### The problem it solves

A webhook or a partner API has no browser and no session, so the only thing both sides share is a secret key. Without a check, anyone can POST to your webhook or impersonate the partner; worse, a copy of a real request can be sent again. `SignatureGuard` verifies an HMAC over the request and a fresh timestamp, and refuses with a `401` when either is wrong.

**Terms**

- *HMAC* — A fingerprint computed from a shared secret and a message; changing either makes it stop matching.
- *shared secret* — A key both sides hold and keep private.
- *replay attack* — Sending a captured, genuine request again unchanged.
- *timestamp tolerance* — Accepting only requests new enough, so a replay quickly becomes useless.

### What it does

`SignatureGuard` refuses a request unless it carries a valid HMAC over a payload you define, together with a timestamp fresh enough that a captured signature cannot be replayed. The signed string is `"{timestamp}.{payload}"`: the timestamp comes first, so your payload callable stays free of the replay concern and only has to describe the request itself — method, path, body, whatever you decide. The signature is compared with `hash_equals()`, so a wrong signature is not told apart by timing. A missing, stale or malformed timestamp and a mismatched signature all answer the same bare `401`, because a verifier should not help an attacker tell which part to fix. The secret is passed in and time comes from an injected clock, so a test can drive the replay window forward without sleeping. It is a guard rather than a middleware because the check is a pure pre-decision with nothing to run on the way out.

### Quick start

The smallest wiring:

```php
use MiGears\Web\Request;
use MiGears\WebGuards\Signature\SignatureGuard;

$guard = new SignatureGuard(
    getenv('HMAC_SECRET'),
    static fn (Request $request): string => $request->method . "\n" . $request->path . "\n" . ($request->body['id'] ?? ''),
);

$rest->before($guard);
```

A complete bootstrap:

```php
use MiGears\Web\MiRest;
use MiGears\Web\Request;
use MiGears\WebGuards\Signature\SignatureGuard;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

$rest = new MiRest(baseDir: __DIR__ . '/resources', namespace: 'App\\Resources');
$rest->set(LoggerInterface::class, static fn (): LoggerInterface => new NullLogger());

$rest->before(new SignatureGuard(
    secret: getenv('HMAC_SECRET'),
    payload: static fn (Request $request): string => $request->method . "\n" . $request->path . "\n" . ($request->body['id'] ?? ''),
    algorithm: 'sha256',
    toleranceSeconds: 300,
    signatureHeader: 'X-Signature',
    timestampHeader: 'X-Timestamp',
));

$rest->handle(Request::fromGlobals())->send();
```

### API reference

Constructor:

| Parameter | Type | Default | Meaning |
|---|---|---|---|
| `$secret` | `string` | — (required) | The shared HMAC secret; must not be empty. |
| `$payload` | `callable(Request): string` | — (required) | Builds the string to sign from the request. |
| `$algorithm` | `string` | `'sha256'` | Any algorithm `hash_hmac()` accepts. |
| `$toleranceSeconds` | `int` | `300` | How far a timestamp may drift, in seconds; `>= 1`. |
| `$signatureHeader` | `string` | `'X-Signature'` | The header carrying the hex signature. |
| `$timestampHeader` | `string` | `'X-Timestamp'` | The header carrying the unix timestamp. |
| `$clock` | `?callable(): int` | `null` → `time()` | The current unix time; inject one for tests. |

`__invoke(Request $request): ?Response`:

| Condition | Outcome | Status | Response headers | Body |
|---|---|---|---|---|
| timestamp header missing, not a string, or not all digits | request refused | `401` | `WWW-Authenticate: HMAC` and `Content-Type: application/json; charset=utf-8` | `{"error":"Unauthorized"}` |
| `abs(clock() - timestamp) > toleranceSeconds` | request refused | `401` | same as above | `{"error":"Unauthorized"}` |
| signature header missing/not a string, or `hash_equals()` fails | request refused | `401` | same as above | `{"error":"Unauthorized"}` |
| all checks pass | request continues | — | — | — |

Exceptions (`InvalidArgumentException`, thrown from the constructor, never from `__invoke()`):

- `$secret` is empty: `The signing secret must not be empty.`
- `$algorithm` is not in `hash_hmac_algos()`: `Unsupported HMAC algorithm '<algorithm>'.`
- `$toleranceSeconds < 1`: `The tolerance must be at least 1 second.`
- `$signatureHeader` or `$timestampHeader` is empty: `Header names must not be empty.`

### Pitfalls

- The timestamp must be *all digits* (`ctype_digit`), so a signed or negative timestamp such as `-1` or `+1700000000` is refused even if it is numerically close.
- The comparison is `abs(clock() - timestamp) > toleranceSeconds`, so a timestamp exactly `toleranceSeconds` away (past or future) is still accepted; only drift beyond it is refused.
- Replay protection is bounded by the tolerance: the same signature may be replayed within the window. Keep the tolerance small and, where it matters, reject repeated signature values in your payload/clock setup.
- The signature must be the lowercase hex that `hash_hmac()` produces; an upper-cased or base64-encoded value will not match.
- The payload callable must be deterministic and identical on both sides. Do not fold volatile data (a random nonce, wall-clock fields you do not also send) into it. The timestamp is prepended by the guard, so the payload only describes the request.
- Header lookup is case-insensitive (`Request::header()` lower-cases the name), so `X-Signature` and `x-signature` are the same header.

### Composition & ordering

- Signature verification identifies the caller, so treat it like authentication: register it before `AuthorizationGuard`.
- For machine-to-machine traffic it can stand in for `AuthGuard`; where both are present, keep the signature first so a bad HMAC is a `401` before any session logic runs.
- Register it early, ahead of per-user work; a non-`null` return short-circuits the `before()` chain, so nothing registered after a refusing `SignatureGuard` runs.

### Typical scenarios

- **Provider webhook** — verify an inbound HMAC over the raw path and body:
  ```php
  new SignatureGuard(getenv('WEBHOOK_SECRET'), static fn (Request $r): string => $r->path . "\n" . json_encode($r->body));
  ```
- **Service-to-service API** — sign the method, path and body:
  ```php
  new SignatureGuard($secret, static fn (Request $r): string => $r->method . "\n" . $r->path . "\n" . json_encode($r->body));
  ```
- **Testable window** — inject a clock and a wider tolerance:
  ```php
  new SignatureGuard($secret, $payload, toleranceSeconds: 900, clock: static fn (): int => $fixedNow);
  ```

## 中文

### 它解决什么问题

webhook 和合作方 API 没有浏览器、也没有会话，双方唯一共有的东西是一个共享密钥。没有校验时，任何人都能往你的 webhook POST、冒充合作方；更糟的是，一份真实请求的副本还能被再次重放。`SignatureGuard` 校验请求上的 HMAC 和一个新鲜的时间戳，任一对不上就以 `401` 拒绝。

**术语**

- *HMAC* —— 用共享密钥和消息算出的一枚指纹，消息或密钥任一改动都会让它对不上。
- *shared secret(共享密钥)* —— 双方各自保存、不对外公开的密钥。
- *replay attack(重放攻击)* —— 把截获的真实请求原样再发一次。
- *timestamp tolerance(时间戳容差)* —— 只接受足够新的请求，让重放很快失效。

### 它做什么

`SignatureGuard` 会拒绝一个请求，除非它携带了一段针对你定义的 payload 的有效 HMAC，并附带一个足够新鲜、使被截获的签名无法重放的时间戳。被签名的字符串是 `"{timestamp}.{payload}"`：时间戳在前，因此你的 payload 回调不必操心重放问题，只需描述请求自身——方法、路径、请求体，随你决定。签名以 `hash_equals()` 比较，所以错误签名不会被计时差异区分出来。时间戳缺失、过期或格式错误，与签名不匹配，都回答同一句朴素的 `401`，因为校验方不该帮攻击者分辨要修哪一处。密钥由外部传入，时间来自可注入的时钟，因此测试可以让重放窗口向前走而不必真的休眠。它之所以是 guard 而不是 middleware，是因为这项检查是纯粹的前置判定，回程无事可做。

### 快速上手

最小接线：

```php
use MiGears\Web\Request;
use MiGears\WebGuards\Signature\SignatureGuard;

$guard = new SignatureGuard(
    getenv('HMAC_SECRET'),
    static fn (Request $request): string => $request->method . "\n" . $request->path . "\n" . ($request->body['id'] ?? ''),
);

$rest->before($guard);
```

一个完整的启动示例：

```php
use MiGears\Web\MiRest;
use MiGears\Web\Request;
use MiGears\WebGuards\Signature\SignatureGuard;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

$rest = new MiRest(baseDir: __DIR__ . '/resources', namespace: 'App\\Resources');
$rest->set(LoggerInterface::class, static fn (): LoggerInterface => new NullLogger());

$rest->before(new SignatureGuard(
    secret: getenv('HMAC_SECRET'),
    payload: static fn (Request $request): string => $request->method . "\n" . $request->path . "\n" . ($request->body['id'] ?? ''),
    algorithm: 'sha256',
    toleranceSeconds: 300,
    signatureHeader: 'X-Signature',
    timestampHeader: 'X-Timestamp',
));

$rest->handle(Request::fromGlobals())->send();
```

### API 参考

构造函数：

| 参数 | 类型 | 默认值 | 含义 |
|---|---|---|---|
| `$secret` | `string` | ——（必填） | 共享的 HMAC 密钥；不得为空。 |
| `$payload` | `callable(Request): string` | ——（必填） | 由请求构造出待签名字符串。 |
| `$algorithm` | `string` | `'sha256'` | `hash_hmac()` 接受的任意算法。 |
| `$toleranceSeconds` | `int` | `300` | 时间戳允许的漂移秒数；`>= 1`。 |
| `$signatureHeader` | `string` | `'X-Signature'` | 携带十六进制签名的响应头。 |
| `$timestampHeader` | `string` | `'X-Timestamp'` | 携带 unix 时间戳的响应头。 |
| `$clock` | `?callable(): int` | `null` → `time()` | 当前 unix 时间；测试时可注入。 |

`__invoke(Request $request): ?Response`：

| 条件 | 结果 | 状态码 | 响应头 | 响应体 |
|---|---|---|---|---|
| 时间戳头缺失、不是字符串、或不全是数字 | 请求被拒 | `401` | `WWW-Authenticate: HMAC` 与 `Content-Type: application/json; charset=utf-8` | `{"error":"Unauthorized"}` |
| `abs(clock() - timestamp) > toleranceSeconds` | 请求被拒 | `401` | 同上 | `{"error":"Unauthorized"}` |
| 签名头缺失/不是字符串，或 `hash_equals()` 失败 | 请求被拒 | `401` | 同上 | `{"error":"Unauthorized"}` |
| 所有检查通过 | 请求继续 | —— | —— | —— |

异常（`InvalidArgumentException`，在构造函数抛出，`__invoke()` 从不抛出）：

- `$secret` 为空：`The signing secret must not be empty.`
- `$algorithm` 不在 `hash_hmac_algos()` 中：`Unsupported HMAC algorithm '<algorithm>'.`
- `$toleranceSeconds < 1`：`The tolerance must be at least 1 second.`
- `$signatureHeader` 或 `$timestampHeader` 为空：`Header names must not be empty.`

### 常见坑与注意事项

- 时间戳必须**全为数字**（`ctype_digit`），所以像 `-1` 或 `+1700000000` 这样带符号的时间戳即便数值接近也会被拒绝。
- 比较是 `abs(clock() - timestamp) > toleranceSeconds`，因此恰好相差 `toleranceSeconds`（过去或未来）仍被接受；只有漂移超过它才被拒。
- 重放防护以容差为界：在窗口之内，同一个签名可以被重放。把容差设小，并在意时可结合 payload/时钟设置拒绝重复的签名值。
- 签名必须是 `hash_hmac()` 产出的**小写十六进制**；大写或 base64 编码的值不会匹配。
- payload 回调必须确定，且两端完全一致。不要把易变数据（随机 nonce、你没有一并发送的墙钟字段）揉进去。时间戳由守卫前置，payload 只需描述请求。
- 头部查找大小写不敏感（`Request::header()` 会把名字转小写），所以 `X-Signature` 与 `x-signature` 是同一个头。

### 组合与顺序

- 签名校验识别调用方，所以按认证来对待：注册在 `AuthorizationGuard` 之前。
- 对机器间流量，它可以替代 `AuthGuard`；两者都在时把签名放在最前，让错误的 HMAC 在任何会话逻辑之前就成为 `401`。
- 尽早注册，排在按用户计算的工作之前；非 `null` 返回会短路 `before()` 链，因此 `SignatureGuard` 拒绝时，注册在其后的守卫都不会执行。

### 典型场景

- **上游 webhook**——校验对原始路径与请求体的入站 HMAC：
  ```php
  new SignatureGuard(getenv('WEBHOOK_SECRET'), static fn (Request $r): string => $r->path . "\n" . json_encode($r->body));
  ```
- **服务间 API**——对方法、路径与请求体签名：
  ```php
  new SignatureGuard($secret, static fn (Request $r): string => $r->method . "\n" . $r->path . "\n" . json_encode($r->body));
  ```
- **可测窗口**——注入时钟并放宽容差：
  ```php
  new SignatureGuard($secret, $payload, toleranceSeconds: 900, clock: static fn (): int => $fixedNow);
  ```
