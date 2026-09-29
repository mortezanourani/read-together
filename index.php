<?php
require_once __DIR__ . '/includes/bootstrap.php';

if (empty($_SESSION['account_id'])) {
    header('Location: login.php');
    exit;
}

try {
    $statement = database()->prepare(
        'SELECT accounts.phone, roles.name AS role
         FROM accounts
         INNER JOIN roles ON roles.id = accounts.role_id
         WHERE accounts.id = :id'
    );
    $statement->execute(['id' => $_SESSION['account_id']]);
    $account = $statement->fetch();
} catch (PDOException $exception) {
    error_log($exception->getMessage());
    $account = false;
}

if (!$account) {
    $_SESSION = [];
    session_regenerate_id(true);
    header('Location: login.php');
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#315c4b">
    <meta name="description" content="Read and share stories together.">
    <title>Read Together</title>
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="icon" href="assets/icons/icon-192.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/css/app.css">
    <script src="assets/js/app.js" defer></script>
</head>
<body>
    <main class="welcome">
        <p class="eyebrow">A little space for stories</p>
        <h1>Read Together</h1>
        <p class="intro">You're signed in as <?= escape_html($account['phone']) ?>.</p>
        <p class="account-role"><?= escape_html($account['role']) ?> account</p>
        <form action="logout.php" method="post">
            <input type="hidden" name="csrf_token" value="<?= escape_html(csrf_token()) ?>">
            <button class="button button-secondary" type="submit">Log out</button>
        </form>
    </main>
</body>
</html>
