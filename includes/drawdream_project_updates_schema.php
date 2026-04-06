<?php

// includes/drawdream_project_updates_schema.php — ตารางผลลัพธ์/อัปเดตโครงการ
declare(strict_types=1);

/**
 * ใช้โดย foundation_post_update.php และ project_result.php
 */
function drawdream_ensure_project_updates_table(mysqli $conn): void
{
    $t = @$conn->query("SHOW TABLES LIKE 'project_updates'");
    if ($t && $t->num_rows > 0) {
        return;
    }

    @$conn->query(
        "CREATE TABLE IF NOT EXISTS `project_updates` (
            `update_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `project_id` INT UNSIGNED NOT NULL,
            `title` VARCHAR(255) NOT NULL DEFAULT '',
            `description` TEXT NULL,
            `update_image` VARCHAR(255) NULL DEFAULT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`update_id`),
            KEY `idx_project_updates_project` (`project_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}
