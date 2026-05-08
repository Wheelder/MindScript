<?php
/**
 * Wheelder unified Auth + Subscription client (PHP).
 *
 * Drop-in middleware for any Wheelder app to:
 *   1) Sign users in via auth.wheelder.com (OIDC, supports Google/MS/GitHub/etc.)
 *   2) Verify the user has an active POS subscription for THIS app
 *   3) Auto-redirect to https://commerce.wheelder.com/checkout/<product>/<plan>
 *      when no subscription exists.
 *
 * Requires: ext-curl, ext-json, sessions enabled.
 *
 * Quick start in any app:
 *
 *   require __DIR__ . '/WheelderAuthClient.php';
 *   $auth = new WheelderAuthClient([
 *       'product_key'    => 'circular',                 // <-- this app's POS product
 *       'default_plan'   => 'pro',
 *       'client_id'      => getenv('WHEELDER_OIDC_CLIENT_ID'),
 *       'client_secret'  => getenv('WHEELDER_OIDC_CLIENT_SECRET'),
 *       'redirect_uri'   => 'https://circular.wheelder.com/auth/callback',
 *       'pos_api_key'    => getenv('POS_API_KEY'),
 *   ]);
 *
 *   // Routes:
 *   if ($_SERVER['REQUEST_URI'] === '/auth/login')    { $auth->login();    exit; }
 *   if ($_SERVER['REQUEST_URI'] === '/auth/callback') { $auth->callback(); exit; }
 *   if ($_SERVER['REQUEST_URI'] === '/auth/logout')   { $auth->logout();   exit; }
 *
 *   // Protect a page:
 *   $user = $auth->require_active_subscription(); // redirects to login or checkout if needed
 *   echo "Hello {$user['email']}, plan: {$user['plan']}";
 */

declare(strict_types=1);

final class WheelderAuthClient
{
    public const ISSUER     = 'https://auth.wheelder.com/realms/wheelder';
    public const COMMERCE   = 'https://commerce.wheelder.com';

    private array $cfg;
    private ?array $oidc = null;

    public function __construct(array $cfg)
    {
        foreach (['product_key','client_id','client_secret','redirect_uri','pos_api_key'] as $req) {
            if (empty($cfg[$req])) {
                throw new \InvalidArgumentException("WheelderAuthClient: missing required config '$req'");
            }
        }
        $this->cfg = array_merge([
            'default_plan' => 'pro',
            'scopes'       => 'openid profile email',
            'issuer'       => self::ISSUER,
            'commerce'     => self::COMMERCE,
            'after_login'  => '/',           // where to send user after a successful login WITH subscription
            'no_sub_path'  => '/onboarding', // optional landing for first-time / unpaid users
        ], $cfg);

        if (session_status() === PHP_SESSION_NONE) {
            session_name('wheelder_auth');
            session_set_cookie_params(['secure'=>true,'httponly'=>true,'samesite'=>'Lax']);
            session_start();
        }
    }

    /* ---------------- OIDC discovery + flow ---------------- */

    private function discover(): array
    {
        if ($this->oidc !== null) return $this->oidc;
        $url = rtrim($this->cfg['issuer'], '/') . '/.well-known/openid-configuration';
        $json = $this->http_get($url);
        $this->oidc = json_decode($json, true) ?: [];
        return $this->oidc;
    }

    public function login(array $extra = []): void
    {
        $oidc = $this->discover();
        $state    = bin2hex(random_bytes(16));
        $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $challenge= rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $_SESSION['oidc_state']    = $state;
        $_SESSION['oidc_verifier'] = $verifier;

        $params = http_build_query(array_merge([
            'response_type'         => 'code',
            'client_id'             => $this->cfg['client_id'],
            'redirect_uri'          => $this->cfg['redirect_uri'],
            'scope'                 => $this->cfg['scopes'],
            'state'                 => $state,
            'code_challenge'        => $challenge,
            'code_challenge_method' => 'S256',
        ], $extra));
        header('Location: ' . $oidc['authorization_endpoint'] . '?' . $params, true, 302);
    }

