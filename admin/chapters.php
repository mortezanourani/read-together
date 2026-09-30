<?php
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/assignments.php';

if (empty($_SESSION['account_id'])) {
    header('Location: ../login.php');
    exit;
}

$error = '';
try {
    $connection = database();
    $accountQuery = $connection->prepare(
        'SELECT roles.name AS role
         FROM accounts
         INNER JOIN roles ON roles.id = accounts.role_id
         WHERE accounts.id = :id'
    );
    $accountQuery->execute(['id' => $_SESSION['account_id']]);
    $account = $accountQuery->fetch();
} catch (PDOException $exception) {
    error_log($exception->getMessage());
    http_response_code(503);
    exit('پایگاه داده در دسترس نیست. لطفاً بعداً دوباره تلاش کنید.');
}

if (!$account || $account['role'] !== 'Admin') {
    http_response_code(403);
    exit('شما اجازه مدیریت فصل‌ها را ندارید.');
}

try {
    ensure_group_schema($connection);
} catch (PDOException $exception) {
    error_log($exception->getMessage());
    http_response_code(503);
    $error = 'جدول فصل‌ها در دسترس نیست. مجوزهای پایگاه داده را بررسی و دوباره تلاش کنید.';
}

$values = [
    'chapter_number' => '',
    'title' => '',
    'description' => '',
    'start_sentence' => '',
    'end_sentence' => '',
];
$editId = null;
$editingChapter = false;
$requestedEditId = filter_var($_GET['edit'] ?? null, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);

if (isset($_GET['edit']) && ($requestedEditId === false || $requestedEditId === null)) {
    http_response_code(404);
    $error = 'این فصل پیدا نشد.';
}

