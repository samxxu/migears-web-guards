# Integration with migears/security and migears/acl-feature

> How to wire the auth guards to miGears' security and ACL modules — by composition, with no new dependency.
> 如何把认证类守卫接到 miGears 的 security 与 ACL 模块——用组合,不加新依赖。

## English

### The rule: compose, don't depend

`web-guards` deliberately requires neither `migears/security` nor `migears/acl-feature`. Every guard takes its decision as a callable (or a small config object), and both modules fit that seam directly, so the wiring lives in your bootstrap and the packages stay independent. Adding them as `require`s would put their constraints on every user of a rate limiter or a CORS guard, and an adapter class would hard-code the coupling that the callable seam exists to avoid.

If you want the hint, put it in your own `composer.json` — not this package's.

### AuthGuard with `migears/security`

`MiGears\Security\AuthInterface` exposes `isLoggedIn(): bool` and `getCurrentUser(): ?object`. `isLoggedIn()` already has the shape `AuthGuard` wants:

```php
use MiGears\Security\MiAuth;
use MiGears\Web\Request;
use MiGears\WebGuards\Auth\AuthGuard;

// $users is your repository; the user object is yours.
$auth = MiAuth::classic(userLoader: static fn (string $id): ?object => $users->find($id));

$rest->before(new AuthGuard(
    static fn (Request $request): bool => $auth->isLoggedIn(),
));
```

Two things matter:

- **Register `$auth` as one container singleton.** `MiAuth::getCurrentUser()` memoises its answer on the instance (`$resolved`), so a shared instance resolves the user once per request; a fresh instance per guard would resolve it again — including consuming a remember-me cookie.
- **`MiAuth::classic()` uses `$_SESSION` / `setcookie()`.** That is fine in a normal request lifecycle, and it is why the guards themselves stay free of sessions: the callable closes over `$auth`, and the guard only ever sees a `Request`.

### AuthorizationGuard with `migears/acl-feature`

`MiGears\AclFeature\AclFeature::allows(string $method, string $path, string|array $roles, int|string|null $userId): bool` is a pure function of its config and its arguments — exactly a policy:

```php
use MiGears\AclFeature\AclFeature;
use MiGears\Web\Request;
use MiGears\WebGuards\Authorization\AuthorizationGuard;

$acl = AclFeature::fromFile(__DIR__ . '/config/acl-feature.php');

$rest->before(new AuthorizationGuard(
    static function (Request $request) use ($acl, $auth): bool {
        $user = $auth->getCurrentUser();
        if ($user === null) {
            return false;
        }

        return $acl->withUser($user->getId())->allows(
            $request->method,
            $request->path,
            $user->getRoles(),          // your user object's own method
        );
    },
));
```

`AclFeature` keys its rules by URL path and HTTP method — the same pair a `Request` already carries — so no translation layer is needed. `withUser($id)` binds the subject once, so the per-request call need not pass it. `getRoles()` is your user object's method; `AclFeature` only asks you for the roles as a string or a list.

### CsrfGuard with `MiGears\Security\Csrf`

`MiGears\Security\Csrf::validate(string $userToken, callable $getter): void` **throws** `SecurityException` on failure instead of returning a bool, so bridge it inside the callable:

```php
use MiGears\Security\Csrf;
use MiGears\Security\Exception\SecurityException;
use MiGears\Web\Request;
use MiGears\WebGuards\Csrf\CsrfGuard;

$csrf = new Csrf('_csrf_token');
$get = static fn (string $key): ?string => $_SESSION[$key] ?? null;

$rest->before(new CsrfGuard(
    static function (Request $request) use ($csrf, $get): bool {
        try {
            $csrf->validate((string) $request->header('x-csrf-token'), $get);

            return true;
        } catch (SecurityException) {
            return false;
        }
    },
));
```

### The exception-versus-bool pitfall

Both `AclFeature::assert()` and `Csrf::validate()` report a denial by **throwing**, while a guard's policy callable must return a **bool**. Choosing the throwing call is a real trap:

- `AclFeature::assert()` is its documentation's recommended call site for a resource's `before()`. But a `DeniedException` thrown from `before()` is caught by `MiRest::handle()` as a `Throwable` and answered with **500**, not 403.
- Prefer `AclFeature::allows()` with `AuthorizationGuard` — a bool, and the guard produces the 403 itself. If you do want `assert()`, register `$rest->error()` to map `DeniedException` to a 403 yourself.
- `Csrf::validate()` has no boolean sibling, so the `try/catch` above is the bridge.

### Checklist

- No new `require`. The guards stay callable-shaped.
- One `MiAuth` instance for the whole request (container singleton), so identity resolves once.
- Prefer `allows()` over `assert()`; if you keep `assert()`, map `DeniedException` in `$rest->error()`.
- Bridge a throwing API to a bool inside the callable, never inside the guard.

---

## 中文

### 原则:组合,不依赖