    public function callback(): array
    {
        $oidc = $this->discover();
        if (!isset($_GET['code'], $_GET['state']) || $_GET['state'] !== ($_SESSION['oidc_state'] ?? '')) {
            http_response_code(400); exit('Invalid OIDC state');
        }
        $tok = $this->http_post_form($oidc['token_endpoint'], [
            'grant_type'    => 'authorization_code',
            'code'          => $_GET['code'],
            'redirect_uri'  => $this->cfg['redirect_uri'],
            'client_id'     => $this->cfg['client_id'],
            'client_secret' => $this->cfg['client_secret'],
            'code_verifier' => $_SESSION['oidc_verifier'] ?? '',
        ]);
        $tok = json_decode($tok, true) ?: [];
        if (empty($tok['access_token'])) { http_response_code(401); exit('Token exchange failed'); }

        $userJson = $this->http_get($oidc['userinfo_endpoint'], ['Authorization: Bearer ' . $tok['access_token']]);
        $user = json_decode($userJson, true) ?: [];
        if (empty($user['sub'])) { http_response_code(401); exit('Userinfo failed'); }

        $_SESSION['wheelder_user'] = [
            'sub'    => $user['sub'],          // stable Keycloak user id -> use as app_user_id
            'email'  => $user['email']  ?? '',
            'name'   => $user['name']   ?? '',
            'tokens' => $tok,
            'login_at' => time(),
        ];
        unset($_SESSION['oidc_state'], $_SESSION['oidc_verifier']);

        // Decide where to send them based on subscription
        $sub = $this->fetch_subscription($user['sub'], $user['email'] ?? '');
        if ($this->is_active($sub)) {
            header('Location: ' . $this->cfg['after_login'], true, 302);
        } else {
            $this->redirect_to_checkout($user['sub'], $user['email'] ?? '', $this->cfg['default_plan']);
        }
        return $_SESSION['wheelder_user'];
    }

    public function logout(): void
    {
        $oidc = $this->discover();
        $idToken = $_SESSION['wheelder_user']['tokens']['id_token'] ?? '';
        $_SESSION = [];
        session_destroy();
        $end = $oidc['end_session_endpoint'] ?? '';
        if ($end) {
            header('Location: ' . $end . '?' . http_build_query([
                'id_token_hint' => $idToken,
                'post_logout_redirect_uri' => $this->cfg['redirect_uri'],
            ]), true, 302);
        } else {
            header('Location: /', true, 302);
        }
    }

    public function user(): ?array { return $_SESSION['wheelder_user'] ?? null; }

    /** Override the plan a non-subscribed user gets sent to (e.g. from a /pricing click). */
    public function set_default_plan(string $plan): void
    {
        $this->cfg['default_plan'] = $plan;
    }

    public function require_login(): array
    {
        $u = $this->user();
        if (!$u) { $this->login(); exit; }
        return $u;
    }

    public function require_active_subscription(): array
    {
        $u = $this->require_login();
        $sub = $this->fetch_subscription($u['sub'], $u['email']);
        if (!$this->is_active($sub)) {
            $this->redirect_to_checkout($u['sub'], $u['email'], $this->cfg['default_plan']);
            exit;
        }
        return array_merge($u, ['subscription' => $sub, 'plan' => $sub['plan_slug'] ?? null]);
    }

    /* ---------------- Plan-based access control ---------------- */

    /**
     * Plan tier ranking. Higher number = more access. Override via cfg['plan_tiers'].
     * Anything not listed ranks 0 (lowest).
     */
    private function plan_tiers(): array
    {
        return $this->cfg['plan_tiers'] ?? [
            'free'       => 0,
            'basic'      => 1,
            'starter'    => 1,
            'pro'        => 2,
            'plus'       => 2,
            'team'       => 3,
            'business'   => 3,
            'enterprise' => 4,
        ];
    }

