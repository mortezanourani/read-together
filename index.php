<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/groups.php';

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

try {
    ensure_group_schema(database());
    $groupStatement = database()->prepare(
        'SELECT `groups`.id, `groups`.name, `groups`.invite_code,
                COUNT(group_members.account_id) AS member_count
         FROM `groups`
         INNER JOIN group_members ON group_members.group_id = `groups`.id
         WHERE group_members.account_id = :account_id AND `groups`.status <> `deactivated`
         GROUP BY `groups`.id, `groups`.name, `groups`.invite_code
         ORDER BY `groups`.name'
    );
    $groupStatement->execute(['account_id' => $_SESSION['account_id']]);
    $groups = $groupStatement->fetchAll();
} catch (PDOException $exception) {
    error_log($exception->getMessage());
    http_response_code(503);
    $groups = [];
    $groupsUnavailable = true;
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
    <main class="home-page">
        <header class="page-header">
            <div>
                <p class="eyebrow">A little space for stories</p>
                <h1>Read Together</h1>
                <p class="intro">Signed in as <?= escape_html($account['phone']) ?></p>
            </div>
            <form action="logout.php" method="post">
                <input type="hidden" name="csrf_token" value="<?= escape_html(csrf_token()) ?>">
                <button class="button button-secondary" type="submit">Log out</button>
            </form>
        </header>

        <?php if ($account['role'] === 'Admin'): ?>
            <p class="admin-link-wrap"><a class="admin-link" href="admin/chapters.php">Manage book chapters <span aria-hidden="true">&rarr;</span></a></p>
        <?php endif; ?>

        <?php if (!empty($groupsUnavailable)): ?>
            <p class="message message-error" role="alert">Groups are temporarily unavailable. Check that the database user can create tables and try again.</p>
        <?php endif; ?>

        <section class="home-section" aria-labelledby="groups-heading">
            <div class="section-heading">
                <div>
                    <p class="eyebrow">Your reading circles</p>
                    <h2 id="groups-heading">Your groups</h2>
                </div>
                <span class="group-total"><?= count($groups) ?></span>
            </div>

            <?php if ($groups === []): ?>
                <p class="empty-state">You haven't joined a group yet. Create one or join with an invitation code.</p>
            <?php else: ?>
                <div class="group-grid">
                    <?php foreach ($groups as $group): ?>
                        <a class="group-card" href="group.php?id=<?= (int) $group['id'] ?>">
                            <span class="group-card-mark" aria-hidden="true">R</span>
                            <span class="group-card-content">
                                <strong><?= escape_html($group['name']) ?></strong>
                                <span><?= (int) $group['member_count'] ?> <?= (int) $group['member_count'] === 1 ? 'member' : 'members' ?></span>
                            </span>
                            <span class="group-card-arrow" aria-hidden="true">&rarr;</span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="action-grid" aria-label="Group actions">
            <a class="action-card action-card-create" href="create_group.php">
                <span class="action-icon" aria-hidden="true">+</span>
                <span class="action-copy">
                    <strong>Create a group</strong>
                    <span>Start a new group and invite others.</span>
                </span>
                <span class="group-card-arrow" aria-hidden="true">&rarr;</span>
            </a>

            <div class="action-card action-card-join">
                <span class="action-icon" aria-hidden="true">&#8599;</span>
                <span class="action-copy">
                    <strong>Join a group</strong>
                    <span>Use a group invitation code or link.</span>
                </span>
                <form class="join-inline-form" action="join.php" method="get">
                    <label class="visually-hidden" for="home-invite-code">Invitation code</label>
                    <input id="home-invite-code" name="code" type="text" pattern="[A-Fa-f0-9]{12}" maxlength="12" placeholder="12-character code" required>
                    <button class="button" type="submit">Join</button>
                </form>
            </div>
        </section>
    </main>
</body>
</html>
