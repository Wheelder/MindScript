<?php
declare(strict_types=1);
// Wheelder onboarding (auth + subscription gates). Routes /onboard /pricing /auth/*
require __DIR__ . '/lib/wheelder/onboard.php';

// Minimal landing page until the full app is wired in.
header('Content-Type: text/html; charset=utf-8');
?><!doctype html><html><head><meta charset="utf-8"><title>MindScript</title>
<style>body{font-family:system-ui;background:#0b0d10;color:#e6e9ef;display:flex;min-height:100vh;align-items:center;justify-content:center}
.c{max-width:520px;text-align:center}.b{display:inline-block;margin:6px;padding:10px 18px;border-radius:8px;background:#3b82f6;color:#fff;text-decoration:none}
.b.alt{background:transparent;border:1px solid #3b82f6;color:#bcd}</style></head>
<body><div class="c"><h1>MindScript</h1>
<p>Sign up or sign in to get started.</p>
<a class="b" href="/auth/signup">Create account</a>
<a class="b alt" href="/auth/login">Sign in</a>
<p><a href="/pricing" style="color:#9ad">View pricing</a></p>
</div></body></html>