    /** Return current plan slug for the logged-in user (or null). */
    public function current_plan(): ?string
    {
        $u = $this->user();
        if (!$u) return null;
        $sub = $this->fetch_subscription($u['sub'], $u['email']);
        if (!$this->is_active($sub)) return null;
        return $sub['plan_slug'] ?? null;
    }

    /** True if the user is on one of the listed plans (e.g. has_plan('pro','team')). */
    public function has_plan(string ...$plans): bool
    {
        $cur = $this->current_plan();
        return $cur !== null && in_array($cur, $plans, true);
    }

    /** True if the user's plan tier >= the required plan's tier. */
    public function has_at_least(string $minPlan): bool
    {
        $cur = $this->current_plan();
        if ($cur === null) return false;
        $tiers = $this->plan_tiers_dynamic();
        return ($tiers[$cur] ?? 0) >= ($tiers[$minPlan] ?? 0);
    }

    /**
     * Gate a page by an exact plan whitelist.
     * If the user is not subscribed -> /checkout/<product>/<first plan in list>.
     * If subscribed to a different plan -> 403 with an upgrade link.
     */
    public function require_plan(string ...$allowedPlans): array
    {
        $u = $this->require_login();
        $sub = $this->fetch_subscription($u['sub'], $u['email']);
        if (!$this->is_active($sub)) {
            $this->redirect_to_checkout($u['sub'], $u['email'], $allowedPlans[0] ?? $this->cfg['default_plan']);
            exit;
        }
        $cur = $sub['plan_slug'] ?? '';
        if (!in_array($cur, $allowedPlans, true)) {
            $this->upgrade_response($u, $cur, $allowedPlans[0] ?? $this->cfg['default_plan']);
            exit;
        }
        return array_merge($u, ['subscription' => $sub, 'plan' => $cur]);
    }

    /**
     * Gate a page by minimum tier (e.g. require_at_least('pro') lets pro/team/enterprise in).
     */
    public function require_at_least(string $minPlan): array
    {
        $u = $this->require_login();
        $sub = $this->fetch_subscription($u['sub'], $u['email']);
        if (!$this->is_active($sub)) {
            $this->redirect_to_checkout($u['sub'], $u['email'], $minPlan);
            exit;
        }
        $tiers = $this->plan_tiers_dynamic();
        $cur = $sub['plan_slug'] ?? '';
        if (($tiers[$cur] ?? 0) < ($tiers[$minPlan] ?? 0)) {
            $this->upgrade_response($u, $cur, $minPlan);
            exit;
        }
        return array_merge($u, ['subscription' => $sub, 'plan' => $cur]);
    }

