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
        'SELECT accounts.id, accounts.display_name, roles.name AS role
         FROM accounts
         INNER JOIN roles ON roles.id = accounts.role_id
         WHERE accounts.id = :id'
    );
    $accountQuery->execute(['id' => $_SESSION['account_id']]);
    $profile = $accountQuery->fetch();

    if (!$profile) {
        http_response_code(404);
        $error = 'حساب کاربری شما پیدا نشد. دوباره وارد شوید.';
    } else {
        $profileAvailable = true;
        $displayName = (string) ($profile['display_name'] ?? '');
    }
} catch (PDOException $exception) {
    error_log($exception->getMessage());
    http_response_code(503);
    $error = 'نمایه شما در دسترس نیست. تنظیمات پایگاه داده را بررسی و دوباره تلاش کنید.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error === '') {
    if (!is_valid_csrf_token($_POST['csrf_token'] ?? null)) {
        $error = 'نشست شما منقضی شده است. صفحه را تازه‌سازی کنید و دوباره تلاش کنید.';
    } else {
        $submittedName = $_POST['display_name'] ?? '';
        $submittedName = is_string($submittedName) ? $submittedName : '';
        $normalizedName = normalize_display_name($submittedName);

        if ($normalizedName === null) {
            $error = 'نام نمایشی باید بین ۱ تا ۸۰ نویسه باشد.';
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

                $notice = 'نام نمایشی شما ذخیره شد.';
            } catch (PDOException $exception) {
                error_log($exception->getMessage());
                $error = 'ذخیره نام نمایشی ممکن نشد. لطفاً دوباره تلاش کنید.';
            }
        }
    }
}

$returnToInvite = isset($_GET['next']) && $_GET['next'] === 'invite';
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#315c4b">
    <title>نام نمایشی | باهم بخوانیم</title>
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="icon" href="assets/icons/icon-192.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/css/app.css">
    <script src="assets/js/app.js" defer></script>
</head>
<body>
    <main class="welcome auth-card">
        <?php if (!$returnToInvite): ?>
            <a class="back-link" href="index.php">&rarr; بازگشت به گروه‌ها</a>
        <?php endif; ?>
        <p class="eyebrow">نمایه شما</p>
        <h1>نام نمایشی</h1>
        <p class="intro">نام شما به‌جای شماره موبایل، برای دیگر اعضای گروه نمایش داده می‌شود.</p>

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
                <label for="display_name">نام نمایشی</label>
                <input
                    id="display_name"
                    name="display_name"
                    type="text"
                    maxlength="80"
                    autocomplete="nickname"
                    value="<?= escape_html($displayName) ?>"
                    placeholder="دوست دارید شما را چه صدا کنیم؟"
                    required
                >
                <p class="field-hint">نامی بین ۱ تا ۸۰ نویسه انتخاب کنید. هر زمان می‌توانید آن را تغییر دهید.</p>
                <button class="button" type="submit">ذخیره نام نمایشی</button>
            </form>
            <?php if ($profile['role'] === 'Admin'): ?>
                <nav class="profile-admin-actions" aria-label="ابزارهای مدیریت">
                    <a class="admin-link" href="admin/chapters.php">مدیریت فصل‌های کتاب <span aria-hidden="true">&larr;</span></a>
                    <a class="admin-link" href="admin/groups.php">مدیریت همه گروه‌ها <span aria-hidden="true">&larr;</span></a>
                </nav>
            <?php endif; ?>
        <?php elseif ($error === 'حساب کاربری شما پیدا نشد. دوباره وارد شوید.'): ?>
            <p><a class="admin-link" href="login.php">ورود دوباره</a></p>
        <?php endif; ?>
        <form class="profile-logout-form" action="logout.php" method="post">
            <input type="hidden" name="csrf_token" value="<?= escape_html(csrf_token()) ?>">
            <button class="button button-secondary" type="submit">خروج از حساب</button>
        </form>
    </main>
</body>
</html>
