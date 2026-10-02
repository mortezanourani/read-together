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
    $error = 'این گروه پیدا نشد.';
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
            $error = 'این گروه پیدا نشد.';
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
        $error = 'گروه‌ها یا برنامه خواندن در دسترس نیستند. تنظیمات و مجوزهای پایگاه داده را بررسی کنید.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $group && $error === '') {
    if (!is_valid_csrf_token($_POST['csrf_token'] ?? null)) {
        $error = 'نشست شما منقضی شده است. صفحه را تازه‌سازی کنید و دوباره تلاش کنید.';
    } else {
        $action = $_POST['action'] ?? '';

        try {
            if ($action === 'set_daily_count') {
                $submittedCount = $_POST['daily_chapter_count'] ?? null;
                $dailyCount = is_string($submittedCount)
                    ? filter_var($submittedCount, FILTER_VALIDATE_INT)
                    : false;
                if ($dailyCount === false || $dailyCount < 1 || $dailyCount > 120) {
                    $error = 'تعداد فصل‌های روزانه را بین ۱ تا ۱۲۰ انتخاب کنید.';
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
                    $error = 'ثبت گزارش خواندن فقط در دوره فعال امکان‌پذیر است.';
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
                        $error = 'این فصل برای امروز به شما اختصاص داده نشده است.';
                    } elseif ($assignment['status'] === 'read') {
                        $error = 'گزارش خواندن این فصل را قبلاً ثبت کرده‌اید.';
                    } elseif ($assignment['assignment_date'] !== gmdate('Y-m-d')) {
                        $error = 'روز تغییر کرده است. برای دیدن فصل‌های امروز صفحه را تازه‌سازی کنید.';
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
                $error = 'این عملیات برای گروه در دسترس نیست.';
            }
        } catch (PDOException $exception) {
            error_log($exception->getMessage());
            $error = $exception->getCode() === '23000'
                ? 'گزارش خواندن این فصل قبلاً ثبت شده است.'
                : 'انجام این عملیات ممکن نشد. لطفاً دوباره تلاش کنید.';
        } catch (Throwable $exception) {
            error_log($exception->getMessage());
            $error = 'انجام این عملیات ممکن نشد. لطفاً دوباره تلاش کنید.';
        }
    }
}

if ($group) {
    if (isset($_GET['started'])) {
        $notice = 'دوره ۱۲۰ روزه کتاب‌خوانی آغاز شد.';
    } elseif (isset($_GET['count_saved'])) {
        $notice = 'تعداد روزانه فصل‌های شما ذخیره شد.';
    } elseif (isset($_GET['reported'])) {
        $notice = 'گزارش خواندن شما ثبت شد.';
    } elseif (isset($_GET['restarted'])) {
        $notice = 'گروه آماده تنظیم دوره کتاب‌خوانی بعدی است.';
    } elseif (isset($_GET['created'])) {
        $notice = 'گروه شما آماده است. برای شروع کتاب‌خوانی، تعداد فصل‌های روزانه را مشخص کنید.';
    } elseif (isset($_GET['joined'])) {
        $notice = 'به گروه پیوستید.';
    }
}

$myTodayAssignments = array_values(array_filter(
    $todayAssignments,
    static function (array $assignment) use ($accountId): bool {
        return (int) $assignment['account_id'] === $accountId;
    }
));
$unreadAssignments = array_values(array_filter(
    $myTodayAssignments,
    static function (array $assignment): bool {
        return $assignment['status'] !== 'read';
    }
));
$readAssignments = array_values(array_filter(
    $myTodayAssignments,
    static function (array $assignment): bool {
        return $assignment['status'] === 'read';
    }
));
$myTodayAssignments = array_merge($unreadAssignments, $readAssignments);
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#315c4b">
    <title><?= $group ? escape_html($group['name']) . ' | باهم بخوانیم' : 'گروه | باهم بخوانیم' ?></title>
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="icon" href="assets/icons/icon-192.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/css/app.css">
    <script src="assets/js/app.js" defer></script>
