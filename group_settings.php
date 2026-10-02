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
$error = '';
$notice = '';

if ($groupId === false || $groupId === null) {
    http_response_code(404);
    $error = 'این گروه پیدا نشد.';
} else {
    try {
        $connection = database();
        ensure_group_schema($connection);
        synchronize_group_cycle($connection, $groupId);
        begin_group_cycle_if_ready($connection, $groupId);

        $groupQuery = $connection->prepare(
            'SELECT `groups`.id, `groups`.name, `groups`.invite_code,
                    `groups`.created_by, `groups`.status, `groups`.cycle_number
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
        } elseif ($group['status'] !== 'setup') {
            http_response_code(403);
            $error = 'تنظیمات گروه و پیوند دعوت پس از آغاز دوره در دسترس نیستند.';
        }
    } catch (PDOException $exception) {
        error_log($exception->getMessage());
        http_response_code(503);
        $error = 'تنظیمات گروه در دسترس نیست. تنظیمات پایگاه داده را بررسی و دوباره تلاش کنید.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $group && $error === '') {
    if (!is_valid_csrf_token($_POST['csrf_token'] ?? null)) {
        $error = 'نشست شما منقضی شده است. صفحه را تازه‌سازی کنید و دوباره تلاش کنید.';
    } elseif ((int) $group['created_by'] !== $accountId) {
        http_response_code(403);
        $error = 'فقط سازنده گروه می‌تواند نام آن را تغییر دهد.';
    } else {
        $submittedName = $_POST['name'] ?? '';
        $newName = is_string($submittedName) ? $submittedName : '';
        try {
            $result = rename_group($connection, $groupId, $accountId, $newName);
            if (isset($result['error'])) {
                $error = $result['error'];
            } else {
                header('Location: group_settings.php?id=' . $groupId . '&renamed=1');
                exit;
            }
        } catch (PDOException $exception) {
            error_log($exception->getMessage());
            $error = 'تغییر نام گروه ممکن نشد. لطفاً دوباره تلاش کنید.';
        }
    }
}

if ($group && isset($_GET['renamed'])) {
    $notice = 'نام گروه تغییر کرد.';
}

$creator = $group && (int) $group['created_by'] === $accountId;
$invitationUrl = $group ? group_invitation_url($group['invite_code']) : '';
$backGroupId = $group ? (int) $group['id'] : (is_int($groupId) ? $groupId : 0);
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#315c4b">
    <title><?= $group ? 'تنظیمات ' . escape_html($group['name']) : 'تنظیمات گروه' ?> | باهم بخوانیم</title>
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="icon" href="assets/icons/icon-192.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/css/app.css">
    <script src="assets/js/app.js" defer></script>
</head>
<body>
    <main class="welcome auth-card group-page">
        <a class="back-link" href="group.php?id=<?= $backGroupId ?>">&rarr; بازگشت به گروه</a>

        <?php if ($error !== ''): ?>
            <p class="message message-error" role="alert"><?= escape_html($error) ?></p>
        <?php endif; ?>
        <?php if ($notice !== ''): ?>
            <p class="message message-success" role="status"><?= escape_html($notice) ?></p>
        <?php endif; ?>

        <?php if ($group && $error === ''): ?>
            <p class="eyebrow">تنظیمات گروه</p>
            <h1><?= escape_html($group['name']) ?></h1>

            <?php if ($creator && (int) $group['cycle_number'] === 0): ?>
                <section class="group-info-section" aria-labelledby="rename-heading">
                    <h2 id="rename-heading">تغییر نام گروه</h2>
                    <form class="auth-form" method="post" action="group_settings.php?id=<?= (int) $group['id'] ?>">
                        <input type="hidden" name="csrf_token" value="<?= escape_html(csrf_token()) ?>">
                        <label for="group-name">نام گروه</label>
                        <input
                            id="group-name"
                            name="name"
                            type="text"
                            maxlength="100"
                            value="<?= escape_html($group['name']) ?>"
                            required
                        >
                        <button class="button" type="submit">ذخیره نام گروه</button>
                    </form>
                </section>
            <?php endif; ?>

            <section class="group-info-section" aria-labelledby="invitation-heading">
                <h2 id="invitation-heading">پیوند دعوت</h2>
                <p class="section-copy">پیش از آغاز دوره کتاب‌خوانی، کد یا پیوند دعوت را به اشتراک بگذارید.</p>
                <div class="invite-code"><?= escape_html($group['invite_code']) ?></div>
                <button
                    class="button copy-invite-button"
                    type="button"
                    data-copy-text="<?= escape_html($invitationUrl) ?>"
                >کپی پیوند دعوت</button>
                <p class="copy-status" role="status" aria-live="polite"></p>
                <a class="invite-link" href="<?= escape_html($invitationUrl) ?>"><?= escape_html($invitationUrl) ?></a>
            </section>
        <?php endif; ?>
    </main>
</body>
</html>
