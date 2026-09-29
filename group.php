<?php
require_once __DIR__ . '/includes/groups.php';

if (empty($_SESSION['account_id'])) {
    header('Location: login.php');
    exit;
}

$group = false;
$members = [];
$error = '';
$groupId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);

if ($groupId === false || $groupId === null) {
    http_response_code(404);
    $error = 'This group could not be found.';
} else {
    try {
        ensure_group_schema(database());
        $findGroup = database()->prepare(
            'SELECT `groups`.id, `groups`.name, `groups`.invite_code,
                    `groups`.created_at, group_members.joined_at
             FROM `groups`
             INNER JOIN group_members ON group_members.group_id = `groups`.id
             WHERE `groups`.id = :group_id AND group_members.account_id = :account_id'
        );
        $findGroup->execute([
            'group_id' => $groupId,
            'account_id' => $_SESSION['account_id'],
        ]);
        $group = $findGroup->fetch();

        if (!$group) {
            http_response_code(404);
            $error = 'This group could not be found.';
        } else {
            $memberQuery = database()->prepare(
                'SELECT accounts.phone
                 FROM group_members
                 INNER JOIN accounts ON accounts.id = group_members.account_id
                 WHERE group_members.group_id = :group_id
                 ORDER BY group_members.joined_at'
            );
            $memberQuery->execute(['group_id' => $groupId]);
            $members = $memberQuery->fetchAll();
        }
    } catch (PDOException $exception) {
        error_log($exception->getMessage());
        http_response_code(503);
        $error = 'Groups are unavailable. Check the database configuration and permissions.';
    }
}

$invitationUrl = $group ? group_invitation_url($group['invite_code']) : '';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#315c4b">
    <title><?= $group ? escape_html($group['name']) . ' | Read Together' : 'Group | Read Together' ?></title>
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="icon" href="assets/icons/icon-192.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/css/app.css">
    <script src="assets/js/app.js" defer></script>
</head>
<body>
    <main class="welcome auth-card">
        <a class="back-link" href="index.php">&larr; Back to your groups</a>

        <?php if ($error !== ''): ?>
            <p class="message message-error" role="alert"><?= escape_html($error) ?></p>
        <?php else: ?>
            <?php if (isset($_GET['created'])): ?>
                <p class="message message-success" role="status">Your group is ready. Invite others with its code or link.</p>
            <?php elseif (isset($_GET['joined'])): ?>
                <p class="message message-success" role="status">You joined the group.</p>
            <?php endif; ?>

            <p class="eyebrow">Your reading circle</p>
            <h1><?= escape_html($group['name']) ?></h1>

            <section class="group-info-section" aria-labelledby="invitation-heading">
                <h2 id="invitation-heading">Invite someone</h2>
                <p class="section-copy">Share this code or link so others can join.</p>
                <div class="invite-code"><?= escape_html($group['invite_code']) ?></div>
                <button
                    class="button copy-invite-button"
                    type="button"
                    data-copy-text="<?= escape_html($invitationUrl) ?>"
                >Copy invitation link</button>
                <p class="copy-status" role="status" aria-live="polite"></p>
                <a class="invite-link" href="<?= escape_html($invitationUrl) ?>"><?= escape_html($invitationUrl) ?></a>
            </section>

            <section class="group-info-section" aria-labelledby="members-heading">
                <div class="section-heading">
                    <h2 id="members-heading">Members</h2>
                    <span class="group-total"><?= count($members) ?></span>
                </div>
                <ul class="member-list">
                    <?php foreach ($members as $member): ?>
                        <li><?= escape_html($member['phone']) ?></li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endif; ?>
    </main>
</body>
</html>