`web-guards` 刻意既不 `require` `migears/security`,也不 `require` `migears/acl-feature`。每个守卫都把判定以回调(或一个小配置对象)接收,而这两个模块正好贴合这条接缝,于是接线落在你的 bootstrap 里,包与包保持独立。把它们写成 `require` 会让每一个用限流器或 CORS 守卫的人都背上它们的版本约束;而加一个适配类,则会把「回调接缝本来就是为了避免」的耦合焊死。

想要这个提示的话,把它写进你自己的 `composer.json`,而不是本包的。

### AuthGuard 与 `migears/security`

`MiGears\Security\AuthInterface` 提供 `isLoggedIn(): bool` 与 `getCurrentUser(): ?object`。`isLoggedIn()` 的形状正是 `AuthGuard` 需要的:

```php
use MiGears\Security\MiAuth;
use MiGears\Web\Request;
use MiGears\WebGuards\Auth\AuthGuard;

// $users 是你的仓储;用户对象也是你的。
$auth = MiAuth::classic(userLoader: static fn (string $id): ?object => $users->find($id));

$rest->before(new AuthGuard(
    static fn (Request $request): bool => $auth->isLoggedIn(),
));
```

两点要紧:

- **把 `$auth` 注册成容器单例。**`MiAuth::getCurrentUser()` 会在实例上 memo 住结果(`$resolved`),共享实例每个请求只解析一次用户;若每个守卫各拿一个新实例,就会重复解析——包括消费 remember-me cookie。
- **`MiAuth::classic()` 使用 `$_SESSION` / `setcookie()`。**在正常的请求生命周期里没问题,这也正是守卫本身不碰会话的原因:回调闭包住 `$auth`,守卫只看到 `Request`。

### AuthorizationGuard 与 `migears/acl-feature`

`MiGears\AclFeature\AclFeature::allows(string $method, string $path, string|array $roles, int|string|null $userId): bool` 是「配置 + 参数」的纯函数——正是策略回调:

```php
use MiGears\AclFeature\AclFeature;
use MiGears\Web\Request;
use MiGears\WebGuards\Authorization\AuthorizationGuard;

$acl = AclFeature::fromFile(__DIR__ . '/config/acl-feature.php');

$rest->before(new AuthorizationGuard(
    static function (Request $request) use ($acl, $auth): bool {
        $user = $auth->getCurrentUser();
        if ($user === null) {
            return false;
        }

        return $acl->withUser($user->getId())->allows(
            $request->method,
            $request->path,
            $user->getRoles(),          // 你的用户对象自己的方法
        );
    },
));
```

`AclFeature` 以 URL 路径与 HTTP 方法为规则键——正是 `Request` 已经带着的那一对,所以不需要翻译层。`withUser($id)` 把主体绑定一次,逐次调用就不必再传。`getRoles()` 是你用户对象的方法;`AclFeature` 只要求你把角色以字符串或列表交出。

### CsrfGuard 与 `MiGears\Security\Csrf`

`MiGears\Security\Csrf::validate(string $userToken, callable $getter): void` 在失败时**抛异常** `SecurityException`,而不是返回 bool,所以在回调里搭一个桥:

```php
use MiGears\Security\Csrf;
use MiGears\Security\Exception\SecurityException;
use MiGears\Web\Request;
use MiGears\WebGuards\Csrf\CsrfGuard;

$csrf = new Csrf('_csrf_token');
$get = static fn (string $key): ?string => $_SESSION[$key] ?? null;

$rest->before(new CsrfGuard(
    static function (Request $request) use ($csrf, $get): bool {
        try {
            $csrf->validate((string) $request->header('x-csrf-token'), $get);

            return true;
        } catch (SecurityException) {
            return false;
        }
    },
));
```

### 异常 vs 布尔:一个真实的坑

`AclFeature::assert()` 与 `Csrf::validate()` 都以**抛异常**表示拒绝,而守卫的策略回调必须返回 **bool**。选了抛异常的那个调用就是个陷阱:

- `AclFeature::assert()` 是它自己文档推荐给资源 `before()` 的调用点。但从 `before()` 抛出的 `DeniedException` 会被 `MiRest::handle()` 当作 `Throwable` 捕获,回应 **500**,而不是 403。
- 更推荐把 `AclFeature::allows()` 配 `AuthorizationGuard`——返回 bool,由守卫自己产出 403。若确实想用 `assert()`,请注册 `$rest->error()` 把 `DeniedException` 自行映射成 403。
- `Csrf::validate()` 没有布尔兄弟,所以上面那段 `try/catch` 就是桥。

### 检查清单

- 不加新的 `require`。守卫保持回调形状。
- 整个请求只用一个 `MiAuth` 实例(容器单例),让身份只解析一次。
- 优先 `allows()` 而非 `assert()`;若保留 `assert()`,在 `$rest->error()` 里映射 `DeniedException`。
- 把「抛异常」的 API 在回调里桥接成 bool,而不是在守卫里。
