<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/groups.php';

if (empty($_SESSION['account_id'])) {
    header('Location: login.php');
    exit;
}

try {
    $connection = database();
    ensure_group_schema($connection);
    $statement = $connection->prepare(
        'SELECT accounts.display_name, roles.name AS role
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
$hasDisplayName = is_string($account['display_name']) && $account['display_name'] !== '';

try {
    $groupStatement = $connection->prepare(
        'SELECT `groups`.id, `groups`.name, `groups`.invite_code,
                COUNT(group_members.account_id) AS member_count
         FROM `groups`
         INNER JOIN group_members ON group_members.group_id = `groups`.id
         WHERE group_members.account_id = :account_id AND `groups`.status <> \'deactivated\'
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
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#315c4b">
    <meta name="description" content="باهم بخوانیم و داستان‌ها را به اشتراک بگذاریم.">
    <title>باهم بخوانیم</title>
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="icon" href="assets/icons/icon-192.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/css/app.css">
    <script src="assets/js/app.js" defer></script>
</head>
<body>
    <main class="home-page">
        <header class="page-header">
            <div>
                <p class="eyebrow">جایی برای داستان‌ها</p>
                <h1>باهم بخوانیم</h1>
                <p class="intro">واردشده با نام <?= escape_html($hasDisplayName ? $account['display_name'] : 'خواننده') ?></p>
                <a class="profile-link" href="profile.php">
                    <?= $hasDisplayName ? 'ویرایش نام نمایشی' : 'انتخاب نام نمایشی' ?>
                </a>
            </div>
            <form action="logout.php" method="post">
                <input type="hidden" name="csrf_token" value="<?= escape_html(csrf_token()) ?>">
                <button class="button button-secondary" type="submit">خروج</button>
            </form>
        </header>

        <?php if ($account['role'] === 'Admin'): ?>
            <p class="admin-link-wrap"><a class="admin-link" href="admin/chapters.php">مدیریت فصل‌های کتاب <span aria-hidden="true">&larr;</span></a></p>
        <?php endif; ?>

        <?php if (!empty($groupsUnavailable)): ?>
            <p class="message message-error" role="alert">گروه‌ها موقتاً در دسترس نیستند. دسترسی پایگاه داده برای ایجاد جدول‌ها را بررسی و دوباره تلاش کنید.</p>
        <?php endif; ?>

        <section class="home-section" aria-labelledby="groups-heading">
            <div class="section-heading">
                <div>
                    <p class="eyebrow">جمع‌های کتاب‌خوانی شما</p>
                    <h2 id="groups-heading">گروه‌های شما</h2>
                </div>
                <span class="group-total"><?= count($groups) ?></span>
            </div>

            <?php if ($groups === []): ?>
                <p class="empty-state">هنوز عضو گروهی نشده‌اید. یک گروه بسازید یا با کد دعوت به گروهی بپیوندید.</p>
            <?php else: ?>
                <div class="group-grid">
                    <?php foreach ($groups as $group): ?>
                        <a class="group-card" href="group.php?id=<?= (int) $group['id'] ?>">
                            <span class="group-card-mark" aria-hidden="true">R</span>
                            <span class="group-card-content">
                                <strong><?= escape_html($group['name']) ?></strong>
                                <span><?= (int) $group['member_count'] ?> <?= (int) $group['member_count'] === 1 ? 'عضو' : 'عضو' ?></span>
                            </span>
                            <span class="group-card-arrow" aria-hidden="true">&larr;</span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="action-grid" aria-label="اقدام‌های گروه">
            <a class="action-card action-card-create" href="create_group.php">
                <span class="action-icon" aria-hidden="true">+</span>
                <span class="action-copy">
                    <strong>ساخت گروه</strong>
                    <span>گروه تازه‌ای بسازید و دیگران را دعوت کنید.</span>
                </span>
                <span class="group-card-arrow" aria-hidden="true">&larr;</span>
            </a>

            <div class="action-card action-card-join">
                <span class="action-icon" aria-hidden="true">&#8599;</span>
                <span class="action-copy">
                    <strong>پیوستن به گروه</strong>
                    <span>با کد دعوت یا پیوند به گروه بپیوندید.</span>
                </span>
                <form class="join-inline-form" action="join.php" method="get">
                    <label class="visually-hidden" for="home-invite-code">کد دعوت</label>
                    <input id="home-invite-code" name="code" type="text" pattern="[A-Fa-f0-9]{12}" maxlength="12" placeholder="کد ۱۲ کاراکتری" required>
                    <button class="button" type="submit">پیوستن</button>
                </form>
            </div>
        </section>
    </main>
</body>
</html>
