<?php
require_once __DIR__ . '/groups.php';

function valid_iso_date(string $date): bool
{
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC'));
    $errors = DateTimeImmutable::getLastErrors();

    return $parsed !== false
        && $parsed->format('Y-m-d') === $date
        && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0));
}

function synchronize_group_cycle(PDO $connection, int $groupId): void
{
    $today = gmdate('Y-m-d');
    $statement = $connection->prepare(
        "UPDATE `groups`
         SET status = 'completed'
         WHERE id = :id AND status = 'active'
           AND DATE_ADD(assignments_start_date, INTERVAL 119 DAY) < :today"
    );
    $statement->execute([
        'id' => $groupId,
        'today' => $today,
    ]);
    if ($statement->rowCount() === 1) {
        $completeCycle = $connection->prepare(
            "UPDATE group_cycles SET status = 'completed'
             WHERE group_id = :id AND status = 'active'"
        );
        $completeCycle->execute(['id' => $groupId]);
    }
}

function begin_group_cycle_if_ready(PDO $connection, int $groupId): bool
{
    $connection->beginTransaction();

    try {
        $lockGroup = $connection->prepare(
            'SELECT status, cycle_number, created_by FROM `groups` WHERE id = :id FOR UPDATE'
        );
        $lockGroup->execute(['id' => $groupId]);
        $group = $lockGroup->fetch();

        if (!$group || $group['status'] !== 'setup') {
            $connection->commit();
            return false;
        }

        $chapterCount = (int) $connection->query(
            'SELECT COUNT(DISTINCT chapter_number) FROM chapters WHERE chapter_number BETWEEN 1 AND 120'
        )->fetchColumn();
        $dailyTotal = $connection->prepare(
            'SELECT COALESCE(SUM(daily_chapter_count), 0)
             FROM group_members WHERE group_id = :group_id'
        );
        $dailyTotal->execute(['group_id' => $groupId]);
        $membersWithoutCount = $connection->prepare(
            'SELECT COUNT(*) FROM group_members
             WHERE group_id = :group_id
               AND (daily_chapter_count IS NULL OR daily_chapter_count < 1)'
        );
        $membersWithoutCount->execute(['group_id' => $groupId]);
        $memberCount = $connection->prepare(
            'SELECT COUNT(*) FROM group_members WHERE group_id = :group_id'
        );
        $memberCount->execute(['group_id' => $groupId]);

        if ($chapterCount !== 120
            || (int) $dailyTotal->fetchColumn() !== 120
            || (int) $membersWithoutCount->fetchColumn() !== 0
            || (int) $memberCount->fetchColumn() < 2) {
            $connection->commit();
            return false;
        }

        $start = $connection->prepare(
            "UPDATE `groups`
             SET status = 'active', assignments_start_date = :start_date,
                 cycle_number = cycle_number + 1
             WHERE id = :id AND status = 'setup'"
        );
        $start->execute([
            'start_date' => gmdate('Y-m-d'),
            'id' => $groupId,
        ]);
        $started = $start->rowCount() === 1;
        if ($started) {
            $cycleNumber = (int) $group['cycle_number'] + 1;
            $createCycle = $connection->prepare(
                "INSERT INTO group_cycles (group_id, cycle_number, starts_on, status)
                 VALUES (:group_id, :cycle_number, :starts_on, 'active')"
            );
            $createCycle->execute([
                'group_id' => $groupId,
                'cycle_number' => $cycleNumber,
                'starts_on' => gmdate('Y-m-d'),
            ]);
            $sourceMembers = $connection->prepare(
                'SELECT account_id, daily_chapter_count FROM group_members
                 WHERE group_id = :group_id
                 ORDER BY (account_id = :creator_id) DESC, joined_at, account_id'
            );
            $sourceMembers->execute([
                'group_id' => $groupId,
                'creator_id' => $group['created_by'],
            ]);
            $snapshotMember = $connection->prepare(
                'INSERT INTO group_cycle_members
                    (group_id, cycle_number, account_id, member_order, daily_chapter_count)
                 VALUES (:group_id, :cycle_number, :account_id, :member_order, :daily_count)'
            );
            foreach ($sourceMembers->fetchAll() as $index => $member) {
                $snapshotMember->execute([
                    'group_id' => $groupId,
                    'cycle_number' => $cycleNumber,
                    'account_id' => $member['account_id'],
                    'member_order' => $index + 1,
                    'daily_count' => $member['daily_chapter_count'],
                ]);
            }
        }
        $connection->commit();

        return $started;
    } catch (Throwable $exception) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        throw $exception;
    }
}

