<?php
require_once __DIR__ . '/includes/bootstrap.php';
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
    $error = 'The database is unavailable. Check the settings in config.php.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error === '') {
    if (!is_valid_csrf_token($_POST['csrf_token'] ?? null)) {
        $error = 'Your session expired. Refresh the page and try again.';
    } elseif (!$schemaReady) {
        $error = 'The database is not installed yet. Configure config.php and run install.php.';
    } else {
        $action = $_POST['action'] ?? '';

        try {
            if ($action === 'request_otp') {
                $phone = normalize_phone_number((string) ($_POST['phone'] ?? ''));

                if ($phone === null) {
                    $error = 'Enter a valid phone number with country code, such as +14155552671.';
                } else {
                    $rateLimit = $connection->prepare(
                        'SELECT created_at FROM login_otps
                         WHERE phone = :phone
                           AND created_at > UTC_TIMESTAMP() - INTERVAL 1 MINUTE'
                    );
                    $rateLimit->execute(['phone' => $phone]);

                    if ($rateLimit->fetch()) {
                        $error = 'Please wait a minute before requesting another code.';
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
                            $notice = 'A sign-in code was sent to your phone.';
                        } catch (LogicException $exception) {
                            $error = 'OTP delivery is not configured yet. Contact the administrator.';
                        } catch (Throwable $exception) {
                            error_log($exception->getMessage());
                            $error = 'Could not send a sign-in code. Please try again later.';
                        }
                    }
                }
            } elseif ($action === 'verify_otp') {
                $code = (string) ($_POST['code'] ?? '');

                if ($otpPhone === '' || !preg_match('/^\d{6}$/D', $code)) {
                    $error = 'Enter the six-digit code sent to your phone.';
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
                        $error = 'That code is invalid or expired. Request a new code.';
                    } elseif (!password_verify($code, $otp['code_hash'])) {
                        $incrementAttempts = $connection->prepare(
                            'UPDATE login_otps SET attempts = attempts + 1 WHERE phone = :phone'
                        );
                        $incrementAttempts->execute(['phone' => $otpPhone]);
                        $connection->commit();
                        $error = 'That code is invalid or expired. Request a new code.';
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
                                throw new RuntimeException('The User role is missing from the database.');
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
                        header('Location: index.php');
                        exit;
                    }
                }
            } else {
                $error = 'Choose whether to request or verify a code.';
            }
        } catch (PDOException $exception) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            error_log($exception->getMessage());
            $error = 'Could not process your sign-in request. Please try again later.';
        } catch (Throwable $exception) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            error_log($exception->getMessage());
            $error = 'Could not process your sign-in request. Please try again later.';
        }
    }
}

$pageTitle = 'Sign in | Read Together';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#315c4b">
    <meta name="description" content="Sign in to Read Together with a one-time phone code.">
    <title><?= escape_html($pageTitle) ?></title>
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="icon" href="assets/icons/icon-192.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/css/app.css">
    <script src="assets/js/app.js" defer></script>
</head>
<body>
    <main class="welcome auth-card">
        <p class="eyebrow">Welcome back</p>
        <h1>Sign in</h1>
        <p class="intro">Enter your phone number and we'll send you a one-time code.</p>

        <?php if ($error !== ''): ?>
            <p class="message message-error" role="alert"><?= escape_html($error) ?></p>
        <?php elseif ($notice !== ''): ?>
            <p class="message message-success" role="status"><?= escape_html($notice) ?></p>
        <?php endif; ?>

        <?php if (!$schemaReady && $error === ''): ?>
            <p class="message">Configure the database in config.php, then run install.php to set up the app.</p>
        <?php endif; ?>

        <form class="auth-form" method="post" action="login.php">
            <input type="hidden" name="csrf_token" value="<?= escape_html(csrf_token()) ?>">
            <input type="hidden" name="action" value="request_otp">
            <label for="phone">Phone number</label>
            <input
                id="phone"
                name="phone"
                type="tel"
                inputmode="tel"
                autocomplete="tel"
                placeholder="+14155552671"
                pattern="\+[1-9][0-9]{7,14}"
                maxlength="16"
                value="<?= escape_html($otpPhone) ?>"
                required
            >
            <p class="field-hint">Include your country code, for example +14155552671.</p>
            <button class="button" type="submit" <?= $schemaReady ? '' : 'disabled' ?>>Send sign-in code</button>
        </form>

        <?php if ($otpPhone !== ''): ?>
            <form class="auth-form verification-form" method="post" action="login.php">
                <input type="hidden" name="csrf_token" value="<?= escape_html(csrf_token()) ?>">
                <input type="hidden" name="action" value="verify_otp">
                <label for="code">One-time code</label>
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
                <button class="button" type="submit">Verify and sign in</button>
            </form>
        <?php endif; ?>
    </main>
</body>
</html>
