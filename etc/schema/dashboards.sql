DROP DATABASE IF EXISTS dashboard;
DROP USER IF EXISTS dashboard;

CREATE DATABASE dashboard;
USE dashboard;

CREATE TABLE `dashboard_home` (
    `id` int(10) unsigned NOT NULL PRIMARY KEY AUTO_INCREMENT,
    `name` varchar(64) NOT NULL COLLATE utf8mb4_unicode_ci,
    `owner` varchar(254) DEFAULT NULL COLLATE utf8mb4_unicode_ci,
    UNIQUE KEY(`name`)
) Engine=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE `dashboard` (
    `id` binary(20) NOT NULL PRIMARY KEY COMMENT 'sha1(module.name + name)',
    `home_id` int(10) unsigned NOT NULL,
    `name` varchar(64) NOT NULL COLLATE utf8mb4_unicode_ci,
    `label` varchar(64) NOT NULL COLLATE utf8mb4_unicode_ci,
    `disabled` tinyint(1) DEFAULT 0,
    KEY `fk_dashboard_dashboard_home` (`home_id`),
    CONSTRAINT `fk_dashboard_dashboard_home` FOREIGN KEY (`home_id`) REFERENCES  `dashboard_home` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE `dashlet` (
    `id` binary(20) NOT NULL PRIMARY KEY COMMENT 'sha1(module.name + dashboard.name + name)',
    `dashboard_id` binary(20) NOT NULL COMMENT 'sha1(module.name + name)',
    `owner` varchar(254) DEFAULT NULL COLLATE utf8mb4_unicode_ci,
    `name` varchar(64) NOT NULL COLLATE utf8mb4_unicode_ci,
    `label` varchar(64) NOT NULL COLLATE utf8mb4_unicode_ci,
    `url` varchar(2048) NOT NULL COLLATE utf8mb4_bin,
    `disabled` tinyint(1) DEFAULT 0,
    KEY `fk_dashlet_dashboard` (`dashboard_id`),
    CONSTRAINT `fk_dashlet_dashboard` FOREIGN KEY (`dashboard_id`) REFERENCES `dashboard` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE `dashlet_override` (
    `dashlet_id` binary(20) NOT NULL PRIMARY KEY,
    `owner` varchar(254) NOT NULL COLLATE utf8mb4_unicode_ci,
    `url` varchar(2048) DEFAULT NULL COLLATE utf8mb4_bin,
    `label` varchar(64) DEFAULT NULL COLLATE utf8mb4_unicode_ci,
    `disabled` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

INSERT INTO `dashboard_home` (`id`, `name`, `owner`) VALUES (default, 'Default Home', null);

CREATE USER 'dashboard'@'%' IDENTIFIED BY 'dashboard';
GRANT ALL PRIVILEGES ON `dashboard`.* TO 'dashboard'@'%' IDENTIFIED BY 'dashboard';

FLUSH PRIVILEGES;