function start_ready_group_cycles(PDO $connection): void
{
    $groupIds = $connection->query(
        "SELECT id FROM `groups` WHERE status = 'setup' ORDER BY id"
    )->fetchAll(PDO::FETCH_COLUMN);

    foreach ($groupIds as $groupId) {
        begin_group_cycle_if_ready($connection, (int) $groupId);
    }
}

function group_cycle_day(array $group, string $assignmentDate): ?int
{
    if (empty($group['assignments_start_date'])) {
        return null;
    }

    $utc = new DateTimeZone('UTC');
    $start = DateTimeImmutable::createFromFormat('!Y-m-d', $group['assignments_start_date'], $utc);
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $assignmentDate, $utc);

    if (!$start || !$date || $date < $start) {
        return null;
    }

    $days = (int) $start->diff($date)->format('%a');
    return $days < 120 ? $days : null;
}

function ensure_active_group_cycle_snapshot(
    PDO $connection,
    int $groupId,
    string $assignmentDate
): void {
    $connection->beginTransaction();

    try {
        $groupQuery = $connection->prepare(
            'SELECT status, created_by, cycle_number, assignments_start_date
             FROM `groups` WHERE id = :id FOR UPDATE'
        );
        $groupQuery->execute(['id' => $groupId]);
        $group = $groupQuery->fetch();
        if (!$group || $group['status'] !== 'active'
            || empty($group['assignments_start_date'])
            || group_cycle_day(
                ['assignments_start_date' => $group['assignments_start_date']],
                $assignmentDate
            ) === null) {
            $connection->commit();
            return;
        }

        $cycleNumber = max(1, (int) $group['cycle_number']);
        $createCycle = $connection->prepare(
            "INSERT IGNORE INTO group_cycles (group_id, cycle_number, starts_on, status)
             VALUES (:group_id, :cycle_number, :starts_on, 'active')"
        );
        $createCycle->execute([
            'group_id' => $groupId,
            'cycle_number' => $cycleNumber,
            'starts_on' => $group['assignments_start_date'],
        ]);

        $memberCountQuery = $connection->prepare(
            'SELECT COUNT(*) FROM group_members WHERE group_id = :group_id'
        );
        $memberCountQuery->execute(['group_id' => $groupId]);
        $memberCount = (int) $memberCountQuery->fetchColumn();
        $snapshotCountQuery = $connection->prepare(
            'SELECT COUNT(*) FROM group_cycle_members
             WHERE group_id = :group_id AND cycle_number = :cycle_number'
        );
        $snapshotCountQuery->execute([
            'group_id' => $groupId,
            'cycle_number' => $cycleNumber,
        ]);
        $snapshotCount = (int) $snapshotCountQuery->fetchColumn();

        if ($snapshotCount !== $memberCount) {
            $membersQuery = $connection->prepare(
                'SELECT account_id, daily_chapter_count
                 FROM group_members
                 WHERE group_id = :group_id
                 ORDER BY (account_id = :creator_id) DESC, joined_at, account_id'
            );
            $membersQuery->execute([
                'group_id' => $groupId,
                'creator_id' => $group['created_by'],
            ]);
            $members = $membersQuery->fetchAll();
            $dailyTotal = 0;
            $hasInvalidCount = false;
            foreach ($members as $member) {
                if ($member['daily_chapter_count'] === null
                    || (int) $member['daily_chapter_count'] < 1) {
                    $hasInvalidCount = true;
                }
                $dailyTotal += (int) $member['daily_chapter_count'];
            }

            if ($memberCount < 2 || count($members) !== $memberCount
                || $dailyTotal !== 120
                || $hasInvalidCount) {
                $connection->commit();
                return;
            }

            $removeSnapshot = $connection->prepare(
                'DELETE FROM group_cycle_members
                 WHERE group_id = :group_id AND cycle_number = :cycle_number'
            );
            $removeSnapshot->execute([
                'group_id' => $groupId,
                'cycle_number' => $cycleNumber,
            ]);
            $insertSnapshot = $connection->prepare(
                'INSERT INTO group_cycle_members
                    (group_id, cycle_number, account_id, member_order, daily_chapter_count)
                 VALUES (:group_id, :cycle_number, :account_id, :member_order, :daily_count)'
            );
            foreach ($members as $index => $member) {
                $insertSnapshot->execute([
                    'group_id' => $groupId,
                    'cycle_number' => $cycleNumber,
                    'account_id' => $member['account_id'],
                    'member_order' => $index + 1,
                    'daily_count' => $member['daily_chapter_count'],
                ]);
            }
        }

        if ((int) $group['cycle_number'] !== $cycleNumber) {
            $updateCycleNumber = $connection->prepare(
                'UPDATE `groups` SET cycle_number = :cycle_number WHERE id = :id'
            );
            $updateCycleNumber->execute([
                'cycle_number' => $cycleNumber,
                'id' => $groupId,
            ]);
        }

        $connection->commit();
    } catch (Throwable $exception) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        throw $exception;
    }
}

