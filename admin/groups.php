<?php
require_once __DIR__ . '/../includes/groups.php';

if (empty($_SESSION['account_id'])) {
    header('Location: ../login.php');
    exit;
}

$error = '';
$notice = '';
$groups = [];
$currentPage = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$currentPage = $currentPage === false ? 1 : $currentPage;
$perPage = 10;
$totalGroups = 0;
$totalPages = 1;
$isAdmin = false;

try {
    $connection = database();
    ensure_group_schema($connection);

    $accountQuery = $connection->prepare(
        'SELECT roles.name AS role
         FROM accounts
         INNER JOIN roles ON roles.id = accounts.role_id
         WHERE accounts.id = :id'
    );
    $accountQuery->execute(['id' => $_SESSION['account_id']]);
    $account = $accountQuery->fetch();
    $isAdmin = $account && $account['role'] === 'Admin';

    if (!$isAdmin) {
        http_response_code(403);
        $error = 'فقط مدیر سامانه می‌تواند همه گروه‌ها را مدیریت کند.';
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!is_valid_csrf_token($_POST['csrf_token'] ?? null)) {
            $error = 'نشست شما منقضی شده است. صفحه را تازه‌سازی کنید و دوباره تلاش کنید.';
        } else {
            $groupId = filter_var($_POST['group_id'] ?? null, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1],
            ]);
            $action = $_POST['action'] ?? '';

            if ($groupId === false || $groupId === null
                || !in_array($action, ['deactivate', 'delete'], true)) {
                $error = 'درخواست مدیریت گروه معتبر نیست.';
            } else {
                $connection->beginTransaction();
                try {
                    $lockGroup = $connection->prepare(
                        'SELECT id, status FROM `groups` WHERE id = :id FOR UPDATE'
                    );
                    $lockGroup->execute(['id' => $groupId]);
                    $groupToManage = $lockGroup->fetch();

                    if (!$groupToManage) {
                        $error = 'این گروه پیدا نشد.';
                    } elseif ($action === 'deactivate') {
                        if ($groupToManage['status'] === 'deactivated') {
                            $error = 'این گروه از قبل غیرفعال شده است.';
                        } else {
                            $deactivate = $connection->prepare(
                                "UPDATE `groups`
                                 SET status = 'deactivated', deactivated_at = UTC_TIMESTAMP()
                                 WHERE id = :id"
                            );
                            $deactivate->execute(['id' => $groupId]);
                            $notice = 'گروه غیرفعال شد.';
                        }
                    } else {
                        $delete = $connection->prepare(
                            'DELETE FROM `groups` WHERE id = :id'
                        );
                        $delete->execute(['id' => $groupId]);
                        $notice = 'گروه و اطلاعات وابسته آن برای همیشه حذف شد.';
                    }
                    $connection->commit();
                } catch (Throwable $exception) {
                    if ($connection->inTransaction()) {
                        $connection->rollBack();
                    }
                    throw $exception;
                }
            }
        }
    }

    if ($isAdmin) {
        $totalGroups = (int) $connection->query(
            'SELECT COUNT(*) FROM `groups`'
        )->fetchColumn();
        $totalPages = max(1, (int) ceil($totalGroups / $perPage));
        $currentPage = min($currentPage, $totalPages);
        $offset = ($currentPage - 1) * $perPage;
        $groupQuery = $connection->prepare(
            'SELECT `groups`.id, `groups`.name, `groups`.status, `groups`.created_at,
                    accounts.display_name AS creator_name, `groups`.created_by,
                    COUNT(group_members.account_id) AS member_count
             FROM `groups`
             INNER JOIN accounts ON accounts.id = `groups`.created_by
             LEFT JOIN group_members ON group_members.group_id = `groups`.id
             GROUP BY `groups`.id, `groups`.name, `groups`.status, `groups`.created_at,
                      accounts.display_name, `groups`.created_by
             ORDER BY `groups`.created_at DESC, `groups`.id DESC
             LIMIT :limit OFFSET :offset'
        );
        $groupQuery->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $groupQuery->bindValue(':offset', $offset, PDO::PARAM_INT);
        $groupQuery->execute();
        $groups = $groupQuery->fetchAll();
    }
} catch (PDOException $exception) {
    if (isset($connection) && $connection->inTransaction()) {
        $connection->rollBack();
    }
    error_log($exception->getMessage());
    http_response_code(503);
    $error = 'فهرست گروه‌ها در دسترس نیست. تنظیمات پایگاه داده را بررسی و دوباره تلاش کنید.';
}

