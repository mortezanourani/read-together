<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/schema.php';

function normalize_invite_code(string $code): ?string
{
    $code = strtoupper(trim($code));

    return preg_match('/^[A-F0-9]{12}$/D', $code) ? $code : null;
}

function group_invitation_url(string $code): string
{
    return rtrim(APP_URL, '/') . '/join.php?code=' . rawurlencode($code);
}
