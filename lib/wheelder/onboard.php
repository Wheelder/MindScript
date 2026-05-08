<?php
/**
 * Wheelder ONE-FILE ONBOARDING.
 *
 * Drop this file (and WheelderAuthClient.php) anywhere in your app and add ONE line
 * to your front controller (index.php / app.php) BEFORE any routing:
 *
 *   require __DIR__ . '/path/to/onboard.php';
 *
 * It auto-handles:
 *   GET  /auth/login         -> redirect to auth.wheelder.com (login)
 *   GET  /auth/signup        -> redirect to auth.wheelder.com (registration form)
 *   GET  /auth/callback      -> exchange code, save session, send to checkout if no sub
 *   GET  /auth/logout        -> clear session + Keycloak SSO logout
 *   GET  /auth/account       -> redirect to https://auth.wheelder.com/realms/wheelder/account/
 *   GET  /onboard            -> renders the built-in Sign-up / Log-in landing page
 *
 * Configure with environment variables (in your .env):
 *   WHEELDER_PRODUCT_KEY      e.g. circular
 *   WHEELDER_DEFAULT_PLAN     e.g. pro          (the plan paid users land on)
 *   WHEELDER_FREE_PLAN        e.g. free         (optional; if set, free users skip checkout)
 *   WHEELDER_OIDC_CLIENT_ID   from Keycloak admin
 *   WHEELDER_OIDC_CLIENT_SECRET
 *   WHEELDER_REDIRECT_URI     e.g. https://circular.wheelder.com/auth/callback
 *   POS_API_KEY               shared secret to query subscriptions from commerce.wheelder.com
 *   WHEELDER_APP_NAME         pretty name shown on the landing page (defaults to product_key)
 *   WHEELDER_AFTER_LOGIN      where to send authenticated subscribers (default: /)
 */

declare(strict_types=1);

require_once __DIR__ . '/WheelderAuthClient.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start([
        'cookie_secure'   => !empty($_SERVER['HTTPS']),
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
    ]);
}

// ---- env helpers ---------------------------------------------------------
$env = static function (string $k, ?string $default = null): ?string {
    $v = getenv($k);
    if ($v === false || $v === '') {
        return $_ENV[$k] ?? $_SERVER[$k] ?? $default;
    }
    return $v;
};

$productKey   = $env('WHEELDER_PRODUCT_KEY');
$clientId     = $env('WHEELDER_OIDC_CLIENT_ID');
$clientSecret = $env('WHEELDER_OIDC_CLIENT_SECRET');
$redirectUri  = $env('WHEELDER_REDIRECT_URI');
$posKey       = $env('POS_API_KEY');
$defaultPlan  = $env('WHEELDER_DEFAULT_PLAN', 'pro');
$freePlan     = $env('WHEELDER_FREE_PLAN');           // optional
$appName      = $env('WHEELDER_APP_NAME', $productKey ?: 'Wheelder');
$afterLogin   = $env('WHEELDER_AFTER_LOGIN', '/');

if (!$productKey || !$clientId || !$clientSecret || !$redirectUri || !$posKey) {
    // Don't blow up the whole app if onboarding is misconfigured -- only blow up on /auth/* and /onboard.
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
    if (str_starts_with($path, '/auth/') || $path === '/onboard') {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Wheelder onboarding is not configured. Missing one of: ".
             "WHEELDER_PRODUCT_KEY, WHEELDER_OIDC_CLIENT_ID, WHEELDER_OIDC_CLIENT_SECRET, ".
             "WHEELDER_REDIRECT_URI, POS_API_KEY";
        exit;
    }
    return; // silently no-op so the app can still serve other pages
}

$auth = new WheelderAuthClient([
    'product_key'   => $productKey,
    'default_plan'  => $defaultPlan,
    'client_id'     => $clientId,
    'client_secret' => $clientSecret,
    'redirect_uri'  => $redirectUri,
    'pos_api_key'   => $posKey,
    'after_login'   => $afterLogin,
    'app_name'      => $appName,
]);
unset($freePlan); // reserved for future per-plan routing logic

