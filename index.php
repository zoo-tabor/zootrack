<?php
/**
 * ZooTrack entry point.
 *
 * Gates the single-page app behind the VetApp login, then serves the unchanged
 * SPA markup (app.html). Intentionally NO VetApp header/footer / layout is added —
 * ZooTrack keeps its own look exactly as before.
 */
require __DIR__ . '/auth_check.php';
zt_require_login_page();

header('Content-Type: text/html; charset=utf-8');
readfile(__DIR__ . '/app.html');