function assignments_for_group_date(
    PDO $connection,
    int $groupId,
    string $assignmentDate
): array {
    $cycleQuery = $connection->prepare(
        'SELECT cycle_number, starts_on FROM group_cycles
         WHERE group_id = :group_id
           AND starts_on <= :date_start
           AND DATE_ADD(starts_on, INTERVAL 119 DAY) >= :date_end
         ORDER BY cycle_number DESC LIMIT 1'
    );
    $cycleQuery->execute([
        'group_id' => $groupId,
        'date_start' => $assignmentDate,
        'date_end' => $assignmentDate,
    ]);
    $cycle = $cycleQuery->fetch();
    $useCurrentMembers = false;

    if (!$cycle) {
        $activeGroupQuery = $connection->prepare(
            "SELECT GREATEST(cycle_number, 1) AS cycle_number,
                    assignments_start_date AS starts_on
             FROM `groups`
             WHERE id = :group_id
               AND status IN ('active', 'completed')
               AND assignments_start_date IS NOT NULL
               AND assignments_start_date <= :date_start
               AND DATE_ADD(assignments_start_date, INTERVAL 119 DAY) >= :date_end"
        );
        $activeGroupQuery->execute([
            'group_id' => $groupId,
            'date_start' => $assignmentDate,
            'date_end' => $assignmentDate,
        ]);
        $cycle = $activeGroupQuery->fetch();
        $useCurrentMembers = (bool) $cycle;
    }

    if (!$cycle) {
        return [];
    }

    $currentGroupQuery = $connection->prepare(
        'SELECT status, cycle_number FROM `groups` WHERE id = :group_id'
    );
    $currentGroupQuery->execute(['group_id' => $groupId]);
    $currentGroup = $currentGroupQuery->fetch();
    $isCurrentCycle = $currentGroup
        && in_array($currentGroup['status'], ['active', 'completed'], true)
        && (int) $currentGroup['cycle_number'] === (int) $cycle['cycle_number'];
    $useCurrentMembers = $useCurrentMembers || $isCurrentCycle;

    $day = group_cycle_day(['assignments_start_date' => $cycle['starts_on']], $assignmentDate);
    if ($day === null) {
        return [];
    }

    if (!$useCurrentMembers) {
        $membersQuery = $connection->prepare(
            "SELECT accounts.id AS account_id,
                    COALESCE(NULLIF(accounts.display_name, ''), 'خواننده') AS display_name,
                    group_cycle_members.daily_chapter_count
             FROM group_cycle_members
             INNER JOIN accounts ON accounts.id = group_cycle_members.account_id
             WHERE group_cycle_members.group_id = :group_id
               AND group_cycle_members.cycle_number = :cycle_number
             ORDER BY group_cycle_members.member_order"
        );
        $membersQuery->execute([
            'group_id' => $groupId,
            'cycle_number' => $cycle['cycle_number'],
        ]);
        $members = $membersQuery->fetchAll();
        if ($isCurrentCycle) {
            $memberCountQuery = $connection->prepare(
                'SELECT COUNT(*) FROM group_members WHERE group_id = :group_id'
            );
            $memberCountQuery->execute(['group_id' => $groupId]);
            $useCurrentMembers = $useCurrentMembers
                || count($members) !== (int) $memberCountQuery->fetchColumn();
        }
    }

    if ($useCurrentMembers) {
        $membersQuery = $connection->prepare(
            "SELECT accounts.id AS account_id,
                    COALESCE(NULLIF(accounts.display_name, ''), 'خواننده') AS display_name,
                    group_members.daily_chapter_count
             FROM group_members
             INNER JOIN accounts ON accounts.id = group_members.account_id
             WHERE group_members.group_id = :group_id
             ORDER BY (accounts.id = (
                 SELECT created_by FROM `groups` WHERE id = :group_id_for_creator
             )) DESC, group_members.joined_at, accounts.id"
        );
        $membersQuery->execute([
            'group_id' => $groupId,
            'group_id_for_creator' => $groupId,
        ]);
        $members = $membersQuery->fetchAll();
    }

    if ($members === []) {
        return [];
    }

    $totalDailyChapters = 0;
    foreach ($members as $member) {
        if ($member['daily_chapter_count'] === null
            || (int) $member['daily_chapter_count'] < 1) {
            return [];
        }
        $totalDailyChapters += (int) $member['daily_chapter_count'];
    }
    if ($totalDailyChapters !== 120) {
        return [];
    }

    $chapters = $connection->query(
        'SELECT id, chapter_number, title, description, start_sentence, end_sentence
         FROM chapters WHERE chapter_number BETWEEN 1 AND 120
         ORDER BY chapter_number'
    )->fetchAll();

    if (count($chapters) !== 120) {
        return [];
    }

    $chaptersByNumber = [];
    foreach ($chapters as $chapter) {
        $chaptersByNumber[(int) $chapter['chapter_number']] = $chapter;
    }

    $reportsQuery = $connection->prepare(
        'SELECT account_id, chapter_id FROM reading_reports
         WHERE group_id = :group_id AND cycle_number = :cycle_number
           AND assignment_date = :assignment_date'
    );
    $reportsQuery->execute([
        'group_id' => $groupId,
        'cycle_number' => $cycle['cycle_number'],
        'assignment_date' => $assignmentDate,
    ]);
    $reported = [];
    foreach ($reportsQuery->fetchAll() as $report) {
        $reported[(int) $report['account_id']][(int) $report['chapter_id']] = true;
    }

    $assignments = [];
    $offset = 0;
    foreach ($members as $member) {
        $count = (int) $member['daily_chapter_count'];
        if ($count < 1) {
            continue;
        }

        for ($index = 0; $index < $count; $index++) {
            $chapterNumber = (($offset + $day + $index) % 120) + 1;
            $chapter = $chaptersByNumber[$chapterNumber] ?? null;
            if (!$chapter) {
                continue;
            }

            $accountId = (int) $member['account_id'];
            $chapterId = (int) $chapter['id'];
            $assignments[] = [
                'account_id' => $accountId,
                'display_name' => $member['display_name'],
                'cycle_number' => (int) $cycle['cycle_number'],
                'assignment_date' => $assignmentDate,
                'chapter' => $chapter,
                'status' => isset($reported[$accountId][$chapterId]) ? 'read' : 'unreported',
            ];
        }
        $offset += $count;
    }

    return $assignments;
}