if ($error === '' && $requestedEditId !== false && $requestedEditId !== null) {
    $editQuery = $connection->prepare(
        'SELECT id, chapter_number, title, description, start_sentence, end_sentence
         FROM chapters WHERE id = :id'
    );
    $editQuery->execute(['id' => $requestedEditId]);
    $editingChapter = $editQuery->fetch();

    if ($editingChapter) {
        $editId = (int) $editingChapter['id'];
        foreach ($values as $field => $unused) {
            $values[$field] = (string) $editingChapter[$field];
        }
    } else {
        http_response_code(404);
        $error = 'این فصل پیدا نشد.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error === '') {
    if (!is_valid_csrf_token($_POST['csrf_token'] ?? null)) {
        $error = 'نشست شما منقضی شده است. صفحه را تازه‌سازی کنید و دوباره تلاش کنید.';
    } else {
        $submitted = [];
        foreach ($values as $field => $unused) {
            $value = $_POST[$field] ?? '';
            $submitted[$field] = is_string($value) ? trim($value) : '';
        }
        $values = $submitted;
        $postedEditId = filter_var($_POST['chapter_id'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        $hasChapterId = array_key_exists('chapter_id', $_POST);
        $editing = $postedEditId !== false && $postedEditId !== null;
        $chapterNumber = preg_match('/^\d{1,3}$/D', $values['chapter_number'])
            ? (int) $values['chapter_number']
            : 0;
        $titleLength = preg_match_all('/./us', $values['title']);

        if ($hasChapterId && !$editing) {
            $error = 'شناسه فصل برای ویرایش معتبر نیست.';
        } elseif ($chapterNumber < 1 || $chapterNumber > 120) {
            $error = 'شماره فصل باید بین ۱ تا ۱۲۰ باشد.';
        } elseif ($titleLength === false || $titleLength < 1 || $titleLength > 255) {
            $error = 'عنوانی با حداکثر ۲۵۵ نویسه وارد کنید.';
        } elseif ($values['description'] === ''
            || $values['start_sentence'] === ''
            || $values['end_sentence'] === '') {
            $error = 'توضیحات، جمله آغازین و جمله پایانی را وارد کنید.';
        } elseif (strlen($values['description']) > 65535
            || strlen($values['start_sentence']) > 65535
            || strlen($values['end_sentence']) > 65535) {
            $error = 'حجم هرکدام از فیلدهای توضیحات، جمله آغازین و جمله پایانی باید حداکثر ۶۵٬۵۳۵ بایت باشد.';
        } else {
            try {
                if ($editing) {
                    $existing = $connection->prepare('SELECT id FROM chapters WHERE id = :id');
                    $existing->execute(['id' => $postedEditId]);
                    if (!$existing->fetchColumn()) {
                        $error = 'این فصل پیدا نشد. صفحه را تازه‌سازی کنید و دوباره تلاش کنید.';
                    } else {
                        $saveChapter = $connection->prepare(
                            'UPDATE chapters
                             SET chapter_number = :chapter_number,
                                 title = :title,
                                 description = :description,
                                 start_sentence = :start_sentence,
                                 end_sentence = :end_sentence
                             WHERE id = :id'
                        );
                        $saveChapter->execute([
                            'chapter_number' => $chapterNumber,
                            'title' => $values['title'],
                            'description' => $values['description'],
                            'start_sentence' => $values['start_sentence'],
                            'end_sentence' => $values['end_sentence'],
                            'id' => $postedEditId,
                        ]);
                    }
                } else {
                    $saveChapter = $connection->prepare(
                        'INSERT INTO chapters
                            (chapter_number, title, description, start_sentence, end_sentence, created_by)
                         VALUES
                            (:chapter_number, :title, :description, :start_sentence, :end_sentence, :created_by)'
                    );
                    $saveChapter->execute([
                        'chapter_number' => $chapterNumber,
                        'title' => $values['title'],
                        'description' => $values['description'],
                        'start_sentence' => $values['start_sentence'],
                        'end_sentence' => $values['end_sentence'],
                        'created_by' => $_SESSION['account_id'],
                    ]);
                }

                if ($error === '') {
                    start_ready_group_cycles($connection);
                    header('Location: chapters.php?saved=1');
                    exit;
                }
            } catch (PDOException $exception) {
                error_log($exception->getMessage());
                if ($exception->getCode() === '23000') {
                    $error = 'این شماره فصل قبلاً استفاده شده است.';
                } else {
                    $error = 'ذخیره فصل ممکن نشد. لطفاً دوباره تلاش کنید.';
                }
            }
        }

        if ($editing && $postedEditId !== false && $postedEditId !== null) {
            $editId = $postedEditId;
            $editingChapter = true;
        }
    }
}

try {
    $chapters = $error !== '' && !chapter_schema_is_installed($connection)
        ? []
        : $connection->query(
            'SELECT id, chapter_number, title, updated_at
             FROM chapters ORDER BY chapter_number'
        )->fetchAll();
} catch (PDOException $exception) {
    error_log($exception->getMessage());
    $chapters = [];
    if ($error === '') {
        http_response_code(503);
        $error = 'بارگذاری فهرست فصل‌ها ممکن نشد.';
    }
}
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#315c4b">
    <title>مدیریت فصل‌ها | باهم بخوانیم</title>
    <link rel="manifest" href="../manifest.webmanifest">
    <link rel="icon" href="../assets/icons/icon-192.svg" type="image/svg+xml">
    <link rel="stylesheet" href="../assets/css/app.css">
    <script src="../assets/js/app.js" defer></script>
</head>
<body>
    <main class="home-page chapter-admin">
        <a class="back-link" href="../index.php">&rarr; بازگشت به باهم بخوانیم</a>
        <header class="page-header">
            <div>
                <p class="eyebrow">ابزارهای مدیر</p>
                <h1>فصل‌های کتاب</h1>
                <p class="intro">اطلاعات فصل‌ها را اضافه یا ویرایش کنید. شماره فصل‌ها از ۱ تا ۱۲۰ است.</p>
            </div>
            <span class="group-total"><?= count($chapters) ?> / 120</span>
        </header>

        <?php if (isset($_GET['saved'])): ?>
            <p class="message message-success" role="status">فصل ذخیره شد.</p>
        <?php endif; ?>
        <?php if ($error !== ''): ?>
            <p class="message message-error" role="alert"><?= escape_html($error) ?></p>
        <?php endif; ?>

        <section class="chapter-form-section" aria-labelledby="chapter-form-heading">
            <h2 id="chapter-form-heading"><?= $editingChapter ? 'ویرایش فصل' : 'افزودن فصل' ?></h2>
            <form class="auth-form chapter-form" method="post" action="chapters.php<?= $editId ? '?edit=' . (int) $editId : '' ?>">
                <input type="hidden" name="csrf_token" value="<?= escape_html(csrf_token()) ?>">
                <?php if ($editId): ?>
                    <input type="hidden" name="chapter_id" value="<?= (int) $editId ?>">
                <?php endif; ?>

                <label for="chapter_number">شماره فصل</label>
                <input id="chapter_number" name="chapter_number" type="number" min="1" max="120" value="<?= escape_html($values['chapter_number']) ?>" required>

                <label for="title">عنوان</label>
                <input id="title" name="title" type="text" maxlength="255" value="<?= escape_html($values['title']) ?>" required>

                <label for="description">توضیحات</label>
                <textarea id="description" name="description" rows="4" required><?= escape_html($values['description']) ?></textarea>

                <label for="start_sentence">جمله آغازین</label>
                <textarea id="start_sentence" name="start_sentence" rows="3" required><?= escape_html($values['start_sentence']) ?></textarea>

                <label for="end_sentence">جمله پایانی</label>
                <textarea id="end_sentence" name="end_sentence" rows="3" required><?= escape_html($values['end_sentence']) ?></textarea>

                <div class="chapter-form-actions">
                    <button class="button" type="submit"><?= $editingChapter ? 'ذخیره تغییرات' : 'افزودن فصل' ?></button>
                    <?php if ($editingChapter): ?>
                        <a class="cancel-link" href="chapters.php">لغو ویرایش</a>
                    <?php endif; ?>
                </div>
            </form>
        </section>

        <section class="chapter-list-section" aria-labelledby="chapter-list-heading">
            <div class="section-heading">
                <h2 id="chapter-list-heading">فصل‌های تعریف‌شده</h2>
                <span class="group-total"><?= count($chapters) ?></span>
            </div>
            <?php if ($chapters === []): ?>
                <p class="empty-state">هنوز فصلی اضافه نشده است.</p>
            <?php else: ?>
                <ol class="chapter-list">
                    <?php foreach ($chapters as $chapter): ?>
                        <li>
                            <span class="chapter-number"><?= (int) $chapter['chapter_number'] ?></span>
                            <span class="chapter-title"><?= escape_html($chapter['title']) ?></span>
                            <a class="chapter-edit-link" href="chapters.php?edit=<?= (int) $chapter['id'] ?>">ویرایش</a>
                        </li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>
        </section>
    </main>
</body>
</html>
