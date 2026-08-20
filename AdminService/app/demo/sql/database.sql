-- ============================================================
-- 新 DBAL 测试数据
-- 使用前提: config/database.php 的 connections.default 已配置好
-- 数据表统一使用前缀 admin_service_, 对应配置中 prefix='admin_service_'
-- (数据库层使用 from('users') 即可, 编译期会自动补前缀)
-- ============================================================

-- 建库(可选, 按你的权限决定是否执行)
-- CREATE DATABASE IF NOT EXISTS admin_service
--     DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
-- USE admin_service;

-- ---------- 用户表 ----------
CREATE TABLE IF NOT EXISTS `admin_service_users` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`       VARCHAR(50)  NOT NULL COMMENT '姓名',
    `age`        TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '年龄',
    `status`     TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '状态: 1正常 2禁用 3待审核',
    `deleted_at` DATETIME NULL DEFAULT NULL COMMENT '软删除时间',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_status` (`status`),
    KEY `idx_age` (`age`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='用户表';

-- ---------- 订单表 ----------
CREATE TABLE IF NOT EXISTS `admin_service_orders` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`    INT UNSIGNED NOT NULL COMMENT '用户ID',
    `order_no`   VARCHAR(32)  NOT NULL COMMENT '订单号',
    `amount`     DECIMAL(10,2) NOT NULL DEFAULT '0.00' COMMENT '金额',
    `status`     TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '订单状态',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='订单表';

-- ---------- 用户测试数据(20 条, 覆盖各操作符) ----------
INSERT INTO `admin_service_users` (`name`,`age`,`status`,`deleted_at`) VALUES
('张三',   20, 1, NULL),
('张三丰', 30, 1, NULL),
('李四',   18, 1, NULL),
('王五',   22, 2, NULL),
('赵六',   25, 1, '2025-01-01 00:00:00'),  -- 已软删除
('钱七',   35, 3, NULL),
('孙八',   40, 1, NULL),
('周九',   28, 2, NULL),
('吴十',   19, 1, NULL),
('郑十一', 50, 1, NULL),
('冯十二', 60, 3, NULL),
('陈十三', 21, 1, NULL),
('褚十四', 33, 2, NULL),
('卫十五', 45, 1, NULL),
('蒋十六', 55, 1, NULL),
('沈十七', 26, 3, NULL),
('韩十八', 38, 1, NULL),
('杨十九', 24, 2, NULL),
('朱二十', 42, 1, NULL),
('秦二十一', 29, 1, NULL);

-- ---------- 订单测试数据(10 条, 覆盖关联查询) ----------
INSERT INTO `admin_service_orders` (`user_id`,`order_no`,`amount`,`status`) VALUES
(1,  '202501010001',  99.90, 1),
(1,  '202501020002', 199.00, 2),
(2,  '202501030003',  59.50, 1),
(3,  '202501040004', 299.00, 1),
(3,  '202501050005',  19.90, 3),
(4,  '202501060006', 129.00, 1),
(5,  '202501070007',  79.00, 2),
(6,  '202501080008', 399.00, 1),
(7,  '202501090009',  49.00, 1),
(8,  '202501100010', 899.00, 1);