function update_group_daily_count(
    PDO $connection,
    int $groupId,
    int $accountId,
    int $dailyCount
): array {
    $connection->beginTransaction();

    try {
        $groupQuery = $connection->prepare(
            'SELECT status FROM `groups` WHERE id = :id FOR UPDATE'
        );
        $groupQuery->execute(['id' => $groupId]);
        $group = $groupQuery->fetch();
        if (!$group || !in_array($group['status'], ['setup'], true)) {
            $connection->rollBack();
            return ['error' => 'در دوره فعال یا تکمیل‌شده، تعداد روزانه فصل‌ها قابل تغییر نیست.'];
        }

        $membershipQuery = $connection->prepare(
            'SELECT account_id FROM group_members
             WHERE group_id = :group_id AND account_id = :account_id FOR UPDATE'
        );
        $membershipQuery->execute([
            'group_id' => $groupId,
            'account_id' => $accountId,
        ]);
        if (!$membershipQuery->fetch()) {
            $connection->rollBack();
            return ['error' => 'شما عضو این گروه نیستید.'];
        }

        $otherCounts = $connection->prepare(
            'SELECT COALESCE(SUM(daily_chapter_count), 0)
             FROM group_members
             WHERE group_id = :group_id AND account_id <> :account_id'
        );
        $otherCounts->execute([
            'group_id' => $groupId,
            'account_id' => $accountId,
        ]);
        if ((int) $otherCounts->fetchColumn() + $dailyCount > 120) {
            $connection->rollBack();
            return ['error' => 'با این تعداد، مجموع فصل‌های روزانه گروه از ۱۲۰ بیشتر می‌شود.'];
        }

        $update = $connection->prepare(
            'UPDATE group_members SET daily_chapter_count = :daily_count
             WHERE group_id = :group_id AND account_id = :account_id'
        );
        $update->execute([
            'daily_count' => $dailyCount,
            'group_id' => $groupId,
            'account_id' => $accountId,
        ]);
        $connection->commit();

        $started = begin_group_cycle_if_ready($connection, $groupId);
        $totalQuery = $connection->prepare(
            'SELECT COALESCE(SUM(daily_chapter_count), 0)
             FROM group_members WHERE group_id = :group_id'
        );
        $totalQuery->execute(['group_id' => $groupId]);
        return [
            'started' => $started,
            'total' => (int) $totalQuery->fetchColumn(),
        ];
    } catch (Throwable $exception) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        throw $exception;
    }
}