</head>
<body>
    <main class="welcome auth-card group-page">
        <a class="back-link" href="index.php">&rarr; بازگشت به گروه‌های شما</a>

        <?php if ($error !== ''): ?>
            <p class="message message-error" role="alert"><?= escape_html($error) ?></p>
        <?php endif; ?>
        <?php if ($notice !== ''): ?>
            <p class="message message-success" role="status"><?= escape_html($notice) ?></p>
        <?php endif; ?>

        <?php if ($group): ?>
            <p class="eyebrow">جمع کتاب‌خوانی شما</p>
            <h1><?= escape_html($group['name']) ?></h1>
            <?php if ($group['status'] === 'setup'): ?>
                <a class="group-settings-link" href="group_settings.php?id=<?= (int) $group['id'] ?>">تغییر نام و لینک دعوت</a>
            <?php endif; ?>

            <?php if ($group['status'] === 'setup'): ?>
                <section class="group-info-section" aria-labelledby="plan-heading">
                    <h2 id="plan-heading">برنامه خواندن روزانه‌تان را تنظیم کنید</h2>
                    <p class="section-copy">
                        تعداد فصل‌هایی را که می‌توانید هر روز بخوانید انتخاب کنید. گروه زمانی آغاز می‌شود که دست‌کم دو عضو داشته باشد، هر ۱۲۰ فصل تعریف شده باشند و مجموع تعداد فصل‌های اعضا دقیقاً ۱۲۰ باشد.
                    </p>
                    <p class="quota-total">
                        مجموع گروه: <strong><?= $dailyTotal ?> از ۱۲۰ فصل در روز</strong>
                        <span><?= max(0, 120 - $dailyTotal) ?> فصل باقی‌مانده</span>
                    </p>
                    <?php if ($chaptersAvailable !== 120): ?>
                        <p class="message">مدیر <?= $chaptersAvailable ?> فصل از ۱۲۰ فصل را تعریف کرده است. پس از آماده‌شدن همه فصل‌ها، برنامه آغاز می‌شود.</p>
                    <?php endif; ?>

                    <form class="auth-form quota-form" method="post" action="group.php?id=<?= (int) $group['id'] ?>">
                        <input type="hidden" name="csrf_token" value="<?= escape_html(csrf_token()) ?>">
                        <input type="hidden" name="action" value="set_daily_count">
                        <label for="daily_chapter_count">تعداد فصل‌هایی که می‌خواهید هر روز بخوانید</label>
                        <input
                            id="daily_chapter_count"
                            name="daily_chapter_count"
                            type="number"
                            min="1"
                            max="120"
                            value="<?= $currentMemberCount === null ? '' : $currentMemberCount ?>"
                            required
                        >
                        <button class="button" type="submit">ذخیره تعداد روزانه</button>
                    </form>
                </section>

                <section class="group-info-section" aria-labelledby="member-counts-heading">
                    <div class="section-heading">
                        <h2 id="member-counts-heading">اعضا</h2>
                        <span class="group-total"><?= count($members) ?></span>
                    </div>
                    <ul class="member-list">
                        <?php foreach ($members as $member): ?>
                            <li>
                                <span><?= escape_html($member['display_name']) ?></span>
                                <span class="member-quota">
                                    <?= $member['daily_chapter_count'] === null
                                        ? 'تعیین‌نشده'
                                        : (int) $member['daily_chapter_count'] . ' فصل در روز' ?>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </section>
            <?php elseif ($group['status'] === 'active'): ?>
                <?php $cycleDay = group_cycle_day($group, gmdate('Y-m-d')); ?>
                <p class="cycle-summary">
                    روز <?= $cycleDay === null ? '—' : $cycleDay + 1 ?> از ۱۲۰
                    <span>آغاز دوره:
                        <time data-solar-date="<?= escape_html($group['assignments_start_date']) ?>" datetime="<?= escape_html($group['assignments_start_date']) ?>"><?= escape_html($group['assignments_start_date']) ?></time>
                    </span>
                </p>

                <section class="group-info-section" aria-labelledby="today-heading">
                    <div class="section-heading">
                        <h2 id="today-heading">فصل‌های امروز شما</h2>
                        <span class="group-total"><?= count($myTodayAssignments) ?></span>
                    </div>
                    <?php if ($myTodayAssignments === []): ?>
                        <p class="empty-state">امروز فصلی به شما اختصاص داده نشده است.</p>
                    <?php else: ?>
                        <div class="assigned-chapters">
                            <?php foreach ($myTodayAssignments as $assignment): ?>
                                <article class="assigned-chapter">
                                    <?php if ($assignment['status'] === 'read'): ?>
                                        <p class="report-complete">خوانده شد</p>
                                    <?php else: ?>
                                        <form class="chapter-read-form" method="post" action="group.php?id=<?= (int) $group['id'] ?>">
                                            <input type="hidden" name="csrf_token" value="<?= escape_html(csrf_token()) ?>">
                                            <input type="hidden" name="action" value="submit_read">
                                            <input type="hidden" name="chapter_id" value="<?= (int) $assignment['chapter']['id'] ?>">
                                            <button class="button button-compact" type="submit">خواندم</button>
                                        </form>
                                    <?php endif; ?>
                                    <h3><?= escape_html($assignment['chapter']['title']) ?></h3>
                                    <p><?= nl2br(escape_html($assignment['chapter']['description'])) ?></p>
                                    <p class="chapter-boundary"><strong>از </strong> <?= nl2br(escape_html($assignment['chapter']['start_sentence'])) ?></p>
                                    <p class="chapter-boundary"><strong>تا </strong> <?= nl2br(escape_html($assignment['chapter']['end_sentence'])) ?></p>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>

            <?php elseif ($group['status'] === 'completed'): ?>
                <p class="message">دوره ۱۲۰ روزه کتاب‌خوانی این گروه به پایان رسیده است. سازنده می‌تواند دوره دیگری آغاز کند یا گروه را غیرفعال کند.</p>
                <?php if ($creator): ?>
                    <form class="auth-form lifecycle-form" method="post" action="group.php?id=<?= (int) $group['id'] ?>">
                        <input type="hidden" name="csrf_token" value="<?= escape_html(csrf_token()) ?>">
                        <input type="hidden" name="action" value="restart_cycle">
                        <button class="button" type="submit">آغاز دوره جدید</button>
                    </form>
                    <form class="lifecycle-form" method="post" action="group.php?id=<?= (int) $group['id'] ?>">
                        <input type="hidden" name="csrf_token" value="<?= escape_html(csrf_token()) ?>">
                        <input type="hidden" name="action" value="deactivate_group">
                        <button class="button button-danger" type="submit">غیرفعال‌کردن گروه</button>
                    </form>
                <?php endif; ?>
            <?php else: ?>
                <p class="message">این گروه غیرفعال شده است و فعالیت کتاب‌خوانی در آن امکان‌پذیر نیست.</p>
            <?php endif; ?>

            <?php if ($creator && (int) $group['cycle_number'] > 0): ?>
                <p class="admin-link-wrap"><a class="admin-link" href="group_reports.php?id=<?= (int) $group['id'] ?>">مشاهده گزارش خواندن اعضا <span aria-hidden="true">&larr;</span></a></p>
            <?php endif; ?>

        <?php endif; ?>
    </main>
</body>
</html>
