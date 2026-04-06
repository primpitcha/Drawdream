-- รันครั้งเดียวถ้าไม่ได้โหลด db.php (หรือ migration อัตโนมัติล้มเหลว)
-- ตารางผลลัพธ์โครงการ — ใช้กับ foundation_post_update.php / project_result.php

CREATE TABLE IF NOT EXISTS `project_updates` (
    `update_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `project_id` INT UNSIGNED NOT NULL,
    `title` VARCHAR(255) NOT NULL DEFAULT '',
    `description` TEXT NULL,
    `update_image` VARCHAR(255) NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`update_id`),
    KEY `idx_project_updates_project` (`project_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
