<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/groups.php';
require_once __DIR__ . '/includes/schema.php';
require_once __DIR__ . '/includes/sms.php';

if (!empty($_SESSION['account_id'])) {
    header('Location: index.php');
    exit;
}

$error = '';
$notice = '';
$otpPhone = isset($_SESSION['otp_phone']) ? (string) $_SESSION['otp_phone'] : '';
$connection = null;
$schemaReady = false;

try {
    $connection = database();
    $schemaReady = schema_is_installed($connection);
} catch (PDOException $exception) {
    error_log($exception->getMessage());
    $error = 'پایگاه داده در دسترس نیست. تنظیمات فایل config.php را بررسی کنید.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error === '') {
    if (!is_valid_csrf_token($_POST['csrf_token'] ?? null)) {
        $error = 'نشست شما منقضی شده است. صفحه را تازه‌سازی کنید و دوباره تلاش کنید.';
    } elseif (!$schemaReady) {
        $error = 'پایگاه داده هنوز راه‌اندازی نشده است. فایل config.php را تنظیم و install.php را اجرا کنید.';
    } else {
        $action = $_POST['action'] ?? '';

        try {
            if ($action === 'request_otp') {
                $submittedPhone = $_POST['phone'] ?? '';
                $phone = is_string($submittedPhone)
                    ? normalize_phone_number($submittedPhone)
                    : null;

                if ($phone === null) {
                    $error = 'شماره موبایل ۱۱ رقمی معتبر که با ۰۹ شروع می‌شود وارد کنید.';
                } else {
                    $rateLimit = $connection->prepare(
                        'SELECT created_at FROM login_otps
                         WHERE phone = :phone
                           AND created_at > UTC_TIMESTAMP() - INTERVAL 1 MINUTE'
                    );
                    $rateLimit->execute(['phone' => $phone]);

                    if ($rateLimit->fetch()) {
                        $error = 'برای درخواست کد جدید، یک دقیقه صبر کنید.';
                    } else {
                        $code = (string) random_int(100000, 999999);
                        $codeHash = password_hash($code, PASSWORD_DEFAULT);
                        $expiresAt = gmdate('Y-m-d H:i:s', time() + 600);

                        $saveOtp = $connection->prepare(
                            'INSERT INTO login_otps (phone, code_hash, expires_at, attempts, created_at)
                             VALUES (:phone, :code_hash, :expires_at, 0, UTC_TIMESTAMP())
                             ON DUPLICATE KEY UPDATE
                                code_hash = VALUES(code_hash),
                                expires_at = VALUES(expires_at),
                                attempts = 0,
                                created_at = UTC_TIMESTAMP()'
                        );
                        $saveOtp->execute([
                            'phone' => $phone,
                            'code_hash' => $codeHash,
                            'expires_at' => $expiresAt,
                        ]);

                        try {
                            send_login_otp($phone, $code);
                            $_SESSION['otp_phone'] = $phone;
                            $otpPhone = $phone;
                            $notice = 'کد ورود به شماره موبایل شما ارسال شد.';
                        } catch (LogicException $exception) {
                            $error = 'ارسال کد یک‌بارمصرف هنوز تنظیم نشده است. با مدیر سامانه تماس بگیرید.';
                        } catch (Throwable $exception) {
                            error_log($exception->getMessage());
                            $error = 'ارسال کد ورود ممکن نشد. لطفاً بعداً دوباره تلاش کنید.';
                        }
                    }
                }
            } elseif ($action === 'verify_otp') {
                $code = (string) ($_POST['code'] ?? '');

                if ($otpPhone === '' || !preg_match('/^\d{6}$/D', $code)) {
                    $error = 'کد شش‌رقمی ارسال‌شده به شماره موبایل خود را وارد کنید.';
                } else {
                    $connection->beginTransaction();
                    $findOtp = $connection->prepare(
                        'SELECT code_hash, expires_at, attempts
                         FROM login_otps WHERE phone = :phone FOR UPDATE'
                    );
                    $findOtp->execute(['phone' => $otpPhone]);
                    $otp = $findOtp->fetch();

                    if (!$otp
                        || (int) $otp['attempts'] >= 5
                        || strtotime($otp['expires_at'] . ' UTC') <= time()) {
                        if ($otp) {
                            $removeOtp = $connection->prepare(
                                'DELETE FROM login_otps WHERE phone = :phone'
                            );
                            $removeOtp->execute(['phone' => $otpPhone]);
                        }
                        $connection->commit();
                        $error = 'کد نامعتبر است یا مهلتش تمام شده است. کد جدیدی درخواست کنید.';
                    } elseif (!password_verify($code, $otp['code_hash'])) {
                        $incrementAttempts = $connection->prepare(
                            'UPDATE login_otps SET attempts = attempts + 1 WHERE phone = :phone'
                        );
                        $incrementAttempts->execute(['phone' => $otpPhone]);
                        $connection->commit();
                        $error = 'کد نامعتبر است یا مهلتش تمام شده است. کد جدیدی درخواست کنید.';
                    } else {
                        $roleQuery = $connection->prepare(
                            'SELECT accounts.id, roles.name AS role
                             FROM accounts
                             INNER JOIN roles ON roles.id = accounts.role_id
                             WHERE accounts.phone = :phone'
                        );
                        $roleQuery->execute(['phone' => $otpPhone]);
                        $account = $roleQuery->fetch();

                        if (!$account) {
                            $userRole = $connection->prepare(
                                "SELECT id FROM roles WHERE name = 'User'"
                            );
                            $userRole->execute();
                            $roleId = $userRole->fetchColumn();

                            if (!$roleId) {
                                throw new RuntimeException('نقش کاربر در پایگاه داده وجود ندارد.');
                            }

                            $createAccount = $connection->prepare(
                                'INSERT INTO accounts (phone, role_id) VALUES (:phone, :role_id)
                                 ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)'
                            );
                            $createAccount->execute([
                                'phone' => $otpPhone,
                                'role_id' => $roleId,
                            ]);
                            $roleQuery->execute(['phone' => $otpPhone]);
                            $account = $roleQuery->fetch();
                        }

                        $removeOtp = $connection->prepare(
                            'DELETE FROM login_otps WHERE phone = :phone'
                        );
                        $removeOtp->execute(['phone' => $otpPhone]);
                        $connection->commit();

                        session_regenerate_id(true);
                        $_SESSION['account_id'] = (int) $account['id'];
                        unset($_SESSION['otp_phone']);

                        $pendingInviteCode = normalize_invite_code(
                            (string) ($_SESSION['pending_invite_code'] ?? '')
                        );
                        if ($pendingInviteCode !== null) {
                            $_SESSION['pending_invite_code'] = $pendingInviteCode;
                            header('Location: profile.php?next=invite');
                            exit;
                        }

                        $profileName = $connection->prepare(
                            'SELECT display_name FROM accounts WHERE id = :id'
                        );
                        $profileName->execute(['id' => $account['id']]);
                        $displayName = $profileName->fetchColumn();
                        if ($displayName === false || $displayName === null || $displayName === '') {
                            header('Location: profile.php');
                            exit;
                        }

                        header('Location: index.php');
                        exit;
                    }
                }
            } else {
                $error = 'درخواست یا تأیید کد را انتخاب کنید.';
            }
        } catch (PDOException $exception) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            error_log($exception->getMessage());
            $error = 'پردازش درخواست ورود ممکن نشد. لطفاً بعداً دوباره تلاش کنید.';
        } catch (Throwable $exception) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            error_log($exception->getMessage());
            $error = 'پردازش درخواست ورود ممکن نشد. لطفاً بعداً دوباره تلاش کنید.';
        }
    }
}

