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
    $connection->exec(
        "CREATE TABLE IF NOT EXISTS `groups` (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(100) NOT NULL,
            invite_code CHAR(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            created_by BIGINT UNSIGNED NOT NULL,
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
            joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (group_id, account_id),
            KEY ix_group_members_account (account_id),
            CONSTRAINT fk_group_members_group FOREIGN KEY (group_id)
                REFERENCES `groups` (id) ON UPDATE CASCADE ON DELETE CASCADE,
            CONSTRAINT fk_group_members_account FOREIGN KEY (account_id)
                REFERENCES accounts (id) ON UPDATE CASCADE ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
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
    if (!group_schema_is_installed($connection)) {
        install_group_schema($connection);
    }
}

function schema_is_installed(PDO $connection): bool
{
    $statement = $connection->query(
        "SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE()
           AND table_name IN ('roles', 'accounts', 'login_otps')"
    );

    return (int) $statement->fetchColumn() === 3;
}