    /**
     * Render a ready-made pricing page for THIS app from the POS catalog.
     * Each plan card links to /auth/signup?plan=<slug> (so signup -> checkout for that plan).
     * Override the entire UI by passing a callable in cfg['pricing_renderer'].
     */
    public function render_pricing(array $opts = []): void
    {
        $plans = $this->fetch_plans();
        if (is_callable($this->cfg['pricing_renderer'] ?? null)) {
            ($this->cfg['pricing_renderer'])($plans, $opts);
            return;
        }
        $title = $opts['title'] ?? ($this->cfg['app_name'] ?? 'Pricing');
        $h = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><meta charset="utf-8"><title>' . $h($title) . '</title>'
           . '<style>'
           . 'body{margin:0;font:16px/1.5 system-ui,sans-serif;background:#0b1020;color:#eef2ff;padding:48px 16px}'
           . '.wrap{max-width:1100px;margin:0 auto}h1{text-align:center;margin:0 0 8px;font-size:32px}'
           . '.sub{text-align:center;color:#94a3b8;margin:0 0 32px}'
           . '.grid{display:grid;gap:20px;grid-template-columns:repeat(auto-fit,minmax(260px,1fr))}'
           . '.card{background:#11172e;border:1px solid rgba(255,255,255,.08);border-radius:16px;padding:28px;display:flex;flex-direction:column}'
           . '.card.feat{border-color:#22d3ee;box-shadow:0 0 0 1px #22d3ee}'
           . '.badge{align-self:flex-start;background:#22d3ee;color:#0b1020;font-size:11px;font-weight:700;padding:4px 8px;border-radius:6px;margin-bottom:8px}'
           . '.name{font-size:20px;font-weight:600;margin:0}'
           . '.tag{color:#94a3b8;margin:4px 0 16px;font-size:14px;min-height:20px}'
           . '.price{font-size:36px;font-weight:700;margin:0}'
           . '.price small{font-size:14px;color:#94a3b8;font-weight:400}'
           . 'ul{list-style:none;padding:0;margin:20px 0;flex:1}'
           . 'li{padding:6px 0;font-size:14px;color:#cbd5e1}li::before{content:"\2713";color:#22d3ee;margin-right:8px}'
           . '.btn{text-align:center;padding:12px;border-radius:10px;font-weight:600;text-decoration:none;background:linear-gradient(135deg,#6366f1,#22d3ee);color:#0b1020}'
           . '</style><div class="wrap"><h1>' . $h($title) . '</h1>'
           . '<p class="sub">Choose a plan. Cancel anytime.</p><div class="grid">';
        foreach ($plans as $p) {
            $price = number_format(((int)($p['price_cents'] ?? 0)) / 100, 2);
            $cur   = strtoupper($p['currency'] ?? 'USD');
            $intv  = $p['interval'] ?? 'month';
            $feat  = $p['is_featured'] ? ' feat' : '';
            echo '<div class="card' . $feat . '">';
            if (!empty($p['badge_text']))    echo '<span class="badge">' . $h($p['badge_text']) . '</span>';
            echo '<p class="name">' . $h($p['name'] ?? $p['slug']) . '</p>';
            echo '<p class="tag">'  . $h($p['tagline'] ?? '') . '</p>';
            echo '<p class="price">' . $h($cur) . ' ' . $h($price) . ' <small>/ ' . $h($intv) . '</small></p>';
            echo '<ul>';
            foreach (($p['features'] ?? []) as $f) echo '<li>' . $h($f) . '</li>';
            echo '</ul>';
            $cta = $p['price_cents'] > 0 ? 'Subscribe' : 'Get started';
            echo '<a class="btn" href="/auth/signup?plan=' . $h($p['slug']) . '">' . $cta . '</a>';
            echo '</div>';
        }
        echo '</div></div>';
    }

    /** Renders a 403 + upgrade CTA. Override by setting cfg['upgrade_handler'] = callable($user,$cur,$target). */
    private function upgrade_response(array $user, string $current, string $target): void
    {
        if (is_callable($this->cfg['upgrade_handler'] ?? null)) {
            ($this->cfg['upgrade_handler'])($user, $current, $target);
            return;
        }
        $upgradeUrl = rtrim($this->cfg['commerce'], '/')
            . '/checkout/' . rawurlencode($this->cfg['product_key']) . '/' . rawurlencode($target)
            . '?' . http_build_query(['app_user_id' => $user['sub'], 'email' => $user['email'] ?? '']);
        http_response_code(403);
        header('Content-Type: text/html; charset=utf-8');
        $h = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        echo '<!doctype html><meta charset="utf-8"><title>Upgrade required</title>'
           . '<div style="font:16px system-ui;max-width:480px;margin:80px auto;padding:32px;'
           . 'background:#11172e;color:#eef2ff;border-radius:16px;text-align:center">'
           . '<h1 style="margin:0 0 8px">Upgrade required</h1>'
           . '<p style="color:#94a3b8">This feature is on the <b>' . $h($target) . '</b> plan. '
           . 'You are currently on <b>' . $h($current ?: 'no') . '</b> plan.</p>'
           . '<p><a href="' . $h($upgradeUrl) . '" style="display:inline-block;padding:12px 20px;'
           . 'background:linear-gradient(135deg,#6366f1,#22d3ee);color:#0b1020;border-radius:10px;'
           . 'text-decoration:none;font-weight:600">Upgrade to ' . $h($target) . '</a></p>'
           . '<p style="margin-top:24px"><a href="/" style="color:#94a3b8">&larr; back</a></p>'
           . '</div>';
    }

