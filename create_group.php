<?php
require_once __DIR__ . '/includes/groups.php';

if (empty($_SESSION['account_id'])) {
    header('Location: login.php');
    exit;
}

$error = '';
$groupName = '';

try {
    ensure_group_schema(database());
} catch (PDOException $exception) {
    error_log($exception->getMessage());
    http_response_code(503);
    $error = 'Groups are unavailable. Check the database configuration and permissions.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error === '') {
    if (!is_valid_csrf_token($_POST['csrf_token'] ?? null)) {
        $error = 'Your session expired. Refresh the page and try again.';
    } else {
        $submittedName = $_POST['name'] ?? '';
        $groupName = is_string($submittedName) ? trim($submittedName) : '';

        $nameLength = preg_match_all('/./us', $groupName);

        if ($nameLength === false || $groupName === '' || $nameLength > 100) {
            $error = 'Enter a group name of up to 100 characters.';
        } else {
            try {
                $connection = database();
                $created = false;

                for ($attempt = 0; $attempt < 5 && !$created; $attempt++) {
                    $code = strtoupper(bin2hex(random_bytes(6)));

                    try {
                        $connection->beginTransaction();
                        $createGroup = $connection->prepare(
                            'INSERT INTO `groups` (name, invite_code, created_by)
                             VALUES (:name, :invite_code, :created_by)'
                        );
                        $createGroup->execute([
                            'name' => $groupName,
                            'invite_code' => $code,
                            'created_by' => $_SESSION['account_id'],
                        ]);
                        $groupId = (int) $connection->lastInsertId();

                        $addCreator = $connection->prepare(
                            'INSERT INTO group_members (group_id, account_id)
                             VALUES (:group_id, :account_id)'
                        );
                        $addCreator->execute([
                            'group_id' => $groupId,
                            'account_id' => $_SESSION['account_id'],
                        ]);
                        $connection->commit();
                        $created = true;
                    } catch (PDOException $exception) {
                        if ($connection->inTransaction()) {
                            $connection->rollBack();
                        }

                        if ($exception->getCode() === '23000' && $attempt < 4) {
                            continue;
                        }

                        throw $exception;
                    }
                }

                if (!$created) {
                    throw new RuntimeException('Could not generate a unique invitation code.');
                }

                header('Location: group.php?id=' . $groupId . '&created=1');
                exit;
            } catch (Throwable $exception) {
                error_log($exception->getMessage());
                $error = 'Could not create the group. Please try again.';
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
    <title>Create a group | Read Together</title>
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="icon" href="assets/icons/icon-192.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/css/app.css">
    <script src="assets/js/app.js" defer></script>
</head>
<body>
    <main class="welcome auth-card">
        <a class="back-link" href="index.php">&larr; Back to your groups</a>
        <p class="eyebrow">A new reading circle</p>
        <h1>Create a group</h1>
        <p class="intro">Choose a name. You'll get a unique invitation code to share.</p>

        <?php if ($error !== ''): ?>
            <p class="message message-error" role="alert"><?= escape_html($error) ?></p>
        <?php endif; ?>

        <form class="auth-form" method="post" action="create_group.php">
            <input type="hidden" name="csrf_token" value="<?= escape_html(csrf_token()) ?>">
            <label for="name">Group name</label>
            <input
                id="name"
                name="name"
                type="text"
                maxlength="100"
                autocomplete="off"
                value="<?= escape_html($groupName) ?>"
                placeholder="e.g. Sunday Reading Club"
                required
            >
            <button class="button" type="submit">Create group</button>
        </form>
    </main>
</body>
</html>
