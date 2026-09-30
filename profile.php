<?php
require_once __DIR__ . '/includes/groups.php';

if (empty($_SESSION['account_id'])) {
    header('Location: login.php');
    exit;
}

$error = '';
$notice = '';
$displayName = '';
$profileAvailable = false;

try {
    $connection = database();
    ensure_group_schema($connection);
    $accountQuery = $connection->prepare(
        'SELECT id, display_name FROM accounts WHERE id = :id'
    );
    $accountQuery->execute(['id' => $_SESSION['account_id']]);
    $profile = $accountQuery->fetch();

    if (!$profile) {
        http_response_code(404);
        $error = 'Your account could not be found. Please sign in again.';
    } else {
        $profileAvailable = true;
        $displayName = (string) ($profile['display_name'] ?? '');
    }
} catch (PDOException $exception) {
    error_log($exception->getMessage());
    http_response_code(503);
    $error = 'Your profile is unavailable. Check the database configuration and try again.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error === '') {
    if (!is_valid_csrf_token($_POST['csrf_token'] ?? null)) {
        $error = 'Your session expired. Refresh the page and try again.';
    } else {
        $submittedName = $_POST['display_name'] ?? '';
        $submittedName = is_string($submittedName) ? $submittedName : '';
        $normalizedName = normalize_display_name($submittedName);

        if ($normalizedName === null) {
            $error = 'Enter a display name between 1 and 80 characters.';
            $displayName = trim($submittedName);
        } else {
            try {
                $saveName = $connection->prepare(
                    'UPDATE accounts SET display_name = :display_name WHERE id = :id'
                );
                $saveName->execute([
                    'display_name' => $normalizedName,
                    'id' => $_SESSION['account_id'],
                ]);
                $displayName = $normalizedName;

                $next = $_POST['next'] ?? '';
                $pendingInviteCode = normalize_invite_code(
                    (string) ($_SESSION['pending_invite_code'] ?? '')
                );
                if ($next === 'invite' && $pendingInviteCode !== null) {
                    header('Location: join.php?code=' . rawurlencode($pendingInviteCode));
                    exit;
                }

                $notice = 'Your display name has been saved.';
            } catch (PDOException $exception) {
                error_log($exception->getMessage());
                $error = 'Could not save your display name. Please try again.';
            }
        }
    }
}

$returnToInvite = isset($_GET['next']) && $_GET['next'] === 'invite';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#315c4b">
    <title>Display name | Read Together</title>
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="icon" href="assets/icons/icon-192.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/css/app.css">
    <script src="assets/js/app.js" defer></script>
</head>
<body>
    <main class="welcome auth-card">
        <?php if (!$returnToInvite): ?>
            <a class="back-link" href="index.php">&larr; Back to your groups</a>
        <?php endif; ?>
        <p class="eyebrow">Your profile</p>
        <h1>Display name</h1>
        <p class="intro">Your name is shown to other members in your groups instead of your phone number.</p>

        <?php if ($error !== ''): ?>
            <p class="message message-error" role="alert"><?= escape_html($error) ?></p>
        <?php elseif ($notice !== ''): ?>
            <p class="message message-success" role="status"><?= escape_html($notice) ?></p>
        <?php endif; ?>

        <?php if ($profileAvailable): ?>
            <form class="auth-form" method="post" action="profile.php<?= $returnToInvite ? '?next=invite' : '' ?>">
                <input type="hidden" name="csrf_token" value="<?= escape_html(csrf_token()) ?>">
                <?php if ($returnToInvite): ?>
                    <input type="hidden" name="next" value="invite">
                <?php endif; ?>
                <label for="display_name">Display name</label>
                <input
                    id="display_name"
                    name="display_name"
                    type="text"
                    maxlength="80"
                    autocomplete="nickname"
                    value="<?= escape_html($displayName) ?>"
                    placeholder="How should we call you?"
                    required
                >
                <p class="field-hint">Use 1 to 80 characters. You can change this any time.</p>
                <button class="button" type="submit">Save display name</button>
            </form>
        <?php elseif ($error === 'Your account could not be found. Please sign in again.'): ?>
            <p><a class="admin-link" href="login.php">Sign in again</a></p>
        <?php endif; ?>
    </main>
</body>
</html>
