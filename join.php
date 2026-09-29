<?php
require_once __DIR__ . '/includes/groups.php';

$submittedCode = $_GET['code'] ?? $_POST['code'] ?? '';
$code = is_string($submittedCode) ? normalize_invite_code($submittedCode) : null;
if ($code === null) {
    http_response_code(400);
    $codeError = 'This invitation code is not valid. Check it and try again.';
} else {
    $codeError = '';
}

if (empty($_SESSION['account_id'])) {
    if ($code === null) {
        http_response_code(400);
        $error = 'This invitation code is not valid. Check it and try again.';
    } else {
        $_SESSION['pending_invite_code'] = $code;
        header('Location: login.php');
        exit;
    }
}

$error = $codeError;
$group = false;

if ($error === '') {
    try {
        ensure_group_schema(database());
        $findGroup = database()->prepare(
            'SELECT id, name FROM `groups` WHERE invite_code = :invite_code'
        );
        $findGroup->execute(['invite_code' => $code]);
        $group = $findGroup->fetch();

        if (!$group) {
            http_response_code(404);
            $error = 'This invitation code does not match a group.';
        }
    } catch (PDOException $exception) {
        error_log($exception->getMessage());
        http_response_code(503);
        $error = 'Groups are unavailable. Check the database configuration and permissions.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error === '' && $group) {
    if (!is_valid_csrf_token($_POST['csrf_token'] ?? null)) {
        $error = 'Your session expired. Refresh the page and try again.';
    } else {
        try {
            $membership = database()->prepare(
                'INSERT IGNORE INTO group_members (group_id, account_id)
                 VALUES (:group_id, :account_id)'
            );
            $membership->execute([
                'group_id' => $group['id'],
                'account_id' => $_SESSION['account_id'],
            ]);
            unset($_SESSION['pending_invite_code']);
            header('Location: group.php?id=' . (int) $group['id'] . '&joined=1');
            exit;
        } catch (PDOException $exception) {
            error_log($exception->getMessage());
            $error = 'Could not join this group. Please try again.';
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#315c4b">
    <title>Join a group | Read Together</title>
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="icon" href="assets/icons/icon-192.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/css/app.css">
    <script src="assets/js/app.js" defer></script>
</head>
<body>
    <main class="welcome auth-card">
        <a class="back-link" href="index.php">&larr; Back to your groups</a>
        <p class="eyebrow">An invitation</p>
        <h1>Join a group</h1>

        <?php if ($error !== ''): ?>
            <p class="message message-error" role="alert"><?= escape_html($error) ?></p>
        <?php elseif ($group): ?>
            <p class="intro">You've been invited to join <strong><?= escape_html($group['name']) ?></strong>.</p>
            <form class="auth-form" method="post" action="join.php">
                <input type="hidden" name="csrf_token" value="<?= escape_html(csrf_token()) ?>">
                <input type="hidden" name="code" value="<?= escape_html($code) ?>">
                <button class="button" type="submit">Join this group</button>
            </form>
        <?php endif; ?>
    </main>
</body>
</html>
