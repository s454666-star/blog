<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach ($this->statements() as $sql) {
            DB::unprepared($sql);
        }
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();

        foreach (array_reverse($this->tableNames()) as $table) {
            Schema::dropIfExists($table);
        }

        Schema::enableForeignKeyConstraints();
    }

    private function statements(): array
    {
        return [
            <<<'SQL'
CREATE TABLE IF NOT EXISTS `actors` (
  `id` int NOT NULL AUTO_INCREMENT,
  `actor_name` varchar(255) NOT NULL,
  `secondary_actor_name` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3
SQL,

            <<<'SQL'
CREATE TABLE IF NOT EXISTS `albums` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `content` text NOT NULL,
  `cover_path` varchar(255) NOT NULL,
  `actor_id` int NOT NULL,
  `is_viewed` tinyint(1) NOT NULL DEFAULT '0',
  `deleted` tinyint NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `actor_id` (`actor_id`),
  CONSTRAINT `albums_ibfk_1` FOREIGN KEY (`actor_id`) REFERENCES `actors` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3
SQL,

            <<<'SQL'
CREATE TABLE IF NOT EXISTS `album_photos` (
  `id` int NOT NULL AUTO_INCREMENT,
  `album_id` int NOT NULL,
  `index_sort` int DEFAULT NULL,
  `photo_path` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `album_id` (`album_id`),
  CONSTRAINT `album_photos_ibfk_1` FOREIGN KEY (`album_id`) REFERENCES `albums` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3
SQL,

            <<<'SQL'
CREATE TABLE IF NOT EXISTS `api_log` (
  `id` int NOT NULL,
  `apilog_no` varchar(15) NOT NULL,
  `orderno` varchar(15) DEFAULT NULL,
  `api_url` varchar(100) NOT NULL,
  `api_types` varchar(20) NOT NULL,
  `msgid` varchar(20) DEFAULT NULL,
  `input_info` text,
  `api_response` text,
  `call_back_response` text,
  `call_back_time` datetime DEFAULT NULL,
  `call_back_status` tinyint(1) NOT NULL DEFAULT '0' COMMENT '0失敗1成功',
  `call_time` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `end_time` datetime DEFAULT NULL,
  `api_status` tinyint(1) NOT NULL DEFAULT '0' COMMENT '0失敗1成功',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3
SQL,

            <<<'SQL'
CREATE TABLE IF NOT EXISTS `articles` (
  `article_id` int NOT NULL AUTO_INCREMENT,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `password` text COLLATE utf8mb4_unicode_ci,
  `https_link` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `detail_url` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `source_type` int DEFAULT NULL,
  `article_time` timestamp NULL DEFAULT NULL,
  `is_disabled` tinyint DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `description` text COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`article_id`),
  KEY `idx_articles_source_type` (`source_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,

            <<<'SQL'
CREATE TABLE IF NOT EXISTS `blacklisted_receivers` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `type` smallint unsigned NOT NULL,
  `donor_no` varchar(9) COLLATE utf8mb4_unicode_ci NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `blacklisted_receivers_type_donor_no_unique` (`type`,`donor_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,

            <<<'SQL'
CREATE TABLE IF NOT EXISTS `btdig_results` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `search_keyword` varchar(100) NOT NULL,
  `type` varchar(2) DEFAULT NULL,
  `detail_url` varchar(500) NOT NULL,
  `magnet` text,
  `name` text,
  `size` varchar(100) DEFAULT NULL,
  `age` varchar(100) DEFAULT NULL,
  `files` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `copied_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_search_keyword` (`search_keyword`),
  KEY `idx_copied_at` (`copied_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,

            <<<'SQL'
CREATE TABLE IF NOT EXISTS `cache` (
  `key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `expiration` int NOT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,

            <<<'SQL'
CREATE TABLE IF NOT EXISTS `dialogues` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `chat_id` bigint NOT NULL,
  `message_id` bigint NOT NULL,
  `text` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_read` tinyint(1) DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_chat_text` (`chat_id`),
  KEY `idx_text` ((left(`text`,255)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,

            <<<'SQL'
CREATE TABLE IF NOT EXISTS `extracted_codes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,

            <<<'SQL'
CREATE TABLE IF NOT EXISTS `file_screenshots` (
  `id` int NOT NULL AUTO_INCREMENT,
  `file_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_path` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `cover_image` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `screenshot_paths` longtext COLLATE utf8mb4_unicode_ci,
  `rating` decimal(3,2) DEFAULT '0.00',
  `notes` text COLLATE utf8mb4_unicode_ci,
  `type` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `is_view` tinyint NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,

            <<<'SQL'
CREATE TABLE IF NOT EXISTS `images` (
  `id` int NOT NULL AUTO_INCREMENT,
  `image_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `image_path` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `article_id` int NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_images_article_id` (`article_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,

            <<<'SQL'
CREATE TABLE IF NOT EXISTS `jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `queue` varchar(255) NOT NULL,
  `payload` longtext NOT NULL,
  `attempts` tinyint unsigned NOT NULL DEFAULT '0',
  `reserved_at` int unsigned DEFAULT NULL,
  `available_at` int unsigned NOT NULL,
  `created_at` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `jobs_queue_index` (`queue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,

            <<<'SQL'
CREATE TABLE IF NOT EXISTS `members` (
  `id` int NOT NULL AUTO_INCREMENT COMMENT '唯一的會員ID',
  `username` varchar(50) NOT NULL COMMENT '帳號',
  `password` varchar(255) NOT NULL COMMENT '密碼 (建議加密儲存)',
  `name` varchar(100) NOT NULL COMMENT '姓名',
  `phone` varchar(15) DEFAULT NULL COMMENT '電話號碼',
  `email` varchar(100) NOT NULL COMMENT '電子郵件',
  `email_verified` tinyint(1) DEFAULT '0' COMMENT '是否已驗證 (0 = 未驗證, 1 = 已驗證)',
  `address` varchar(255) DEFAULT NULL COMMENT '地址 (可選)',
  `email_verification_token` varchar(250) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP COMMENT '註冊時間',
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新時間',
  `status` enum('active','inactive') DEFAULT 'active' COMMENT '帳號狀態',
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COMMENT='會員資料表'
SQL,

            <<<'SQL'
CREATE TABLE IF NOT EXISTS `credit_cards` (
  `id` int NOT NULL AUTO_INCREMENT,
  `member_id` int NOT NULL COMMENT '對應的會員ID',
  `cardholder_name` varchar(100) NOT NULL COMMENT '持卡人姓名',
  `card_number` varchar(100) NOT NULL COMMENT '信用卡卡號 (建議加密儲存)',
  `expiry_date` varchar(5) NOT NULL COMMENT '到期日 (格式: MM/YY)',
  `card_type` enum('Visa','MasterCard','American Express','Discover') NOT NULL COMMENT '信用卡類型',
  `billing_address` varchar(255) NOT NULL COMMENT '帳單地址',
  `postal_code` varchar(10) NOT NULL COMMENT '郵遞區號',
  `country` varchar(50) DEFAULT 'Taiwan' COMMENT '國家',
  `is_default` tinyint(1) DEFAULT '0' COMMENT '是否為主要卡片 (0 = 否, 1 = 是)',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_member_credit_card` (`member_id`),
  CONSTRAINT `fk_member_credit_card` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3
SQL,

            <<<'SQL'
CREATE TABLE IF NOT EXISTS `delivery_addresses` (
  `id` int NOT NULL AUTO_INCREMENT,
  `member_id` int NOT NULL COMMENT '對應的會員ID',
  `recipient` varchar(100) NOT NULL COMMENT '收件人姓名',
  `phone` varchar(15) NOT NULL COMMENT '收件人電話號碼',
  `address` varchar(255) NOT NULL COMMENT '宅配地址',
  `postal_code` varchar(10) NOT NULL COMMENT '郵遞區號',
  `country` varchar(50) DEFAULT 'Taiwan' COMMENT '國家',
  `city` varchar(50) NOT NULL COMMENT '城市',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `is_default` tinyint(1) DEFAULT '0' COMMENT '是否為主要地址 (0 = 否, 1 = 是)',
  PRIMARY KEY (`id`),
  KEY `fk_member_delivery` (`member_id`),
  CONSTRAINT `fk_member_delivery` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3
SQL,

            <<<'SQL'
CREATE TABLE IF NOT EXISTS `orders` (
  `id` int NOT NULL AUTO_INCREMENT,
  `member_id` int NOT NULL COMMENT '下單的會員ID',
  `order_number` varchar(50) NOT NULL COMMENT '訂單編號',
  `order_date` timestamp NULL DEFAULT CURRENT_TIMESTAMP COMMENT '訂單日期',
  `status` enum('pending','processing','shipped','completed','cancelled') DEFAULT 'pending' COMMENT '訂單狀態',
  `total_amount` decimal(10,2) NOT NULL COMMENT '訂單總金額',
  `payment_method` enum('credit_card','bank_transfer','cash_on_delivery') NOT NULL COMMENT '付款方式',
  `shipping_fee` decimal(10,2) DEFAULT '0.00' COMMENT '運費',
  `delivery_address_id` int DEFAULT NULL,
  `credit_card_id` int DEFAULT NULL COMMENT '使用的信用卡ID (如果付款方式為信用卡)',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `order_number` (`order_number`),
  KEY `fk_member_order` (`member_id`),
  KEY `fk_order_delivery_address` (`delivery_address_id`),
  KEY `fk_order_credit_card` (`credit_card_id`),
  CONSTRAINT `fk_member_order` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_order_credit_card` FOREIGN KEY (`credit_card_id`) REFERENCES `credit_cards` (`id`),
  CONSTRAINT `fk_order_delivery_address` FOREIGN KEY (`delivery_address_id`) REFERENCES `delivery_addresses` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3
SQL,

            <<<'SQL'
CREATE TABLE IF NOT EXISTS `product_categories` (
  `id` int NOT NULL AUTO_INCREMENT,
  `category_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `status` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,

            <<<'SQL'
CREATE TABLE IF NOT EXISTS `products` (
  `id` int NOT NULL AUTO_INCREMENT,
  `category_id` int NOT NULL,
  `product_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `price` decimal(10,2) NOT NULL,
  `image_base64` mediumtext COLLATE utf8mb4_unicode_ci,
  `description` text COLLATE utf8mb4_unicode_ci,
  `stock_quantity` int DEFAULT '0',
  `sku` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `weight` decimal(10,2) DEFAULT NULL,
  `dimensions` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `color` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `material` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `brand` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('available','out_of_stock','discontinued') COLLATE utf8mb4_unicode_ci DEFAULT 'available',
  `rating` decimal(3,2) DEFAULT '0.00',
  `release_date` date DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `category_id` (`category_id`),
  CONSTRAINT `products_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `product_categories` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,

            <<<'SQL'
CREATE TABLE IF NOT EXISTS `order_items` (
  `id` int NOT NULL AUTO_INCREMENT,
  `order_id` int NOT NULL COMMENT '訂單ID',
  `product_id` int NOT NULL COMMENT '產品ID',
  `quantity` int NOT NULL COMMENT '購買數量',
  `price` decimal(10,2) NOT NULL COMMENT '單價',
  `subtotal` decimal(10,2) GENERATED ALWAYS AS ((`quantity` * `price`)) STORED COMMENT '小計',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `return_quantity` int DEFAULT '0' COMMENT '退貨數量',
  PRIMARY KEY (`id`),
  KEY `fk_order_item_order` (`order_id`),
  KEY `fk_order_item_product` (`product_id`),
  CONSTRAINT `fk_order_item_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_order_item_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3
SQL,

            <<<'SQL'
CREATE TABLE IF NOT EXISTS `return_orders` (
  `id` int NOT NULL AUTO_INCREMENT COMMENT '唯一的退貨單ID',
  `member_id` int NOT NULL COMMENT '申請退貨的會員ID',
  `order_id` int NOT NULL COMMENT '相關訂單ID',
  `reason` varchar(500) NOT NULL COMMENT '退貨原因',
  `return_date` timestamp NULL DEFAULT CURRENT_TIMESTAMP COMMENT '退貨日期',
  `status` enum('已接收','物流運送中','已完成','已取消') DEFAULT '已接收' COMMENT '退貨單狀態',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP COMMENT '建立時間',
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新時間',
  `order_item_id` int NOT NULL COMMENT '相關訂單項目ID',
  `return_quantity` int NOT NULL COMMENT '退貨數量',
  `return_order_number` varchar(50) NOT NULL COMMENT '退貨單號',
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_return_order_number` (`return_order_number`),
  KEY `fk_return_orders_member` (`member_id`),
  KEY `fk_return_orders_order` (`order_id`),
  KEY `fk_return_orders_order_item` (`order_item_id`),
  CONSTRAINT `fk_return_orders_member` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_return_orders_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_return_orders_order_item` FOREIGN KEY (`order_item_id`) REFERENCES `order_items` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COMMENT='退貨單資料表'
SQL,

            <<<'SQL'
CREATE TABLE IF NOT EXISTS `sessions` (
  `id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` text COLLATE utf8mb4_unicode_ci,
  `payload` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `last_activity` int NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,

            <<<'SQL'
CREATE TABLE IF NOT EXISTS `telegram_filestore_sessions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `chat_id` bigint NOT NULL,
  `username` varchar(255) DEFAULT NULL,
  `encrypt_token` varchar(255) DEFAULT NULL,
  `public_token` varchar(255) DEFAULT NULL,
  `status` enum('uploading','closed') NOT NULL DEFAULT 'uploading',
  `total_files` int NOT NULL DEFAULT '0',
  `total_size` bigint NOT NULL DEFAULT '0',
  `share_count` int NOT NULL DEFAULT '0',
  `last_shared_at` datetime DEFAULT NULL,
  `close_upload_prompted_at` datetime DEFAULT NULL,
  `is_sending` tinyint(1) NOT NULL DEFAULT '0',
  `sending_started_at` datetime DEFAULT NULL,
  `sending_finished_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `closed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `encrypt_token` (`encrypt_token`),
  UNIQUE KEY `uniq_public_token` (`public_token`),
  KEY `idx_chat_id` (`chat_id`),
  KEY `idx_encrypt_token` (`encrypt_token`),
  KEY `idx_chat_status` (`chat_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,

            <<<'SQL'
CREATE TABLE IF NOT EXISTS `telegram_filestore_files` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `session_id` bigint unsigned NOT NULL,
  `chat_id` bigint NOT NULL,
  `message_id` bigint NOT NULL,
  `file_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `file_unique_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `file_name` varchar(255) DEFAULT NULL,
  `mime_type` varchar(100) DEFAULT NULL,
  `file_size` bigint NOT NULL DEFAULT '0',
  `file_type` enum('photo','video','document','other') NOT NULL,
  `raw_payload` json NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_session_id` (`session_id`),
  KEY `idx_chat_id` (`chat_id`),
  KEY `idx_file_unique_id` (`file_unique_id`),
  CONSTRAINT `fk_session` FOREIGN KEY (`session_id`) REFERENCES `telegram_filestore_sessions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,

            <<<'SQL'
CREATE TABLE IF NOT EXISTS `token_scan_headers` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `peer_id` bigint unsigned NOT NULL,
  `chat_title` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_start_message_id` bigint unsigned NOT NULL DEFAULT '1',
  `max_message_id` bigint unsigned NOT NULL DEFAULT '0',
  `max_message_datetime` datetime DEFAULT NULL,
  `last_batch_count` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_token_scan_headers_peer_id` (`peer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,

            <<<'SQL'
CREATE TABLE IF NOT EXISTS `token_scan_items` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `header_id` bigint unsigned NOT NULL,
  `token` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_token_scan_items_header_token` (`header_id`,`token`),
  KEY `idx_token_scan_items_header_id` (`header_id`),
  CONSTRAINT `fk_token_scan_items_header_id` FOREIGN KEY (`header_id`) REFERENCES `token_scan_headers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,

            <<<'SQL'
CREATE TABLE IF NOT EXISTS `video_duplicates` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `filename` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `full_path` varchar(2048) COLLATE utf8mb4_unicode_ci NOT NULL,
  `full_path_sha1` char(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_size_bytes` bigint unsigned NOT NULL,
  `file_mtime` int unsigned NOT NULL,
  `duration_seconds` int unsigned NOT NULL,
  `snapshot1_b64` mediumtext COLLATE utf8mb4_unicode_ci,
  `snapshot2_b64` mediumtext COLLATE utf8mb4_unicode_ci,
  `snapshot3_b64` mediumtext COLLATE utf8mb4_unicode_ci,
  `snapshot4_b64` mediumtext COLLATE utf8mb4_unicode_ci,
  `hash1_hex` char(16) COLLATE utf8mb4_unicode_ci NOT NULL,
  `hash2_hex` char(16) COLLATE utf8mb4_unicode_ci NOT NULL,
  `hash3_hex` char(16) COLLATE utf8mb4_unicode_ci NOT NULL,
  `hash4_hex` char(16) COLLATE utf8mb4_unicode_ci NOT NULL,
  `similar_video_ids` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `last_error` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_full_path_sha1` (`full_path_sha1`),
  KEY `idx_duration_size` (`duration_seconds`,`file_size_bytes`),
  KEY `idx_hash1` (`hash1_hex`),
  KEY `idx_hash2` (`hash2_hex`),
  KEY `idx_hash3` (`hash3_hex`),
  KEY `idx_hash4` (`hash4_hex`),
  KEY `idx_file_size_only` (`file_size_bytes`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,

            <<<'SQL'
CREATE TABLE IF NOT EXISTS `video_master` (
  `id` int NOT NULL AUTO_INCREMENT COMMENT '主鍵',
  `video_name` varchar(255) NOT NULL COMMENT '影片名稱',
  `video_path` varchar(500) NOT NULL COMMENT '影片路徑',
  `m3u8_path` varchar(100) DEFAULT NULL,
  `duration` decimal(10,2) NOT NULL COMMENT '影片時長（秒，保留小數點兩位）',
  `video_type` varchar(10) DEFAULT '1',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP COMMENT '建立時間',
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新時間',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='影片主檔'
SQL,

            <<<'SQL'
CREATE TABLE IF NOT EXISTS `video_screenshots` (
  `id` int NOT NULL AUTO_INCREMENT COMMENT '主鍵',
  `video_master_id` int NOT NULL COMMENT '影片主檔 ID',
  `screenshot_path` varchar(500) NOT NULL COMMENT '截圖路徑',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP COMMENT '建立時間',
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新時間',
  PRIMARY KEY (`id`),
  KEY `video_master_id` (`video_master_id`),
  CONSTRAINT `video_screenshots_ibfk_1` FOREIGN KEY (`video_master_id`) REFERENCES `video_master` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='影片截圖'
SQL,

            <<<'SQL'
CREATE TABLE IF NOT EXISTS `video_face_screenshots` (
  `id` int NOT NULL AUTO_INCREMENT COMMENT '主鍵',
  `video_screenshot_id` int DEFAULT NULL COMMENT '影片截圖 ID',
  `face_image_path` varchar(500) NOT NULL COMMENT '人臉圖片路徑',
  `is_master` tinyint NOT NULL DEFAULT '0',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP COMMENT '建立時間',
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新時間',
  PRIMARY KEY (`id`),
  KEY `video_screenshot_id` (`video_screenshot_id`),
  CONSTRAINT `video_face_screenshots_ibfk_1` FOREIGN KEY (`video_screenshot_id`) REFERENCES `video_screenshots` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='影片人臉截圖'
SQL,

            <<<'SQL'
CREATE TABLE IF NOT EXISTS `videos` (
  `id` int NOT NULL AUTO_INCREMENT,
  `video_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `path` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tags` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `rating` tinyint DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `path` (`path`),
  CONSTRAINT `videos_chk_1` CHECK (((`rating` >= 1) and (`rating` <= 5)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,

            <<<'SQL'
CREATE TABLE IF NOT EXISTS `videos_ts` (
  `id` int NOT NULL AUTO_INCREMENT,
  `video_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `path` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `video_time` int DEFAULT '0',
  `tags` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `rating` tinyint DEFAULT NULL,
  `preview_image` text COLLATE utf8mb4_unicode_ci,
  `video_screenshot` text COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`),
  KEY `path` (`path`),
  CONSTRAINT `videos_ts_chk_1` CHECK (((`rating` >= 1) and (`rating` <= 5)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        ];
    }

    private function tableNames(): array
    {
        return [
            'actors',
            'albums',
            'album_photos',
            'api_log',
            'articles',
            'blacklisted_receivers',
            'btdig_results',
            'cache',
            'dialogues',
            'extracted_codes',
            'file_screenshots',
            'images',
            'jobs',
            'members',
            'credit_cards',
            'delivery_addresses',
            'orders',
            'product_categories',
            'products',
            'order_items',
            'return_orders',
            'sessions',
            'telegram_filestore_sessions',
            'telegram_filestore_files',
            'token_scan_headers',
            'token_scan_items',
            'video_duplicates',
            'video_master',
            'video_screenshots',
            'video_face_screenshots',
            'videos',
            'videos_ts',
        ];
    }
};
