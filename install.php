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
    $error = 'The database is unavailable. Check the settings in config.php.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$installed && $error === '') {
    if (!is_valid_csrf_token($_POST['csrf_token'] ?? null)) {
        $error = 'Your session expired. Refresh the page and try again.';
    } elseif (INSTALLATION_KEY === '') {
        $error = 'Set a long, unique INSTALLATION_KEY in config.php before installing.';
    } elseif (!is_string($_POST['installation_key'] ?? null)
        || !hash_equals(INSTALLATION_KEY, $_POST['installation_key'])) {
        $error = 'The installation key is incorrect.';
    } else {
        $submittedPhone = $_POST['phone'] ?? '';
        $phone = is_string($submittedPhone)
            ? normalize_phone_number($submittedPhone)
            : null;

        if ($phone === null) {
            $error = 'Enter a valid 11-digit mobile number starting with 09.';
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
                    $notice = 'The Admin account was created. Remove install.php from the host, then sign in with this mobile number.';
                }
            } catch (Throwable $exception) {
                if ($connection->inTransaction()) {
                    $connection->rollBack();
                }
                error_log($exception->getMessage());
                $error = 'Installation failed. Check the database configuration and try again.';
            }
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
    <title>Install Read Together</title>
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body>
    <main class="welcome auth-card">
        <p class="eyebrow">First-time setup</p>
        <h1>Install</h1>

        <?php if ($error !== ''): ?>
            <p class="message message-error" role="alert"><?= escape_html($error) ?></p>
        <?php elseif ($notice !== ''): ?>
            <p class="message message-success" role="status"><?= escape_html($notice) ?></p>
        <?php elseif ($installed): ?>
            <p class="message">An Admin account already exists. Installation is locked. Remove install.php from the host.</p>
        <?php else: ?>
            <p class="intro">Create the first Admin account. You'll verify this mobile number with an OTP when signing in.</p>
            <form class="auth-form" method="post" action="install.php">
                <input type="hidden" name="csrf_token" value="<?= escape_html(csrf_token()) ?>">
                <label for="installation_key">Installation key</label>
                <input id="installation_key" name="installation_key" type="password" autocomplete="off" required>
                <label for="phone">Admin phone number</label>
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
                <p class="field-hint">Enter your 11-digit mobile number starting with 09.</p>
                <button class="button" type="submit" <?= INSTALLATION_KEY === '' ? 'disabled' : '' ?>>Create Admin account</button>
            </form>
            <?php if (INSTALLATION_KEY === ''): ?>
                <p class="message message-error">Set a long, unique INSTALLATION_KEY in config.php before continuing.</p>
            <?php endif; ?>
        <?php endif; ?>
    </main>
</body>
</html>
