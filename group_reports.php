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
$date = $today;
$requestedPage = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$currentPage = $requestedPage === false ? 1 : $requestedPage;
$perPage = 10;
$error = '';
$group = false;
$assignments = [];

if ($groupId === false || $groupId === null) {
    http_response_code(404);
    $error = 'این گروه پیدا نشد.';
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
    } else {
        $pendingCount++;
        $assignment['report_status'] = 'Not submitted';
    }
}
unset($assignment);

$unreadAssignments = array_values(array_filter(
    $assignments,
    static function (array $assignment): bool {
        return $assignment['report_status'] !== 'Read';
    }
));
$readAssignments = array_values(array_filter(
    $assignments,
    static function (array $assignment): bool {
        return $assignment['report_status'] === 'Read';
    }
));
$assignments = array_merge($unreadAssignments, $readAssignments);
$totalAssignments = count($assignments);
$totalPages = max(1, (int) ceil($totalAssignments / $perPage));
$currentPage = min($currentPage, $totalPages);
$pageAssignments = array_slice($assignments, ($currentPage - 1) * $perPage, $perPage);
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
    <script src="assets/js/app.js" defer></script>
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
            <?php if ($assignments === []): ?>
                <p class="message">برای امروز برنامه‌ای وجود ندارد. ممکن است دوره هنوز آغاز نشده یا به پایان رسیده باشد.</p>
            <?php else: ?>
            <section class="report-summary" aria-label="خلاصه گزارش">
                <div><span>خوانده‌شده</span><strong><?= $readCount ?></strong></div>
                <div><span>ازدست‌رفته</span><strong><?= $missedCount ?></strong></div>
                <div><span>ثبت‌نشده</span><strong><?= $pendingCount ?></strong></div>
            </section>

            <p class="report-date-label">
                گزارش‌های تاریخ <time data-solar-date="<?= escape_html($date) ?>" datetime="<?= escape_html($date) ?>"><?= escape_html($date) ?></time>.
                فصل‌های بدون گزارش هنوز در انتظار ثبت هستند.
            </p>

            <div class="report-table-wrap">
                <table class="report-table">
                    <thead>
                        <tr>
                            <th scope="col">عضو</th>
                            <th scope="col">عنوان</th>
                            <th scope="col">وضعیت</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pageAssignments as $assignment): ?>
                            <tr>
                                <td><?= escape_html($assignment['display_name']) ?></td>
                                <td><?= escape_html($assignment['chapter']['title']) ?></td>
                                <td><span class="report-status report-status-<?= strtolower(str_replace(' ', '-', $assignment['report_status'])) ?>"><?= $assignment['report_status'] === 'Read' ? 'خوانده‌شده' : ($assignment['report_status'] === 'Missed' ? 'ازدست‌رفته' : 'ثبت‌نشده') ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($totalPages > 1): ?>
                <nav class="admin-pagination" aria-label="صفحه‌بندی گزارش‌ها">
                    <?php if ($currentPage > 1): ?>
                        <a href="group_reports.php?id=<?= (int) $group['id'] ?>&amp;page=<?= $currentPage - 1 ?>">صفحه قبل</a>
                    <?php endif; ?>
                    <span>صفحه <?= $currentPage ?> از <?= $totalPages ?></span>
                    <?php if ($currentPage < $totalPages): ?>
                        <a href="group_reports.php?id=<?= (int) $group['id'] ?>&amp;page=<?= $currentPage + 1 ?>">صفحه بعد</a>
                    <?php endif; ?>
                </nav>
            <?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>
    </main>
</body>
</html>
