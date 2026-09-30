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
    $error = 'این گروه پیدا نشد.';
} elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)
    || !valid_iso_date($date)
    || $date > $today) {
    http_response_code(400);
    $error = 'تاریخی معتبر، حداکثر تا امروز، انتخاب کنید.';
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
            $error = 'فقط سازنده این گروه می‌تواند گزارش خواندن اعضا را ببیند.';
        } else {
            $assignments = assignments_for_group_date($connection, $groupId, $date);
        }
    } catch (PDOException $exception) {
        error_log($exception->getMessage());
        http_response_code(503);
        $error = 'گزارش‌های خواندن در دسترس نیستند. تنظیمات و مجوزهای پایگاه داده را بررسی کنید.';
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
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#315c4b">
    <title>گزارش‌های خواندن | باهم بخوانیم</title>
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="icon" href="assets/icons/icon-192.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body>
    <main class="home-page chapter-admin">
        <a class="back-link" href="<?= $group ? 'group.php?id=' . (int) $group['id'] : 'index.php' ?>">&rarr; بازگشت به گروه</a>
        <header class="page-header">
            <div>
                <p class="eyebrow">نمای سازنده گروه</p>
                <h1>گزارش‌های خواندن</h1>
                <?php if ($group): ?>
                    <p class="intro"><?= escape_html($group['name']) ?></p>
                <?php endif; ?>
            </div>
        </header>

        <?php if ($error !== ''): ?>
            <p class="message message-error" role="alert"><?= escape_html($error) ?></p>
        <?php else: ?>
            <section class="report-date-section" aria-labelledby="report-date-heading">
                <h2 id="report-date-heading">انتخاب روز</h2>
                <form class="report-date-form" method="get" action="group_reports.php">
                    <input type="hidden" name="id" value="<?= (int) $group['id'] ?>">
                    <label class="visually-hidden" for="report-date">تاریخ برنامه</label>
                    <input id="report-date" name="date" type="date" value="<?= escape_html($date) ?>" max="<?= escape_html($today) ?>" required>
                    <button class="button" type="submit">نمایش گزارش‌ها</button>
                </form>
            </section>

            <?php if ($assignments === []): ?>
                <p class="message">برای این تاریخ برنامه‌ای وجود ندارد. ممکن است تاریخ پیش از آغاز یک دوره یا خارج از دوره باشد.</p>
            <?php else: ?>
            <section class="report-summary" aria-label="خلاصه گزارش">
                <div><strong><?= $readCount ?></strong><span>خوانده‌شده</span></div>
                <div><strong><?= $missedCount ?></strong><span>ازدست‌رفته</span></div>
                <div><strong><?= $pendingCount ?></strong><span>ثبت‌نشده</span></div>
            </section>

            <p class="report-date-label">
                گزارش‌های تاریخ <?= escape_html($date) ?>.
                <?php if ($date < $today): ?>
                    فصل‌های بدون گزارش، ازدست‌رفته محسوب می‌شوند و گزارش با تأخیر پذیرفته نمی‌شود.
                <?php else: ?>
                    فصل‌های بدون گزارش هنوز در انتظار ثبت هستند.
                <?php endif; ?>
            </p>

            <div class="report-table-wrap">
                <table class="report-table">
                    <thead>
                        <tr>
                            <th scope="col">عضو</th>
                            <th scope="col">فصل</th>
                            <th scope="col">عنوان</th>
                            <th scope="col">وضعیت</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($assignments as $assignment): ?>
                            <tr>
                                <td><?= escape_html($assignment['display_name']) ?></td>
                                <td><?= (int) $assignment['chapter']['chapter_number'] ?></td>
                                <td><?= escape_html($assignment['chapter']['title']) ?></td>
                                <td><span class="report-status report-status-<?= strtolower(str_replace(' ', '-', $assignment['report_status'])) ?>"><?= $assignment['report_status'] === 'Read' ? 'خوانده‌شده' : ($assignment['report_status'] === 'Missed' ? 'ازدست‌رفته' : 'ثبت‌نشده') ?></span></td>
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