function restart_group_cycle(PDO $connection, int $groupId, int $creatorId): void
{
    $connection->beginTransaction();
    try {
        $groupQuery = $connection->prepare(
            'SELECT created_by, status FROM `groups` WHERE id = :id FOR UPDATE'
        );
        $groupQuery->execute(['id' => $groupId]);
        $group = $groupQuery->fetch();

        if (!$group || (int) $group['created_by'] !== $creatorId || $group['status'] !== 'completed') {
            throw new RuntimeException('فقط سازنده گروه می‌تواند دوره تکمیل‌شده را دوباره آغاز کند.');
        }

        $clearCounts = $connection->prepare(
            'UPDATE group_members SET daily_chapter_count = NULL WHERE group_id = :group_id'
        );
        $clearCounts->execute(['group_id' => $groupId]);
        $restart = $connection->prepare(
            "UPDATE `groups`
             SET status = 'setup', assignments_start_date = NULL
             WHERE id = :id"
        );
        $restart->execute(['id' => $groupId]);
        $connection->commit();
    } catch (Throwable $exception) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        throw $exception;
    }
}

function deactivate_group(PDO $connection, int $groupId, int $creatorId): void
{
    $statement = $connection->prepare(
        "UPDATE `groups`
         SET status = 'deactivated', deactivated_at = UTC_TIMESTAMP()
         WHERE id = :id AND created_by = :creator_id AND status = 'completed'"
    );
    $statement->execute([
        'id' => $groupId,
        'creator_id' => $creatorId,
    ]);

    if ($statement->rowCount() !== 1) {
        throw new RuntimeException('فقط سازنده گروه می‌تواند گروه تکمیل‌شده را غیرفعال کند.');
    }
}
