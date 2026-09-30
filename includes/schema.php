<?php

function install_schema(PDO $connection): void
{
    $connection->exec(
        "CREATE TABLE IF NOT EXISTS roles (
            id TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(20) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_roles_name (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $connection->exec(
        "CREATE TABLE IF NOT EXISTS accounts (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            phone VARCHAR(16) NOT NULL,
            display_name VARCHAR(80) NULL,
            role_id TINYINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_accounts_phone (phone),
            CONSTRAINT fk_accounts_role FOREIGN KEY (role_id)
                REFERENCES roles (id) ON UPDATE CASCADE ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $connection->exec(
        "CREATE TABLE IF NOT EXISTS login_otps (
            phone VARCHAR(16) NOT NULL,
            code_hash VARCHAR(255) NOT NULL,
            expires_at DATETIME NOT NULL,
            attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (phone),
            KEY ix_login_otps_expires_at (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    install_group_schema($connection);

    $statement = $connection->prepare(
        "INSERT IGNORE INTO roles (name) VALUES (:admin), (:user)"
    );
    $statement->execute([
        'admin' => 'Admin',
        'user' => 'User',
    ]);
}

function install_group_schema(PDO $connection): void
{
    install_chapter_schema($connection);
    ensure_account_schema($connection);

    $connection->exec(
        "CREATE TABLE IF NOT EXISTS `groups` (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(100) NOT NULL,
            invite_code CHAR(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            created_by BIGINT UNSIGNED NOT NULL,
            status VARCHAR(16) NOT NULL DEFAULT 'setup',
            assignments_start_date DATE NULL,
            cycle_number INT UNSIGNED NOT NULL DEFAULT 0,
            deactivated_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_groups_invite_code (invite_code),
            KEY ix_groups_created_by (created_by),
            CONSTRAINT fk_groups_created_by FOREIGN KEY (created_by)
                REFERENCES accounts (id) ON UPDATE CASCADE ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $connection->exec(
        "CREATE TABLE IF NOT EXISTS group_members (
            group_id BIGINT UNSIGNED NOT NULL,
            account_id BIGINT UNSIGNED NOT NULL,
            daily_chapter_count TINYINT UNSIGNED NULL,
            joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (group_id, account_id),
            KEY ix_group_members_account (account_id),
            CONSTRAINT fk_group_members_group FOREIGN KEY (group_id)
                REFERENCES `groups` (id) ON UPDATE CASCADE ON DELETE CASCADE,
            CONSTRAINT fk_group_members_account FOREIGN KEY (account_id)
                REFERENCES accounts (id) ON UPDATE CASCADE ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    ensure_column($connection, 'groups', 'status', "VARCHAR(16) NOT NULL DEFAULT 'setup'");
    ensure_column($connection, 'groups', 'assignments_start_date', 'DATE NULL');
    ensure_column($connection, 'groups', 'cycle_number', 'INT UNSIGNED NOT NULL DEFAULT 0');
    ensure_column($connection, 'groups', 'deactivated_at', 'DATETIME NULL');
    ensure_column($connection, 'group_members', 'daily_chapter_count', 'TINYINT UNSIGNED NULL');
    $connection->exec(
        "CREATE TABLE IF NOT EXISTS group_cycles (
            group_id BIGINT UNSIGNED NOT NULL,
            cycle_number INT UNSIGNED NOT NULL,
            starts_on DATE NOT NULL,
            status VARCHAR(16) NOT NULL DEFAULT 'active',
            PRIMARY KEY (group_id, cycle_number),
            UNIQUE KEY uq_group_cycles_start (group_id, starts_on),
            CONSTRAINT fk_group_cycles_group FOREIGN KEY (group_id)
                REFERENCES `groups` (id) ON UPDATE CASCADE ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $connection->exec(
        "CREATE TABLE IF NOT EXISTS group_cycle_members (
            group_id BIGINT UNSIGNED NOT NULL,
            cycle_number INT UNSIGNED NOT NULL,
            account_id BIGINT UNSIGNED NOT NULL,
            member_order SMALLINT UNSIGNED NOT NULL,
            daily_chapter_count TINYINT UNSIGNED NOT NULL,
            PRIMARY KEY (group_id, cycle_number, account_id),
            UNIQUE KEY uq_group_cycle_member_order (group_id, cycle_number, member_order),
            CONSTRAINT fk_group_cycle_members_cycle FOREIGN KEY (group_id, cycle_number)
                REFERENCES group_cycles (group_id, cycle_number) ON UPDATE CASCADE ON DELETE CASCADE,
            CONSTRAINT fk_group_cycle_members_account FOREIGN KEY (account_id)
                REFERENCES accounts (id) ON UPDATE CASCADE ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $connection->exec(
        "CREATE TABLE IF NOT EXISTS reading_reports (
            group_id BIGINT UNSIGNED NOT NULL,
            account_id BIGINT UNSIGNED NOT NULL,
            chapter_id BIGINT UNSIGNED NOT NULL,
            cycle_number INT UNSIGNED NOT NULL,
            assignment_date DATE NOT NULL,
            submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (group_id, account_id, chapter_id, cycle_number, assignment_date),
            KEY ix_reading_reports_group_date (group_id, assignment_date),
            CONSTRAINT fk_reading_reports_group FOREIGN KEY (group_id)
                REFERENCES `groups` (id) ON UPDATE CASCADE ON DELETE CASCADE,
            CONSTRAINT fk_reading_reports_account FOREIGN KEY (account_id)
                REFERENCES accounts (id) ON UPDATE CASCADE ON DELETE CASCADE,
            CONSTRAINT fk_reading_reports_chapter FOREIGN KEY (chapter_id)
                REFERENCES chapters (id) ON UPDATE CASCADE ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function ensure_account_schema(PDO $connection): void
{
    ensure_column($connection, 'accounts', 'display_name', 'VARCHAR(80) NULL');
    migrate_iranian_mobile_numbers($connection);
}

function ensure_column(PDO $connection, string $table, string $column, string $definition): void
{
    $statement = $connection->prepare(
        'SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = :table_name
           AND column_name = :column_name'
    );
    $statement->execute([
        'table_name' => $table,
        'column_name' => $column,
    ]);

    if ((int) $statement->fetchColumn() === 0) {
        try {
            $connection->exec(
                'ALTER TABLE `' . str_replace('`', '``', $table) . '` ADD COLUMN `'
                . str_replace('`', '``', $column) . '` ' . $definition
            );
        } catch (PDOException $exception) {
            $statement->execute([
                'table_name' => $table,
                'column_name' => $column,
            ]);
            if ((int) $statement->fetchColumn() === 0) {
                throw $exception;
            }
        }
    }
}

function migrate_iranian_mobile_numbers(PDO $connection): void
{
    $legacyNumbers = $connection->query(
        "SELECT id, phone FROM accounts WHERE phone LIKE '+98%'"
    )->fetchAll();
    $findLocalNumber = $connection->prepare(
        'SELECT id FROM accounts WHERE phone = :phone AND id <> :id'
    );
    $updateNumber = $connection->prepare(
        'UPDATE accounts SET phone = :phone WHERE id = :id'
    );

    foreach ($legacyNumbers as $account) {
        if (!preg_match('/^\+989[0-9]{9}$/D', $account['phone'])) {
            continue;
        }

        $localNumber = '0' . substr($account['phone'], 3);
        $findLocalNumber->execute([
            'phone' => $localNumber,
            'id' => $account['id'],
        ]);

        if ($findLocalNumber->fetchColumn()) {
            error_log('Could not migrate account phone because the local number already exists (account ID '
                . (int) $account['id'] . ').');
            continue;
        }

        $updateNumber->execute([
            'phone' => $localNumber,
            'id' => $account['id'],
        ]);
    }
}

function group_schema_is_installed(PDO $connection): bool
{
    $statement = $connection->query(
        "SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE()
           AND table_name IN ('groups', 'group_members')"
    );

    return (int) $statement->fetchColumn() === 2;
}

function ensure_group_schema(PDO $connection): void
{
    install_group_schema($connection);
}

function install_chapter_schema(PDO $connection): void
{
    $connection->exec(
        "CREATE TABLE IF NOT EXISTS chapters (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            chapter_number TINYINT UNSIGNED NOT NULL,
            title VARCHAR(255) NOT NULL,
            description TEXT NOT NULL,
            start_sentence TEXT NOT NULL,
            end_sentence TEXT NOT NULL,
            created_by BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_chapters_number (chapter_number),
            CONSTRAINT fk_chapters_created_by FOREIGN KEY (created_by)
                REFERENCES accounts (id) ON UPDATE CASCADE ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function chapter_schema_is_installed(PDO $connection): bool
{
    $statement = $connection->query(
        "SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = 'chapters'"
    );

    return (int) $statement->fetchColumn() === 1;
}

function ensure_chapter_schema(PDO $connection): void
{
    if (!chapter_schema_is_installed($connection)) {
        install_chapter_schema($connection);
    }
}

function schema_is_installed(PDO $connection): bool
{
    $statement = $connection->query(
        "SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE()
           AND table_name IN ('roles', 'accounts', 'login_otps')"
    );

    $installed = (int) $statement->fetchColumn() === 3;
    if ($installed) {
        ensure_account_schema($connection);
    }

    return $installed;
}