// If the visitor clicks a specific plan card on /pricing, remember which one
// so the post-login redirect sends them straight to that plan's checkout.
if (!empty($_GET['plan'])) {
    $_SESSION['wheelder_intended_plan'] = preg_replace('/[^a-z0-9_-]/i', '', $_GET['plan']);
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';

switch ($path) {
    case '/auth/login':
        $auth->login();
        exit;

    case '/auth/signup':
        // Force Keycloak's registration screen (kc_action=register)
        $auth->login(['kc_action' => 'register']);
        exit;

    case '/auth/callback':
        // callback() already routes the user: -> after_login if active, else -> checkout/{product}/{default_plan}.
        // If they came from /pricing with ?plan=, override default_plan for this hop.
        if (!empty($_SESSION['wheelder_intended_plan'])) {
            $auth->set_default_plan($_SESSION['wheelder_intended_plan']);
            unset($_SESSION['wheelder_intended_plan']);
        }
        $auth->callback();
        exit;

    case '/pricing':
        $auth->render_pricing();
        exit;

    case '/auth/logout':
        $auth->logout();   // clears session + redirects to Keycloak end_session
        exit;

    case '/auth/account':
        header('Location: https://auth.wheelder.com/realms/wheelder/account/');
        exit;

    case '/onboard':
        // Built-in landing page (sign up / log in / pricing CTA)
        $h = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        header('Content-Type: text/html; charset=utf-8');
        ?><!doctype html>
<html lang="en"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Welcome to <?= $h($appName) ?></title>
<style>
  :root{--bg:#0b1020;--card:#11172e;--ink:#eef2ff;--mute:#94a3b8;--brand:#6366f1;--brand2:#22d3ee}
  *{box-sizing:border-box}
  body{margin:0;min-height:100vh;font:16px/1.5 system-ui,Segoe UI,Roboto,Helvetica,Arial,sans-serif;
       background:radial-gradient(1200px 800px at 80% -10%,#1e1b4b 0,transparent 60%),
                  radial-gradient(900px 600px at -10% 110%,#0e7490 0,transparent 50%),var(--bg);
       color:var(--ink);display:flex;align-items:center;justify-content:center;padding:24px}
  .card{max-width:520px;width:100%;background:rgba(17,23,46,.85);backdrop-filter:blur(12px);
        border:1px solid rgba(255,255,255,.08);border-radius:20px;padding:36px;
        box-shadow:0 30px 60px -20px rgba(0,0,0,.6)}
  h1{margin:0 0 6px;font-size:28px;letter-spacing:-.02em}
  p.lead{margin:0 0 28px;color:var(--mute)}
  .row{display:grid;gap:12px}
  a.btn{display:flex;align-items:center;justify-content:center;gap:10px;padding:14px 18px;
        border-radius:12px;font-weight:600;text-decoration:none;transition:.15s transform,.15s opacity}
  a.btn:hover{transform:translateY(-1px)}
  .primary{background:linear-gradient(135deg,var(--brand),var(--brand2));color:#0b1020}
  .ghost{background:transparent;color:var(--ink);border:1px solid rgba(255,255,255,.15)}
  .divider{display:flex;align-items:center;gap:10px;color:var(--mute);margin:18px 0;font-size:13px}
  .divider::before,.divider::after{content:"";flex:1;height:1px;background:rgba(255,255,255,.1)}
  ul.feat{list-style:none;padding:0;margin:0 0 24px;color:var(--mute);font-size:14px}
  ul.feat li{padding:6px 0;display:flex;gap:8px}
  ul.feat li::before{content:"✓";color:var(--brand2);font-weight:700}
  .foot{margin-top:24px;color:var(--mute);font-size:12px;text-align:center}
  .foot a{color:var(--mute)}
</style>
</head><body>
<main class="card">
  <h1>Welcome to <?= $h($appName) ?></h1>
  <p class="lead">Create an account or sign in to continue. One Wheelder ID works across every app.</p>
  <ul class="feat">
    <li>Sign up with email or single sign-on</li>
    <li>One subscription, all your devices</li>
    <li>Manage billing &amp; profile from one place</li>
  </ul>
  <div class="row">
    <a class="btn primary" href="/auth/signup">Create account</a>
    <a class="btn ghost"   href="/auth/login">I already have an account</a>
  </div>
  <div class="divider">secure sign-in by auth.wheelder.com</div>
  <div class="foot">
    Need help? <a href="https://auth.wheelder.com/realms/wheelder/account/">Manage your Wheelder ID</a>
  </div>
</main>
</body></html><?php
        exit;
}

// Not an /auth/* or /onboard request -- expose $auth globally for the host app.
$GLOBALS['wheelder_auth'] = $auth;