    /* ---------------- POS bridge ---------------- */

    /**
     * Fetch the live plan catalog for this product from POS (cached in-process).
     * Returns array of plans with: slug, name, tagline, price_cents, currency, interval,
     * features (array), badge_text, is_featured, sort_order.
     */
    public function fetch_plans(bool $force = false): array
    {
        static $cache = [];
        $key = $this->cfg['product_key'];
        if (!$force && isset($cache[$key])) return $cache[$key];

        $url = rtrim($this->cfg['commerce'], '/')
             . '/api/products/' . rawurlencode($key);
        try {
            $body = $this->http_get($url, ['X-Wheelder-Pos-Key: ' . $this->cfg['pos_api_key']]);
            $data = json_decode($body, true) ?: [];
            $plans = $data['plans'] ?? [];
            foreach ($plans as &$p) {
                if (isset($p['features']) && is_string($p['features'])) {
                    $decoded = json_decode($p['features'], true);
                    $p['features'] = is_array($decoded) ? $decoded : [];
                }
            }
            return $cache[$key] = $plans;
        } catch (\Throwable $e) {
            error_log('WheelderAuthClient fetch_plans failed: ' . $e->getMessage());
            return $cache[$key] = [];
        }
    }

    /**
     * Tier ranking sourced from POS (sort_order ASC = lower tier).
     * Falls back to cfg['plan_tiers'] or the built-in defaults if POS is unreachable.
     */
    private function plan_tiers_dynamic(): array
    {
        $plans = $this->fetch_plans();
        if (!$plans) return $this->plan_tiers();    // fallback to static
        $tiers = [];
        foreach ($plans as $i => $p) {
            // sort_order is the source of truth; index is a stable backup.
            $tiers[$p['slug']] = (int)($p['sort_order'] ?? $i);
        }
        return $tiers;
    }

    public function fetch_subscription(string $appUserId, string $email = ''): ?array
    {
        $url = rtrim($this->cfg['commerce'], '/')
             . '/api/subscription?'
             . http_build_query([
                 'product_key' => $this->cfg['product_key'],
                 'app_user_id' => $appUserId,
                 'email'       => $email,
             ]);
        try {
            $body = $this->http_get($url, ['X-Wheelder-Pos-Key: ' . $this->cfg['pos_api_key']]);
            $data = json_decode($body, true) ?: [];
            return $data['subscription'] ?? null;
        } catch (\Throwable $e) {
            error_log('WheelderAuthClient subscription fetch failed: ' . $e->getMessage());
            return null;
        }
    }

    public function is_active(?array $sub): bool
    {
        if (!$sub) return false;
        return in_array(($sub['status'] ?? ''), ['active','trialing'], true);
    }

    public function redirect_to_checkout(string $appUserId, string $email, string $plan): void
    {
        $url = rtrim($this->cfg['commerce'], '/')
             . '/checkout/' . rawurlencode($this->cfg['product_key']) . '/' . rawurlencode($plan)
             . '?' . http_build_query(['app_user_id' => $appUserId, 'email' => $email]);
        header('Location: ' . $url, true, 302);
    }

    /* ---------------- HTTP helpers ---------------- */

    private function http_get(string $url, array $headers = []): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        $out = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($out === false || $code >= 400) {
            throw new \RuntimeException("GET $url failed (http=$code) $err " . substr((string)$out, 0, 200));
        }
        return (string)$out;
    }

    private function http_post_form(string $url, array $form): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($form),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        ]);
        $out = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($out === false || $code >= 400) {
            throw new \RuntimeException("POST $url failed (http=$code) $err " . substr((string)$out, 0, 200));
        }
        return (string)$out;
    }
}
