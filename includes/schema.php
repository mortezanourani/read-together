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

    $statement = $connection->prepare(
        "INSERT IGNORE INTO roles (name) VALUES (:admin), (:user)"
    );
    $statement->execute([
        'admin' => 'Admin',
        'user' => 'User',
    ]);
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