$pageTitle = 'ورود | باهم بخوانیم';
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#315c4b">
    <meta name="description" content="با کد یک‌بارمصرف موبایل وارد باهم بخوانیم شوید.">
    <title><?= escape_html($pageTitle) ?></title>
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="icon" href="assets/icons/icon-192.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/css/app.css">
    <script src="assets/js/app.js" defer></script>
</head>
<body>
    <main class="welcome auth-card">
        <p class="eyebrow">خوش برگشتید</p>
        <h1>ورود</h1>
        <p class="intro">شماره موبایل خود را وارد کنید تا کد یک‌بارمصرف برایتان ارسال شود.</p>

        <?php if ($error !== ''): ?>
            <p class="message message-error" role="alert"><?= escape_html($error) ?></p>
        <?php elseif ($notice !== ''): ?>
            <p class="message message-success" role="status"><?= escape_html($notice) ?></p>
        <?php endif; ?>

        <?php if (!$schemaReady && $error === ''): ?>
            <p class="message">برای راه‌اندازی برنامه، پایگاه داده را در config.php تنظیم کنید و سپس install.php را اجرا کنید.</p>
        <?php endif; ?>

        <form class="auth-form" method="post" action="login.php">
            <input type="hidden" name="csrf_token" value="<?= escape_html(csrf_token()) ?>">
            <input type="hidden" name="action" value="request_otp">
            <label for="phone">شماره موبایل</label>
            <input
                id="phone"
                name="phone"
                type="tel"
                inputmode="tel"
                autocomplete="tel"
                placeholder="09123456789"
                pattern="09[0-9]{9}"
                maxlength="11"
                value="<?= escape_html($otpPhone) ?>"
                required
            >
            <p class="field-hint">شماره موبایل ۱۱ رقمی خود را با ۰۹ وارد کنید.</p>
            <button class="button" type="submit" <?= $schemaReady ? '' : 'disabled' ?>>ارسال کد ورود</button>
        </form>

        <?php if ($otpPhone !== ''): ?>
            <form class="auth-form verification-form" method="post" action="login.php">
                <input type="hidden" name="csrf_token" value="<?= escape_html(csrf_token()) ?>">
                <input type="hidden" name="action" value="verify_otp">
                <label for="code">کد یک‌بارمصرف</label>
                <input
                    id="code"
                    name="code"
                    type="text"
                    inputmode="numeric"
                    autocomplete="one-time-code"
                    pattern="[0-9]{6}"
                    maxlength="6"
                    placeholder="123456"
                    required
                >
                <button class="button" type="submit">تأیید و ورود</button>
            </form>
        <?php endif; ?>
    </main>
</body>
</html>
