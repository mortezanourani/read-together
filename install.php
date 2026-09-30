<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/schema.php';

$error = '';
$notice = '';
$installed = false;
$connection = null;

try {
    $connection = database();
    if (schema_is_installed($connection)) {
        $adminCheck = $connection->query(
            "SELECT COUNT(*)
             FROM accounts
             INNER JOIN roles ON roles.id = accounts.role_id
             WHERE roles.name = 'Admin'"
        );
        $installed = (int) $adminCheck->fetchColumn() > 0;
    }
} catch (PDOException $exception) {
    error_log($exception->getMessage());
    $error = 'پایگاه داده در دسترس نیست. تنظیمات فایل config.php را بررسی کنید.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$installed && $error === '') {
    if (!is_valid_csrf_token($_POST['csrf_token'] ?? null)) {
        $error = 'نشست شما منقضی شده است. صفحه را تازه‌سازی کنید و دوباره تلاش کنید.';
    } elseif (INSTALLATION_KEY === '') {
        $error = 'پیش از نصب، یک INSTALLATION_KEY طولانی و یکتا در فایل config.php تنظیم کنید.';
    } elseif (!is_string($_POST['installation_key'] ?? null)
        || !hash_equals(INSTALLATION_KEY, $_POST['installation_key'])) {
        $error = 'کلید نصب نادرست است.';
    } else {
        $submittedPhone = $_POST['phone'] ?? '';
        $phone = is_string($submittedPhone)
            ? normalize_phone_number($submittedPhone)
            : null;

        if ($phone === null) {
            $error = 'شماره موبایل ۱۱ رقمی معتبری که با 09 شروع شود وارد کنید.';
        } else {
            try {
                install_schema($connection);
                $connection->beginTransaction();

                $adminRole = $connection->query(
                    "SELECT id FROM roles WHERE name = 'Admin' FOR UPDATE"
                )->fetchColumn();
                $adminCheck = $connection->query(
                    "SELECT COUNT(*)
                     FROM accounts
                     INNER JOIN roles ON roles.id = accounts.role_id
                     WHERE roles.name = 'Admin'"
                );

                if ((int) $adminCheck->fetchColumn() > 0) {
                    $connection->rollBack();
                    $installed = true;
                } else {
                    $createAdmin = $connection->prepare(
                        'INSERT INTO accounts (phone, role_id) VALUES (:phone, :role_id)
                         ON DUPLICATE KEY UPDATE role_id = VALUES(role_id)'
                    );
                    $createAdmin->execute([
                        'phone' => $phone,
                        'role_id' => $adminRole,
                    ]);
                    $connection->commit();
                    $installed = true;
                    $notice = 'حساب مدیر ساخته شد. فایل install.php را از میزبان حذف کنید و سپس با این شماره موبایل وارد شوید.';
                }
            } catch (Throwable $exception) {
                if ($connection->inTransaction()) {
                    $connection->rollBack();
                }
                error_log($exception->getMessage());
                $error = 'نصب انجام نشد. تنظیمات پایگاه داده را بررسی و دوباره تلاش کنید.';
            }
        }
    }
}
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#315c4b">
    <title>نصب باهم بخوانیم</title>
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body>
    <main class="welcome auth-card">
        <p class="eyebrow">راه‌اندازی اولیه</p>
        <h1>نصب</h1>

        <?php if ($error !== ''): ?>
            <p class="message message-error" role="alert"><?= escape_html($error) ?></p>
        <?php elseif ($notice !== ''): ?>
            <p class="message message-success" role="status"><?= escape_html($notice) ?></p>
        <?php elseif ($installed): ?>
            <p class="message">حساب مدیر از قبل وجود دارد و نصب قفل شده است. فایل install.php را از میزبان حذف کنید.</p>
        <?php else: ?>
            <p class="intro">نخستین حساب مدیر را بسازید. هنگام ورود، این شماره موبایل را با رمز یک‌بارمصرف تأیید خواهید کرد.</p>
            <form class="auth-form" method="post" action="install.php">
                <input type="hidden" name="csrf_token" value="<?= escape_html(csrf_token()) ?>">
                <label for="installation_key">کلید نصب</label>
                <input id="installation_key" name="installation_key" type="password" autocomplete="off" required>
                <label for="phone">شماره موبایل مدیر</label>
                <input
                    id="phone"
                    name="phone"
                    type="tel"
                    inputmode="tel"
                    autocomplete="tel"
                    placeholder="09123456789"
                    pattern="09[0-9]{9}"
                    maxlength="11"
                    required
                >
                <p class="field-hint">شماره موبایل ۱۱ رقمی خود را که با 09 شروع می‌شود وارد کنید.</p>
                <button class="button" type="submit" <?= INSTALLATION_KEY === '' ? 'disabled' : '' ?>>ساخت حساب مدیر</button>
            </form>
            <?php if (INSTALLATION_KEY === ''): ?>
                <p class="message message-error">برای ادامه، یک INSTALLATION_KEY طولانی و یکتا در فایل config.php تنظیم کنید.</p>
            <?php endif; ?>
        <?php endif; ?>
    </main>
</body>
</html>
