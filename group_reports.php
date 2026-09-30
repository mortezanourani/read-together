<?php
require_once __DIR__ . '/includes/assignments.php';

if (empty($_SESSION['account_id'])) {
    header('Location: login.php');
    exit;
}

$groupId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$today = gmdate('Y-m-d');
$requestedDate = $_GET['date'] ?? $today;
$date = is_string($requestedDate) ? $requestedDate : '';
$error = '';
$group = false;
$assignments = [];

if ($groupId === false || $groupId === null) {
    http_response_code(404);
    $error = 'This group could not be found.';
} elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)
    || !valid_iso_date($date)
    || $date > $today) {
    http_response_code(400);
    $error = 'Choose a valid date no later than today.';
} else {
    try {
        $connection = database();
        ensure_group_schema($connection);
        $groupQuery = $connection->prepare(
            'SELECT id, name, created_by FROM `groups` WHERE id = :id'
        );
        $groupQuery->execute(['id' => $groupId]);
        $group = $groupQuery->fetch();

        if (!$group || (int) $group['created_by'] !== (int) $_SESSION['account_id']) {
            http_response_code(403);
            $error = 'Only this group’s creator can view member reading reports.';
        } else {
            $assignments = assignments_for_group_date($connection, $groupId, $date);
        }
    } catch (PDOException $exception) {
        error_log($exception->getMessage());
        http_response_code(503);
        $error = 'Reading reports are unavailable. Check the database configuration and permissions.';
    }
}

$readCount = 0;
$missedCount = 0;
$pendingCount = 0;
foreach ($assignments as &$assignment) {
    if ($assignment['status'] === 'read') {
        $readCount++;
        $assignment['report_status'] = 'Read';
    } elseif ($date < $today) {
        $missedCount++;
        $assignment['report_status'] = 'Missed';
    } else {
        $pendingCount++;
        $assignment['report_status'] = 'Not submitted';
    }
}
unset($assignment);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#315c4b">
    <title>Reading reports | Read Together</title>
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="icon" href="assets/icons/icon-192.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body>
    <main class="home-page chapter-admin">
        <a class="back-link" href="<?= $group ? 'group.php?id=' . (int) $group['id'] : 'index.php' ?>">&larr; Back to group</a>
        <header class="page-header">
            <div>
                <p class="eyebrow">Creator view</p>
                <h1>Reading reports</h1>
                <?php if ($group): ?>
                    <p class="intro"><?= escape_html($group['name']) ?></p>
                <?php endif; ?>
            </div>
        </header>

        <?php if ($error !== ''): ?>
            <p class="message message-error" role="alert"><?= escape_html($error) ?></p>
        <?php else: ?>
            <section class="report-date-section" aria-labelledby="report-date-heading">
                <h2 id="report-date-heading">Choose a day</h2>
                <form class="report-date-form" method="get" action="group_reports.php">
                    <input type="hidden" name="id" value="<?= (int) $group['id'] ?>">
                    <label class="visually-hidden" for="report-date">Assignment date</label>
                    <input id="report-date" name="date" type="date" value="<?= escape_html($date) ?>" max="<?= escape_html($today) ?>" required>
                    <button class="button" type="submit">View reports</button>
                </form>
            </section>

            <?php if ($assignments === []): ?>
                <p class="message">There are no assignments for this date. It may be before a cycle started or outside a cycle.</p>
            <?php else: ?>
            <section class="report-summary" aria-label="Report summary">
                <div><strong><?= $readCount ?></strong><span>Read</span></div>
                <div><strong><?= $missedCount ?></strong><span>Missed</span></div>
                <div><strong><?= $pendingCount ?></strong><span>Not submitted</span></div>
            </section>

            <p class="report-date-label">
                Reports for <?= escape_html($date) ?>.
                <?php if ($date < $today): ?>
                    Unreported chapters are marked missed; reports cannot be submitted late.
                <?php else: ?>
                    Unreported chapters are still pending.
                <?php endif; ?>
            </p>

            <div class="report-table-wrap">
                <table class="report-table">
                    <thead>
                        <tr>
                            <th scope="col">Member</th>
                            <th scope="col">Chapter</th>
                            <th scope="col">Title</th>
                            <th scope="col">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($assignments as $assignment): ?>
                            <tr>
                                <td><?= escape_html($assignment['display_name']) ?></td>
                                <td><?= (int) $assignment['chapter']['chapter_number'] ?></td>
                                <td><?= escape_html($assignment['chapter']['title']) ?></td>
                                <td><span class="report-status report-status-<?= strtolower(str_replace(' ', '-', $assignment['report_status'])) ?>"><?= escape_html($assignment['report_status']) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </main>
</body>
</html>
