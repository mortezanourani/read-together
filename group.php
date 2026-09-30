<?php
require_once __DIR__ . '/includes/assignments.php';

if (empty($_SESSION['account_id'])) {
    header('Location: login.php');
    exit;
}

$accountId = (int) $_SESSION['account_id'];
$groupId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$group = false;
$members = [];
$todayAssignments = [];
$error = '';
$notice = '';
$chaptersAvailable = 0;
$dailyTotal = 0;
$creator = false;
$currentMemberCount = null;

if ($groupId === false || $groupId === null) {
    http_response_code(404);
    $error = 'This group could not be found.';
} else {
    try {
        $connection = database();
        ensure_group_schema($connection);
        synchronize_group_cycle($connection, $groupId);

        $groupQuery = $connection->prepare(
            'SELECT `groups`.id, `groups`.name, `groups`.invite_code,
                    `groups`.created_by, `groups`.status,
                    `groups`.assignments_start_date, `groups`.cycle_number,
                    group_members.daily_chapter_count
             FROM `groups`
             INNER JOIN group_members ON group_members.group_id = `groups`.id
             WHERE `groups`.id = :group_id AND group_members.account_id = :account_id'
        );
        $groupQuery->execute([
            'group_id' => $groupId,
            'account_id' => $accountId,
        ]);
        $group = $groupQuery->fetch();

        if (!$group) {
            http_response_code(404);
            $error = 'This group could not be found.';
        } else {
            if ($group['status'] === 'setup') {
                begin_group_cycle_if_ready($connection, $groupId);
                $groupQuery->execute([
                    'group_id' => $groupId,
                    'account_id' => $accountId,
                ]);
                $group = $groupQuery->fetch();
            }

            $creator = (int) $group['created_by'] === $accountId;
            $chaptersAvailable = (int) $connection->query(
                'SELECT COUNT(DISTINCT chapter_number) FROM chapters
                 WHERE chapter_number BETWEEN 1 AND 120'
            )->fetchColumn();
            $memberQuery = $connection->prepare(
                "SELECT accounts.id AS account_id,
                        COALESCE(NULLIF(accounts.display_name, ''), 'Unnamed reader') AS display_name,
                        group_members.daily_chapter_count, group_members.joined_at
                 FROM group_members
                 INNER JOIN accounts ON accounts.id = group_members.account_id
                 WHERE group_members.group_id = :group_id
                 ORDER BY group_members.joined_at, accounts.id"
            );
            $memberQuery->execute(['group_id' => $groupId]);
            $members = $memberQuery->fetchAll();
            foreach ($members as $member) {
                $dailyTotal += (int) $member['daily_chapter_count'];
                if ((int) $member['account_id'] === $accountId) {
                    $currentMemberCount = $member['daily_chapter_count'] === null
                        ? null
                        : (int) $member['daily_chapter_count'];
                }
            }

            if ($group['status'] === 'active') {
                $todayAssignments = assignments_for_group_date(
                    $connection,
                    $groupId,
                    gmdate('Y-m-d')
                );
            }
        }
    } catch (PDOException $exception) {
        error_log($exception->getMessage());
        http_response_code(503);
        $error = 'Groups or reading assignments are unavailable. Check the database configuration and permissions.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $group && $error === '') {
    if (!is_valid_csrf_token($_POST['csrf_token'] ?? null)) {
        $error = 'Your session expired. Refresh the page and try again.';
    } else {
        $action = $_POST['action'] ?? '';

        try {
            if ($action === 'set_daily_count') {
                $submittedCount = $_POST['daily_chapter_count'] ?? null;
                $dailyCount = is_string($submittedCount)
                    ? filter_var($submittedCount, FILTER_VALIDATE_INT)
                    : false;
                if ($dailyCount === false || $dailyCount < 1 || $dailyCount > 120) {
                    $error = 'Choose a daily chapter count from 1 to 120.';
                } else {
                    $result = update_group_daily_count(
                        $connection,
                        $groupId,
                        $accountId,
                        $dailyCount
                    );
                    if (isset($result['error'])) {
                        $error = $result['error'];
                    } else {
                        $destination = 'group.php?id=' . $groupId . '&count_saved=1';
                        if (!empty($result['started'])) {
                            $destination .= '&started=1';
                        }
                        header('Location: ' . $destination);
                        exit;
                    }
                }
            } elseif ($action === 'submit_read') {
                if ($group['status'] !== 'active') {
                    $error = 'Reading reports can only be submitted during an active cycle.';
                } else {
                    $submittedChapterId = $_POST['chapter_id'] ?? null;
                    $chapterId = is_string($submittedChapterId)
                        ? filter_var($submittedChapterId, FILTER_VALIDATE_INT, [
                            'options' => ['min_range' => 1],
                        ])
                        : false;
                    $assignment = false;
                    foreach ($todayAssignments as $todayAssignment) {
                        if ((int) $todayAssignment['account_id'] === $accountId
                            && (int) $todayAssignment['chapter']['id'] === $chapterId) {
                            $assignment = $todayAssignment;
                            break;
                        }
                    }

                    if (!$assignment) {
                        $error = 'That chapter is not assigned to you today.';
                    } elseif ($assignment['status'] === 'read') {
                        $error = 'You already submitted this chapter.';
                    } elseif ($assignment['assignment_date'] !== gmdate('Y-m-d')) {
                        $error = 'The day changed. Refresh to see your current assignments.';
                    } else {
                        $report = $connection->prepare(
                            'INSERT INTO reading_reports
                                (group_id, account_id, chapter_id, cycle_number, assignment_date)
                             VALUES
                                (:group_id, :account_id, :chapter_id, :cycle_number, :assignment_date)'
                        );
                        $report->execute([
                            'group_id' => $groupId,
                            'account_id' => $accountId,
                            'chapter_id' => $chapterId,
                            'cycle_number' => $assignment['cycle_number'],
                            'assignment_date' => $assignment['assignment_date'],
                        ]);
                        header('Location: group.php?id=' . $groupId . '&reported=1');
                        exit;
                    }
                }
            } elseif ($action === 'restart_cycle' && $creator) {
                restart_group_cycle($connection, $groupId, $accountId);
                header('Location: group.php?id=' . $groupId . '&restarted=1');
                exit;
            } elseif ($action === 'deactivate_group' && $creator) {
                deactivate_group($connection, $groupId, $accountId);
                header('Location: ../index.php');
                exit;
            } else {
                $error = 'That group action is not available.';
            }
        } catch (PDOException $exception) {
            error_log($exception->getMessage());
            $error = $exception->getCode() === '23000'
                ? 'This chapter has already been reported as read.'
                : 'Could not complete that action. Please try again.';
        } catch (Throwable $exception) {
            error_log($exception->getMessage());
            $error = 'Could not complete that action. Please try again.';
        }
    }
}

if ($group) {
    if (isset($_GET['started'])) {
        $notice = 'The 120-day reading cycle has started.';
    } elseif (isset($_GET['count_saved'])) {
        $notice = 'Your daily chapter count was saved.';
    } elseif (isset($_GET['reported'])) {
        $notice = 'Your reading report was submitted.';
    } elseif (isset($_GET['restarted'])) {
        $notice = 'The group is ready to configure its next reading cycle.';
    } elseif (isset($_GET['created'])) {
        $notice = 'Your group is ready. Set daily chapter counts to start reading together.';
    } elseif (isset($_GET['joined'])) {
        $notice = 'You joined the group.';
    }
}

$invitationUrl = $group ? group_invitation_url($group['invite_code']) : '';
$myTodayAssignments = array_values(array_filter(
    $todayAssignments,
    static function (array $assignment) use ($accountId): bool {
        return (int) $assignment['account_id'] === $accountId;
    }
));
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
    <main class="welcome auth-card group-page">
        <a class="back-link" href="index.php">&larr; Back to your groups</a>

        <?php if ($error !== ''): ?>
            <p class="message message-error" role="alert"><?= escape_html($error) ?></p>
        <?php endif; ?>
        <?php if ($notice !== ''): ?>
            <p class="message message-success" role="status"><?= escape_html($notice) ?></p>
        <?php endif; ?>

        <?php if ($group): ?>
            <p class="eyebrow">Your reading circle</p>
            <h1><?= escape_html($group['name']) ?></h1>

            <?php if ($group['status'] === 'setup'): ?>
                <section class="group-info-section" aria-labelledby="plan-heading">
                    <h2 id="plan-heading">Set your daily reading</h2>
                    <p class="section-copy">
                        Choose how many chapters you can read each day. The group starts when at least two members have joined, all 120 chapters are defined, and member counts add up to exactly 120.
                    </p>
                    <p class="quota-total">
                        Group total: <strong><?= $dailyTotal ?> / 120 chapters per day</strong>
                        <span><?= max(0, 120 - $dailyTotal) ?> remaining</span>
                    </p>
                    <?php if ($chaptersAvailable !== 120): ?>
                        <p class="message">The Admin has defined <?= $chaptersAvailable ?> of 120 chapters. Assignments start once all are ready.</p>
                    <?php endif; ?>

                    <form class="auth-form quota-form" method="post" action="group.php?id=<?= (int) $group['id'] ?>">
                        <input type="hidden" name="csrf_token" value="<?= escape_html(csrf_token()) ?>">
                        <input type="hidden" name="action" value="set_daily_count">
                        <label for="daily_chapter_count">Chapters you want to read each day</label>
                        <input
                            id="daily_chapter_count"
                            name="daily_chapter_count"
                            type="number"
                            min="1"
                            max="120"
                            value="<?= $currentMemberCount === null ? '' : $currentMemberCount ?>"
                            required
                        >
                        <button class="button" type="submit">Save daily count</button>
                    </form>
                </section>

                <section class="group-info-section" aria-labelledby="member-counts-heading">
                    <div class="section-heading">
                        <h2 id="member-counts-heading">Members</h2>
                        <span class="group-total"><?= count($members) ?></span>
                    </div>
                    <ul class="member-list">
                        <?php foreach ($members as $member): ?>
                            <li>
                                <span><?= escape_html($member['display_name']) ?></span>
                                <span class="member-quota">
                                    <?= $member['daily_chapter_count'] === null
                                        ? 'Not set'
                                        : (int) $member['daily_chapter_count'] . ' / day' ?>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </section>
            <?php elseif ($group['status'] === 'active'): ?>
                <?php $cycleDay = group_cycle_day($group, gmdate('Y-m-d')); ?>
                <p class="cycle-summary">
                    Reading day <?= $cycleDay === null ? '—' : $cycleDay + 1 ?> of 120
                    <span>Cycle started <?= escape_html($group['assignments_start_date']) ?></span>
                </p>

                <section class="group-info-section" aria-labelledby="today-heading">
                    <div class="section-heading">
                        <h2 id="today-heading">Your chapters for today</h2>
                        <span class="group-total"><?= count($myTodayAssignments) ?></span>
                    </div>
                    <?php if ($myTodayAssignments === []): ?>
                        <p class="empty-state">There are no chapters assigned to you today.</p>
                    <?php else: ?>
                        <div class="assigned-chapters">
                            <?php foreach ($myTodayAssignments as $assignment): ?>
                                <article class="assigned-chapter">
                                    <p class="eyebrow">Chapter <?= (int) $assignment['chapter']['chapter_number'] ?></p>
                                    <h3><?= escape_html($assignment['chapter']['title']) ?></h3>
                                    <p><?= nl2br(escape_html($assignment['chapter']['description'])) ?></p>
                                    <p class="chapter-boundary"><strong>Starts:</strong> <?= nl2br(escape_html($assignment['chapter']['start_sentence'])) ?></p>
                                    <p class="chapter-boundary"><strong>Ends:</strong> <?= nl2br(escape_html($assignment['chapter']['end_sentence'])) ?></p>
                                    <?php if ($assignment['status'] === 'read'): ?>
                                        <p class="report-complete">Read report submitted</p>
                                    <?php else: ?>
                                        <form method="post" action="group.php?id=<?= (int) $group['id'] ?>">
                                            <input type="hidden" name="csrf_token" value="<?= escape_html(csrf_token()) ?>">
                                            <input type="hidden" name="action" value="submit_read">
                                            <input type="hidden" name="chapter_id" value="<?= (int) $assignment['chapter']['id'] ?>">
                                            <button class="button" type="submit">I read this chapter</button>
                                        </form>
                                    <?php endif; ?>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>

            <?php elseif ($group['status'] === 'completed'): ?>
                <p class="message">This group has completed its 120-day reading cycle. The creator can start another cycle or deactivate the group.</p>
                <?php if ($creator): ?>
                    <form class="auth-form lifecycle-form" method="post" action="group.php?id=<?= (int) $group['id'] ?>">
                        <input type="hidden" name="csrf_token" value="<?= escape_html(csrf_token()) ?>">
                        <input type="hidden" name="action" value="restart_cycle">
                        <button class="button" type="submit">Start another cycle</button>
                    </form>
                    <form class="lifecycle-form" method="post" action="group.php?id=<?= (int) $group['id'] ?>">
                        <input type="hidden" name="csrf_token" value="<?= escape_html(csrf_token()) ?>">
                        <input type="hidden" name="action" value="deactivate_group">
                        <button class="button button-danger" type="submit">Deactivate group</button>
                    </form>
                <?php endif; ?>
            <?php else: ?>
                <p class="message">This group has been deactivated and is no longer accepting reading activity.</p>
            <?php endif; ?>

            <?php if ($creator && (int) $group['cycle_number'] > 0): ?>
                <p class="admin-link-wrap"><a class="admin-link" href="group_reports.php?id=<?= (int) $group['id'] ?>">View member reading reports <span aria-hidden="true">&rarr;</span></a></p>
            <?php endif; ?>

            <?php if ($group['status'] !== 'deactivated'): ?>
                <section class="group-info-section" aria-labelledby="invitation-heading">
                    <h2 id="invitation-heading">Invitation</h2>
                    <?php if ($group['status'] === 'setup'): ?>
                        <p class="section-copy">Share the invitation code or link before the reading cycle starts.</p>
                        <div class="invite-code"><?= escape_html($group['invite_code']) ?></div>
                        <button
                            class="button copy-invite-button"
                            type="button"
                            data-copy-text="<?= escape_html($invitationUrl) ?>"
                        >Copy invitation link</button>
                        <p class="copy-status" role="status" aria-live="polite"></p>
                        <a class="invite-link" href="<?= escape_html($invitationUrl) ?>"><?= escape_html($invitationUrl) ?></a>
                    <?php else: ?>
                        <p class="section-copy">Membership and daily counts are locked for this cycle.</p>
                    <?php endif; ?>
                </section>
            <?php endif; ?>
        <?php endif; ?>
    </main>
</body>
</html>