$statusLabels = [
    'setup' => 'در حال آماده‌سازی',
    'active' => 'فعال',
    'completed' => 'تکمیل‌شده',
    'deactivated' => 'غیرفعال',
];
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#315c4b">
    <title>مدیریت گروه‌ها | باهم بخوانیم</title>
    <link rel="manifest" href="../manifest.webmanifest">
    <link rel="icon" href="../assets/icons/icon-192.svg" type="image/svg+xml">
    <link rel="stylesheet" href="../assets/css/app.css">
    <script src="../assets/js/app.js" defer></script>
</head>
<body>
    <main class="home-page chapter-admin">
        <a class="back-link" href="../profile.php">&rarr; بازگشت به نمایه</a>
        <header class="page-header">
            <div>
                <p class="eyebrow">ابزارهای مدیر</p>
                <h1>همه گروه‌ها</h1>
                <p class="intro">تعداد کل گروه‌ها: <?= $totalGroups ?></p>
            </div>
        </header>

        <?php if ($error !== ''): ?>
            <p class="message message-error" role="alert"><?= escape_html($error) ?></p>
        <?php elseif ($notice !== ''): ?>
            <p class="message message-success" role="status"><?= escape_html($notice) ?></p>
        <?php endif; ?>

        <?php if ($isAdmin && $error === ''): ?>
            <?php if ($groups === []): ?>
                <p class="empty-state">هنوز گروهی ساخته نشده است.</p>
            <?php else: ?>
                <section class="admin-groups-list" aria-label="فهرست همه گروه‌ها">
                    <?php foreach ($groups as $group): ?>
                        <article class="admin-group-card">
                            <div>
                                <h2><?= escape_html($group['name']) ?></h2>
                                <p class="admin-group-meta">
                                    سازنده: <?= escape_html($group['creator_name'] ?: 'خواننده') ?>
                                    · <?= (int) $group['member_count'] ?> عضو
                                    · وضعیت: <?= escape_html($statusLabels[$group['status']] ?? $group['status']) ?>
                                    · ایجادشده: <time data-solar-date="<?= escape_html($group['created_at']) ?>" datetime="<?= escape_html(str_replace(' ', 'T', $group['created_at'])) ?>"><?= escape_html($group['created_at']) ?></time>
                                </p>
                            </div>
                            <div class="admin-group-actions">
                                <?php if ($group['status'] !== 'deactivated'): ?>
                                    <form method="post" action="groups.php?page=<?= $currentPage ?>">
                                        <input type="hidden" name="csrf_token" value="<?= escape_html(csrf_token()) ?>">
                                        <input type="hidden" name="group_id" value="<?= (int) $group['id'] ?>">
                                        <input type="hidden" name="action" value="deactivate">
                                        <button class="button button-secondary" type="submit">غیرفعال‌کردن</button>
                                    </form>
                                <?php endif; ?>
                                <form method="post" action="groups.php?page=<?= $currentPage ?>" onsubmit="return confirm('این گروه و تمام اطلاعات وابسته آن برای همیشه حذف می‌شود. ادامه می‌دهید؟')">
                                    <input type="hidden" name="csrf_token" value="<?= escape_html(csrf_token()) ?>">
                                    <input type="hidden" name="group_id" value="<?= (int) $group['id'] ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <button class="button button-danger" type="submit">حذف دائمی</button>
                                </form>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </section>
                <?php if ($totalPages > 1): ?>
                    <nav class="admin-pagination" aria-label="صفحه‌بندی فهرست گروه‌ها">
                        <?php if ($currentPage > 1): ?>
                            <a href="groups.php?page=<?= $currentPage - 1 ?>">صفحه قبل</a>
                        <?php endif; ?>
                        <span>صفحه <?= $currentPage ?> از <?= $totalPages ?></span>
                        <?php if ($currentPage < $totalPages): ?>
                            <a href="groups.php?page=<?= $currentPage + 1 ?>">صفحه بعد</a>
                        <?php endif; ?>
                    </nav>
                <?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>
    </main>
</body>
</html>
