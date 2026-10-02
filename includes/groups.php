<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/schema.php';

function normalize_invite_code(string $code): ?string
{
    $code = strtoupper(trim($code));

    return preg_match('/^[A-F0-9]{12}$/D', $code) ? $code : null;
}

function group_invitation_url(string $code): string
{
    return rtrim(APP_URL, '/') . '/join.php?code=' . rawurlencode($code);
}

function rename_group(PDO $connection, int $groupId, int $creatorId, string $name): array
{
    $name = trim($name);
    $nameLength = preg_match_all('/./us', $name);

    if ($nameLength === false || $name === '' || $nameLength > 100) {
        return ['error' => 'نام گروه را حداکثر با ۱۰۰ نویسه وارد کنید.'];
    }

    $connection->beginTransaction();

    try {
        $groupQuery = $connection->prepare(
            'SELECT created_by, status, cycle_number
             FROM `groups` WHERE id = :id FOR UPDATE'
        );
        $groupQuery->execute(['id' => $groupId]);
        $group = $groupQuery->fetch();

        if (!$group || (int) $group['created_by'] !== $creatorId) {
            $connection->rollBack();
            return ['error' => 'فقط سازنده گروه می‌تواند نام آن را تغییر دهد.'];
        }
        if ($group['status'] !== 'setup' || (int) $group['cycle_number'] > 0) {
            $connection->rollBack();
            return ['error' => 'پس از آغاز نخستین دوره، تغییر نام گروه امکان‌پذیر نیست.'];
        }

        $update = $connection->prepare(
            'UPDATE `groups` SET name = :name WHERE id = :id'
        );
        $update->execute([
            'name' => $name,
            'id' => $groupId,
        ]);
        $connection->commit();

        return ['name' => $name];
    } catch (Throwable $exception) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        throw $exception;
    }
}
