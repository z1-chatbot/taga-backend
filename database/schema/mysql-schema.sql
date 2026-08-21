/*!999999\- enable the sandbox mode */ 
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*M!100616 SET @OLD_NOTE_VERBOSITY=@@NOTE_VERBOSITY, NOTE_VERBOSITY=0 */;
DROP TABLE IF EXISTS `addresses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `addresses` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  `address_line_1` varchar(255) NOT NULL,
  `address_line_2` varchar(255) DEFAULT NULL,
  `city` varchar(255) NOT NULL,
  `state` varchar(255) NOT NULL,
  `postal_code` varchar(255) NOT NULL,
  `country` varchar(255) NOT NULL DEFAULT 'Nigeria',
  `phone` varchar(255) DEFAULT NULL,
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_default_address` (`user_id`,`is_default`),
  CONSTRAINT `addresses_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `agent_documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `agent_documents` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `delivery_agent_id` bigint(20) unsigned NOT NULL,
  `document_type` enum('government_id','drivers_license','vehicle_registration','profile_photo','insurance','other') NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `mime_type` varchar(255) DEFAULT NULL,
  `file_size` int(11) DEFAULT NULL,
  `verification_status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `rejection_reason` text DEFAULT NULL,
  `verified_by` bigint(20) unsigned DEFAULT NULL,
  `verified_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `agent_documents_verified_by_foreign` (`verified_by`),
  KEY `agent_documents_delivery_agent_id_document_type_index` (`delivery_agent_id`,`document_type`),
  KEY `agent_documents_verification_status_index` (`verification_status`),
  CONSTRAINT `agent_documents_delivery_agent_id_foreign` FOREIGN KEY (`delivery_agent_id`) REFERENCES `delivery_agents` (`id`) ON DELETE CASCADE,
  CONSTRAINT `agent_documents_verified_by_foreign` FOREIGN KEY (`verified_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `agent_earnings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `agent_earnings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `delivery_agent_id` bigint(20) unsigned DEFAULT NULL,
  `logistics_company_id` bigint(20) unsigned DEFAULT NULL,
  `order_id` bigint(20) unsigned NOT NULL,
  `delivery_fee` decimal(10,2) NOT NULL,
  `agreed_rate` decimal(10,2) DEFAULT NULL COMMENT 'The agreed base rate from shipping_rates table',
  `agent_commission` decimal(10,2) DEFAULT NULL,
  `platform_commission` decimal(10,2) DEFAULT 0.00,
  `commission_percentage` decimal(5,2) DEFAULT NULL,
  `status` enum('pending','available','paid_out') NOT NULL DEFAULT 'pending',
  `available_at` timestamp NULL DEFAULT NULL,
  `payout_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `agent_earnings_payout_id_foreign` (`payout_id`),
  KEY `agent_earnings_delivery_agent_id_status_index` (`delivery_agent_id`,`status`),
  KEY `agent_earnings_logistics_company_id_status_index` (`logistics_company_id`,`status`),
  KEY `agent_earnings_order_id_index` (`order_id`),
  KEY `agent_earnings_status_index` (`status`),
  CONSTRAINT `agent_earnings_delivery_agent_id_foreign` FOREIGN KEY (`delivery_agent_id`) REFERENCES `delivery_agents` (`id`) ON DELETE CASCADE,
  CONSTRAINT `agent_earnings_logistics_company_id_foreign` FOREIGN KEY (`logistics_company_id`) REFERENCES `logistics_companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `agent_earnings_order_id_foreign` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `agent_earnings_payout_id_foreign` FOREIGN KEY (`payout_id`) REFERENCES `agent_payouts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `agent_invitations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `agent_invitations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `logistics_company_id` bigint(20) unsigned NOT NULL,
  `email` varchar(255) NOT NULL,
  `phone` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `token` varchar(255) NOT NULL,
  `status` enum('pending','accepted','expired','cancelled') NOT NULL DEFAULT 'pending',
  `expires_at` timestamp NOT NULL,
  `accepted_at` timestamp NULL DEFAULT NULL,
  `delivery_agent_id` bigint(20) unsigned DEFAULT NULL,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `agent_invitations_token_unique` (`token`),
  KEY `agent_invitations_delivery_agent_id_foreign` (`delivery_agent_id`),
  KEY `agent_invitations_logistics_company_id_index` (`logistics_company_id`),
  KEY `agent_invitations_token_index` (`token`),
  KEY `agent_invitations_email_status_index` (`email`,`status`),
  CONSTRAINT `agent_invitations_delivery_agent_id_foreign` FOREIGN KEY (`delivery_agent_id`) REFERENCES `delivery_agents` (`id`) ON DELETE SET NULL,
  CONSTRAINT `agent_invitations_logistics_company_id_foreign` FOREIGN KEY (`logistics_company_id`) REFERENCES `logistics_companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `agent_payouts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `agent_payouts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `delivery_agent_id` bigint(20) unsigned DEFAULT NULL,
  `logistics_company_id` bigint(20) unsigned DEFAULT NULL,
  `payout_type` varchar(255) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `status` enum('pending','approved','processing','completed','failed','cancelled') NOT NULL DEFAULT 'pending',
  `payment_method` varchar(255) NOT NULL DEFAULT 'bank_transfer',
  `bank_details` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`bank_details`)),
  `reference_number` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `approved_by` bigint(20) unsigned DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `agent_payouts_approved_by_foreign` (`approved_by`),
  KEY `agent_payouts_delivery_agent_id_status_index` (`delivery_agent_id`,`status`),
  KEY `agent_payouts_logistics_company_id_status_index` (`logistics_company_id`,`status`),
  KEY `agent_payouts_status_index` (`status`),
  CONSTRAINT `agent_payouts_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `agent_payouts_delivery_agent_id_foreign` FOREIGN KEY (`delivery_agent_id`) REFERENCES `delivery_agents` (`id`) ON DELETE CASCADE,
  CONSTRAINT `agent_payouts_logistics_company_id_foreign` FOREIGN KEY (`logistics_company_id`) REFERENCES `logistics_companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `automation_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `automation_settings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `key` varchar(255) NOT NULL,
  `value` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`value`)),
  `type` enum('boolean','integer','decimal','string','array','json') NOT NULL DEFAULT 'string',
  `category` enum('sales_automation','inventory_automation','pricing_automation','notification_automation','marketing_automation') NOT NULL,
  `description` text DEFAULT NULL,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `automation_settings_key_unique` (`key`),
  KEY `automation_settings_category_is_enabled_index` (`category`,`is_enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `banners`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `banners` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `subtitle` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `image_url` varchar(255) NOT NULL,
  `link_url` varchar(255) DEFAULT NULL,
  `button_text` varchar(255) NOT NULL DEFAULT 'Shop Now',
  `bg_color` varchar(255) NOT NULL DEFAULT 'from-blue-600 to-blue-800',
  `position` enum('home','products','both') NOT NULL DEFAULT 'both',
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `start_date` timestamp NULL DEFAULT NULL,
  `end_date` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `banners_is_active_index` (`is_active`),
  KEY `banners_position_index` (`position`),
  KEY `banners_sort_order_index` (`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cache`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cache` (
  `key` varchar(255) NOT NULL,
  `value` mediumtext NOT NULL,
  `expiration` int(11) NOT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cache_locks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cache_locks` (
  `key` varchar(255) NOT NULL,
  `owner` varchar(255) NOT NULL,
  `expiration` int(11) NOT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cart_sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cart_sessions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `session_id` varchar(255) DEFAULT NULL,
  `coupon_id` bigint(20) unsigned DEFAULT NULL,
  `coupon_code` varchar(255) DEFAULT NULL,
  `discount_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `subtotal` decimal(10,2) NOT NULL DEFAULT 0.00,
  `total` decimal(10,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `cart_sessions_coupon_id_foreign` (`coupon_id`),
  KEY `cart_sessions_user_id_session_id_index` (`user_id`,`session_id`),
  CONSTRAINT `cart_sessions_coupon_id_foreign` FOREIGN KEY (`coupon_id`) REFERENCES `coupons` (`id`) ON DELETE SET NULL,
  CONSTRAINT `cart_sessions_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `carts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `carts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `session_id` varchar(255) DEFAULT NULL,
  `product_id` bigint(20) unsigned NOT NULL,
  `variation_id` bigint(20) unsigned DEFAULT NULL,
  `quantity` int(11) NOT NULL,
  `price` decimal(10,2) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `prescription_id` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `carts_product_id_foreign` (`product_id`),
  KEY `carts_user_id_product_id_index` (`user_id`,`product_id`),
  KEY `carts_session_id_product_id_index` (`session_id`,`product_id`),
  KEY `carts_variation_id_foreign` (`variation_id`),
  KEY `carts_prescription_id_foreign` (`prescription_id`),
  CONSTRAINT `carts_prescription_id_foreign` FOREIGN KEY (`prescription_id`) REFERENCES `prescriptions` (`id`) ON DELETE SET NULL,
  CONSTRAINT `carts_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `carts_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `carts_variation_id_foreign` FOREIGN KEY (`variation_id`) REFERENCES `product_variations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `categories` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `slug` varchar(255) NOT NULL,
  `product_type` enum('medication','device','supply','wellness','general') NOT NULL DEFAULT 'general',
  `requires_prescription` tinyint(1) NOT NULL DEFAULT 0,
  `is_controlled_substance` tinyint(1) NOT NULL DEFAULT 0,
  `description` text DEFAULT NULL,
  `image` varchar(255) DEFAULT NULL,
  `icon` varchar(255) DEFAULT NULL,
  `parent_id` bigint(20) unsigned DEFAULT NULL,
  `depth` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `meta_title` varchar(255) DEFAULT NULL,
  `meta_description` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `categories_slug_unique` (`slug`),
  KEY `categories_is_active_sort_order_index` (`is_active`,`sort_order`),
  KEY `categories_parent_id_is_active_index` (`parent_id`,`is_active`),
  KEY `categories_product_type_is_active_index` (`product_type`,`is_active`),
  KEY `categories_requires_prescription_index` (`requires_prescription`),
  CONSTRAINT `categories_parent_id_foreign` FOREIGN KEY (`parent_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `category_attributes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `category_attributes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `category_id` bigint(20) unsigned DEFAULT NULL,
  `key` varchar(255) NOT NULL,
  `label` varchar(255) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `type` enum('text','textarea','number','select','multiselect','boolean','date') NOT NULL DEFAULT 'text',
  `options` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`options`)),
  `unit` varchar(255) DEFAULT NULL,
  `placeholder` varchar(255) DEFAULT NULL,
  `is_required` tinyint(1) NOT NULL DEFAULT 0,
  `is_filterable` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `category_attributes_category_id_key_unique` (`category_id`,`key`),
  KEY `category_attributes_category_id_is_active_sort_order_index` (`category_id`,`is_active`,`sort_order`),
  KEY `category_attributes_is_filterable_is_active_index` (`is_filterable`,`is_active`),
  CONSTRAINT `category_attributes_category_id_foreign` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `coupon_usages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `coupon_usages` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `coupon_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `order_id` bigint(20) unsigned DEFAULT NULL,
  `used_at` timestamp NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `coupon_usages_user_id_foreign` (`user_id`),
  KEY `coupon_usages_order_id_foreign` (`order_id`),
  KEY `coupon_usages_coupon_id_user_id_index` (`coupon_id`,`user_id`),
  CONSTRAINT `coupon_usages_coupon_id_foreign` FOREIGN KEY (`coupon_id`) REFERENCES `coupons` (`id`) ON DELETE CASCADE,
  CONSTRAINT `coupon_usages_order_id_foreign` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE SET NULL,
  CONSTRAINT `coupon_usages_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `coupons`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `coupons` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `store_id` bigint(20) unsigned DEFAULT NULL,
  `code` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `type` enum('percentage','fixed_amount','free_shipping') NOT NULL,
  `value` decimal(10,2) NOT NULL,
  `minimum_amount` decimal(10,2) DEFAULT NULL,
  `maximum_discount` decimal(10,2) DEFAULT NULL,
  `usage_limit` int(11) DEFAULT NULL,
  `used_count` int(11) NOT NULL DEFAULT 0,
  `user_limit` int(11) DEFAULT NULL,
  `valid_from` datetime NOT NULL,
  `valid_until` datetime NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `is_first_order_coupon` tinyint(1) NOT NULL DEFAULT 0,
  `applicable_to` enum('all','specific_products','specific_categories') NOT NULL DEFAULT 'all',
  `applicable_ids` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`applicable_ids`)),
  `exclude_sale_items` tinyint(1) NOT NULL DEFAULT 0,
  `first_order_only` tinyint(1) NOT NULL DEFAULT 0,
  `auto_apply` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `coupons_code_unique` (`code`),
  KEY `coupons_is_active_valid_from_valid_until_index` (`is_active`,`valid_from`,`valid_until`),
  KEY `coupons_auto_apply_index` (`auto_apply`),
  KEY `coupons_store_id_foreign` (`store_id`),
  CONSTRAINT `coupons_store_id_foreign` FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `delivery_agents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `delivery_agents` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `logistics_company_id` bigint(20) unsigned DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `password` varchar(255) DEFAULT NULL,
  `phone` varchar(255) NOT NULL,
  `profile_photo` varchar(255) DEFAULT NULL,
  `vehicle_type` varchar(255) DEFAULT NULL,
  `vehicle_number` varchar(255) DEFAULT NULL,
  `license_number` varchar(255) DEFAULT NULL,
  `bank_name` varchar(255) DEFAULT NULL,
  `account_number` varchar(255) DEFAULT NULL,
  `account_name` varchar(255) DEFAULT NULL,
  `service_areas` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`service_areas`)),
  `status` enum('available','busy','offline','suspended') NOT NULL DEFAULT 'available',
  `is_verified` tinyint(1) NOT NULL DEFAULT 0,
  `verified_at` timestamp NULL DEFAULT NULL,
  `last_active_at` timestamp NULL DEFAULT NULL,
  `rating` decimal(3,2) NOT NULL DEFAULT 0.00,
  `total_deliveries` int(11) NOT NULL DEFAULT 0,
  `pending_balance` decimal(10,2) NOT NULL DEFAULT 0.00,
  `available_balance` decimal(10,2) NOT NULL DEFAULT 0.00,
  `total_earned` decimal(10,2) NOT NULL DEFAULT 0.00,
  `total_paid_out` decimal(10,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `delivery_agents_email_unique` (`email`),
  KEY `delivery_agents_logistics_company_id_foreign` (`logistics_company_id`),
  CONSTRAINT `delivery_agents_logistics_company_id_foreign` FOREIGN KEY (`logistics_company_id`) REFERENCES `logistics_companies` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `delivery_proofs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `delivery_proofs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `order_id` bigint(20) unsigned NOT NULL,
  `delivery_agent_id` bigint(20) unsigned NOT NULL,
  `type` enum('pickup','delivery') NOT NULL,
  `photos` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`photos`)),
  `signature_image` varchar(255) DEFAULT NULL,
  `recipient_name` varchar(255) DEFAULT NULL,
  `recipient_phone` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `location` varchar(255) DEFAULT NULL,
  `verified_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `delivery_proofs_order_id_type_index` (`order_id`,`type`),
  KEY `delivery_proofs_delivery_agent_id_index` (`delivery_agent_id`),
  CONSTRAINT `delivery_proofs_delivery_agent_id_foreign` FOREIGN KEY (`delivery_agent_id`) REFERENCES `delivery_agents` (`id`) ON DELETE CASCADE,
  CONSTRAINT `delivery_proofs_order_id_foreign` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `delivery_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `delivery_settings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `key` varchar(255) NOT NULL,
  `value` text DEFAULT NULL,
  `type` varchar(255) NOT NULL DEFAULT 'string',
  `description` text DEFAULT NULL,
  `group` varchar(255) NOT NULL DEFAULT 'general',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `delivery_settings_key_unique` (`key`),
  KEY `delivery_settings_key_index` (`key`),
  KEY `delivery_settings_group_index` (`group`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `delivery_tracking_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `delivery_tracking_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `order_id` bigint(20) unsigned NOT NULL,
  `status` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `location` varchar(255) DEFAULT NULL,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata`)),
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_by_type` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `delivery_tracking_events_created_by_foreign` (`created_by`),
  KEY `delivery_tracking_events_order_id_created_at_index` (`order_id`,`created_at`),
  KEY `delivery_tracking_events_status_index` (`status`),
  CONSTRAINT `delivery_tracking_events_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `delivery_tracking_events_order_id_foreign` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `email_automation_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `email_automation_settings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `key` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `config` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`config`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email_automation_settings_key_unique` (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `email_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `email_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `email` varchar(255) NOT NULL,
  `type` varchar(255) NOT NULL,
  `subject` varchar(255) NOT NULL,
  `body` text DEFAULT NULL,
  `status` enum('sent','failed','pending') NOT NULL DEFAULT 'pending',
  `error_message` text DEFAULT NULL,
  `sent_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `email_logs_user_id_type_index` (`user_id`,`type`),
  KEY `email_logs_status_index` (`status`),
  CONSTRAINT `email_logs_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `failed_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `failed_jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(255) NOT NULL,
  `connection` text NOT NULL,
  `queue` text NOT NULL,
  `payload` longtext NOT NULL,
  `exception` longtext NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `job_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `job_batches` (
  `id` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `total_jobs` int(11) NOT NULL,
  `pending_jobs` int(11) NOT NULL,
  `failed_jobs` int(11) NOT NULL,
  `failed_job_ids` longtext NOT NULL,
  `options` mediumtext DEFAULT NULL,
  `cancelled_at` int(11) DEFAULT NULL,
  `created_at` int(11) NOT NULL,
  `finished_at` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `queue` varchar(255) NOT NULL,
  `payload` longtext NOT NULL,
  `attempts` tinyint(3) unsigned NOT NULL,
  `reserved_at` int(10) unsigned DEFAULT NULL,
  `available_at` int(10) unsigned NOT NULL,
  `created_at` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `jobs_queue_index` (`queue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `logistics_companies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `logistics_companies` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `code` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `logo` varchar(255) DEFAULT NULL,
  `contact_email` varchar(255) DEFAULT NULL,
  `admin_email` varchar(255) DEFAULT NULL,
  `admin_password` varchar(255) DEFAULT NULL,
  `contact_phone` varchar(255) DEFAULT NULL,
  `bank_name` varchar(255) DEFAULT NULL,
  `account_number` varchar(255) DEFAULT NULL,
  `account_name` varchar(255) DEFAULT NULL,
  `service_areas` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`service_areas`)),
  `pricing_structure` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`pricing_structure`)),
  `pending_balance` decimal(10,2) NOT NULL DEFAULT 0.00,
  `available_balance` decimal(10,2) NOT NULL DEFAULT 0.00,
  `total_earned` decimal(10,2) NOT NULL DEFAULT 0.00,
  `total_paid_out` decimal(10,2) NOT NULL DEFAULT 0.00,
  `commission_percentage` decimal(5,2) NOT NULL DEFAULT 85.00,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `logistics_companies_code_unique` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `migrations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) NOT NULL,
  `batch` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `order_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `order_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `order_id` bigint(20) unsigned NOT NULL,
  `product_id` bigint(20) unsigned NOT NULL,
  `prescription_id` bigint(20) unsigned DEFAULT NULL,
  `required_prescription` tinyint(1) NOT NULL DEFAULT 0,
  `variation_id` bigint(20) unsigned DEFAULT NULL,
  `quantity` int(11) NOT NULL,
  `price` decimal(10,2) NOT NULL,
  `total` decimal(10,2) NOT NULL,
  `product_snapshot` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`product_snapshot`)),
  `variation_snapshot` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Snapshot of variation details at time of purchase' CHECK (json_valid(`variation_snapshot`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `order_items_product_id_foreign` (`product_id`),
  KEY `order_items_order_id_product_id_index` (`order_id`,`product_id`),
  KEY `order_items_variation_id_foreign` (`variation_id`),
  KEY `order_items_prescription_id_index` (`prescription_id`),
  CONSTRAINT `order_items_order_id_foreign` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `order_items_prescription_id_foreign` FOREIGN KEY (`prescription_id`) REFERENCES `prescriptions` (`id`) ON DELETE SET NULL,
  CONSTRAINT `order_items_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `order_items_variation_id_foreign` FOREIGN KEY (`variation_id`) REFERENCES `product_variations` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `order_shipments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `order_shipments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `order_id` bigint(20) unsigned NOT NULL,
  `store_id` bigint(20) unsigned DEFAULT NULL,
  `tracking_number` varchar(255) NOT NULL,
  `logistics_company_id` bigint(20) unsigned DEFAULT NULL,
  `delivery_agent_id` bigint(20) unsigned DEFAULT NULL,
  `pickup_agent_id` bigint(20) unsigned DEFAULT NULL,
  `status` enum('pending','shop_preparing','ready_for_pickup','assigned_to_agent','picked_up','in_transit','arrived_at_hub','out_for_delivery','delivered','failed','returned') NOT NULL DEFAULT 'pending',
  `shipping_fee` decimal(10,2) NOT NULL,
  `weight` decimal(8,2) DEFAULT NULL,
  `dimensions` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`dimensions`)),
  `items` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`items`)),
  `estimated_delivery_days` int(11) DEFAULT NULL,
  `ready_at` timestamp NULL DEFAULT NULL,
  `assigned_at` timestamp NULL DEFAULT NULL,
  `picked_up_at` timestamp NULL DEFAULT NULL,
  `in_transit_at` timestamp NULL DEFAULT NULL,
  `arrived_at_hub_at` timestamp NULL DEFAULT NULL,
  `out_for_delivery_at` timestamp NULL DEFAULT NULL,
  `delivered_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `order_shipments_tracking_number_unique` (`tracking_number`),
  KEY `order_shipments_logistics_company_id_foreign` (`logistics_company_id`),
  KEY `order_shipments_delivery_agent_id_foreign` (`delivery_agent_id`),
  KEY `order_shipments_order_id_status_index` (`order_id`,`status`),
  KEY `order_shipments_store_id_index` (`store_id`),
  KEY `order_shipments_tracking_number_index` (`tracking_number`),
  KEY `order_shipments_status_index` (`status`),
  KEY `order_shipments_pickup_agent_id_foreign` (`pickup_agent_id`),
  CONSTRAINT `order_shipments_delivery_agent_id_foreign` FOREIGN KEY (`delivery_agent_id`) REFERENCES `delivery_agents` (`id`) ON DELETE SET NULL,
  CONSTRAINT `order_shipments_logistics_company_id_foreign` FOREIGN KEY (`logistics_company_id`) REFERENCES `logistics_companies` (`id`) ON DELETE SET NULL,
  CONSTRAINT `order_shipments_order_id_foreign` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `order_shipments_pickup_agent_id_foreign` FOREIGN KEY (`pickup_agent_id`) REFERENCES `delivery_agents` (`id`) ON DELETE SET NULL,
  CONSTRAINT `order_shipments_store_id_foreign` FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `orders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `orders` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `session_id` varchar(255) DEFAULT NULL,
  `order_number` varchar(255) NOT NULL,
  `status` enum('pending','processing','ready_for_pickup','assigned_to_agent','shipped','picked_up','arrived_at_hub','in_transit','out_for_delivery','delivered','cancelled','refunded') NOT NULL DEFAULT 'pending',
  `subtotal` decimal(10,2) NOT NULL,
  `tax_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `shipping_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `shipping_zone_id` bigint(20) unsigned DEFAULT NULL,
  `calculated_shipping_fee` decimal(10,2) NOT NULL DEFAULT 0.00,
  `discount_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `coupon_discount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `sale_discount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `total_amount` decimal(10,2) NOT NULL,
  `currency` varchar(3) NOT NULL DEFAULT 'USD',
  `payment_status` enum('pending','paid','failed','refunded') NOT NULL DEFAULT 'pending',
  `payment_method` varchar(255) DEFAULT NULL,
  `is_pay_on_delivery` tinyint(1) NOT NULL DEFAULT 0,
  `cod_fee` decimal(10,2) NOT NULL DEFAULT 0.00,
  `payment_id` varchar(255) DEFAULT NULL,
  `coupon_id` bigint(20) unsigned DEFAULT NULL,
  `coupon_code` varchar(255) DEFAULT NULL,
  `sale_event_id` bigint(20) unsigned DEFAULT NULL,
  `sale_event_name` varchar(255) DEFAULT NULL,
  `logistics_company_id` bigint(20) unsigned DEFAULT NULL,
  `delivery_agent_id` bigint(20) unsigned DEFAULT NULL,
  `delivery_access_token` varchar(64) DEFAULT NULL,
  `store_id` bigint(20) unsigned DEFAULT NULL,
  `delivery_type` enum('home_delivery','pickup','store_pickup') NOT NULL DEFAULT 'home_delivery',
  `pickup_location` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`pickup_location`)),
  `shipping_address` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`shipping_address`)),
  `billing_address` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`billing_address`)),
  `notes` text DEFAULT NULL,
  `delivery_notes` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`delivery_notes`)),
  `tracking_number` varchar(255) DEFAULT NULL,
  `delivery_code` varchar(6) DEFAULT NULL,
  `assigned_at` timestamp NULL DEFAULT NULL,
  `picked_up_at` timestamp NULL DEFAULT NULL,
  `out_for_delivery_at` timestamp NULL DEFAULT NULL,
  `shipped_at` timestamp NULL DEFAULT NULL,
  `delivered_at` timestamp NULL DEFAULT NULL,
  `delivery_rating` int(11) DEFAULT NULL,
  `delivery_review` text DEFAULT NULL,
  `rated_at` timestamp NULL DEFAULT NULL,
  `cancelled_at` timestamp NULL DEFAULT NULL,
  `refunded_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `requires_prescription` tinyint(1) NOT NULL DEFAULT 0,
  `prescription_status` enum('not_required','pending','approved','rejected') NOT NULL DEFAULT 'not_required',
  PRIMARY KEY (`id`),
  UNIQUE KEY `orders_order_number_unique` (`order_number`),
  KEY `orders_coupon_id_foreign` (`coupon_id`),
  KEY `orders_sale_event_id_foreign` (`sale_event_id`),
  KEY `orders_user_id_status_index` (`user_id`,`status`),
  KEY `orders_status_created_at_index` (`status`,`created_at`),
  KEY `orders_payment_status_index` (`payment_status`),
  KEY `orders_session_id_index` (`session_id`),
  KEY `orders_logistics_company_id_foreign` (`logistics_company_id`),
  KEY `orders_delivery_agent_id_foreign` (`delivery_agent_id`),
  KEY `orders_store_id_foreign` (`store_id`),
  KEY `orders_shipping_zone_id_foreign` (`shipping_zone_id`),
  KEY `orders_delivery_access_token_index` (`delivery_access_token`),
  KEY `orders_prescription_status_index` (`prescription_status`),
  CONSTRAINT `orders_coupon_id_foreign` FOREIGN KEY (`coupon_id`) REFERENCES `coupons` (`id`) ON DELETE SET NULL,
  CONSTRAINT `orders_delivery_agent_id_foreign` FOREIGN KEY (`delivery_agent_id`) REFERENCES `delivery_agents` (`id`) ON DELETE SET NULL,
  CONSTRAINT `orders_logistics_company_id_foreign` FOREIGN KEY (`logistics_company_id`) REFERENCES `logistics_companies` (`id`) ON DELETE SET NULL,
  CONSTRAINT `orders_sale_event_id_foreign` FOREIGN KEY (`sale_event_id`) REFERENCES `sale_events` (`id`) ON DELETE SET NULL,
  CONSTRAINT `orders_shipping_zone_id_foreign` FOREIGN KEY (`shipping_zone_id`) REFERENCES `shipping_zones` (`id`) ON DELETE SET NULL,
  CONSTRAINT `orders_store_id_foreign` FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`) ON DELETE SET NULL,
  CONSTRAINT `orders_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `password_reset_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `password_reset_tokens` (
  `email` varchar(255) NOT NULL,
  `token` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `payment_transactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `payment_transactions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `order_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `transaction_id` varchar(255) DEFAULT NULL,
  `reference` varchar(255) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `currency` varchar(3) NOT NULL DEFAULT 'USD',
  `status` enum('pending','success','failed','abandoned','refunded') NOT NULL,
  `payment_method` varchar(255) DEFAULT NULL,
  `gateway` enum('paystack','stripe','flutterwave') NOT NULL,
  `gateway_response` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`gateway_response`)),
  `fees` decimal(10,2) DEFAULT NULL,
  `authorization_code` varchar(255) DEFAULT NULL,
  `customer_code` varchar(255) DEFAULT NULL,
  `paid_at` timestamp NULL DEFAULT NULL,
  `failed_at` timestamp NULL DEFAULT NULL,
  `failure_reason` text DEFAULT NULL,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `payment_transactions_reference_unique` (`reference`),
  KEY `payment_transactions_user_id_foreign` (`user_id`),
  KEY `payment_transactions_status_gateway_index` (`status`,`gateway`),
  KEY `payment_transactions_order_id_status_index` (`order_id`,`status`),
  CONSTRAINT `payment_transactions_order_id_foreign` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `payment_transactions_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `permissions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `display_name` varchar(255) NOT NULL,
  `group` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `permissions_name_unique` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `prescriptions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `prescriptions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `session_id` varchar(255) DEFAULT NULL,
  `order_id` bigint(20) unsigned DEFAULT NULL,
  `store_id` bigint(20) unsigned DEFAULT NULL,
  `file_path` varchar(255) NOT NULL,
  `original_filename` varchar(255) DEFAULT NULL,
  `mime_type` varchar(255) DEFAULT NULL,
  `file_size` int(10) unsigned DEFAULT NULL,
  `patient_name` varchar(255) DEFAULT NULL,
  `doctor_name` varchar(255) DEFAULT NULL,
  `doctor_license` varchar(255) DEFAULT NULL,
  `hospital_name` varchar(255) DEFAULT NULL,
  `issued_date` date DEFAULT NULL,
  `expires_at` date DEFAULT NULL,
  `status` enum('pending','approved','rejected','expired') NOT NULL DEFAULT 'pending',
  `reviewed_by_type` enum('store','platform') DEFAULT NULL,
  `reviewed_by` bigint(20) unsigned DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `prescriptions_order_id_foreign` (`order_id`),
  KEY `prescriptions_store_id_foreign` (`store_id`),
  KEY `prescriptions_reviewed_by_foreign` (`reviewed_by`),
  KEY `prescriptions_status_store_id_index` (`status`,`store_id`),
  KEY `prescriptions_user_id_status_index` (`user_id`,`status`),
  KEY `prescriptions_session_id_index` (`session_id`),
  KEY `prescriptions_expires_at_index` (`expires_at`),
  CONSTRAINT `prescriptions_order_id_foreign` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE SET NULL,
  CONSTRAINT `prescriptions_reviewed_by_foreign` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `prescriptions_store_id_foreign` FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`) ON DELETE SET NULL,
  CONSTRAINT `prescriptions_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pricing_configurations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `pricing_configurations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `category` varchar(255) DEFAULT NULL,
  `type` enum('percentage','fixed_amount') NOT NULL DEFAULT 'percentage',
  `value` decimal(10,2) NOT NULL,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `priority` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `product_attribute_values`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `product_attribute_values` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `product_id` bigint(20) unsigned NOT NULL,
  `category_attribute_id` bigint(20) unsigned NOT NULL,
  `value` text DEFAULT NULL,
  `value_number` decimal(15,4) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `pav_product_attribute_unique` (`product_id`,`category_attribute_id`),
  KEY `pav_attribute_number_index` (`category_attribute_id`,`value_number`),
  KEY `product_attribute_values_product_id_index` (`product_id`),
  KEY `pav_attribute_value_index` (`category_attribute_id`,`value`(191)),
  CONSTRAINT `product_attribute_values_category_attribute_id_foreign` FOREIGN KEY (`category_attribute_id`) REFERENCES `category_attributes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `product_attribute_values_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `product_images`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `product_images` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `product_id` bigint(20) unsigned NOT NULL,
  `image_path` varchar(255) NOT NULL,
  `image_url` varchar(255) DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_primary` tinyint(1) NOT NULL DEFAULT 0,
  `alt_text` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `product_images_product_id_is_primary_index` (`product_id`,`is_primary`),
  KEY `product_images_product_id_sort_order_index` (`product_id`,`sort_order`),
  CONSTRAINT `product_images_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `product_variations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `product_variations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `product_id` bigint(20) unsigned NOT NULL,
  `sku` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `strength` varchar(255) DEFAULT NULL,
  `pack_size` varchar(255) DEFAULT NULL,
  `dosage_form` varchar(255) DEFAULT NULL,
  `batch_number` varchar(255) DEFAULT NULL,
  `expiry_date` date DEFAULT NULL,
  `other_specs` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Additional specifications in JSON format' CHECK (json_valid(`other_specs`)),
  `price` decimal(10,2) NOT NULL,
  `sale_price` decimal(10,2) DEFAULT NULL,
  `stock_quantity` int(11) NOT NULL DEFAULT 0,
  `weight` decimal(8,2) DEFAULT NULL,
  `images` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Array of image URLs specific to this variation' CHECK (json_valid(`images`)),
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `product_variations_sku_unique` (`sku`),
  KEY `product_variations_product_id_index` (`product_id`),
  KEY `product_variations_is_active_index` (`is_active`),
  KEY `product_variations_sort_order_index` (`sort_order`),
  KEY `product_variations_expiry_date_index` (`expiry_date`),
  CONSTRAINT `product_variations_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `products`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `products` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `store_id` bigint(20) unsigned DEFAULT NULL,
  `category_id` bigint(20) unsigned DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `generic_name` varchar(255) DEFAULT NULL,
  `brand_name` varchar(255) DEFAULT NULL,
  `manufacturer` varchar(255) DEFAULT NULL,
  `active_ingredients` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`active_ingredients`)),
  `description` text NOT NULL,
  `short_description` text DEFAULT NULL,
  `price` decimal(10,2) NOT NULL,
  `sale_price` decimal(10,2) DEFAULT NULL,
  `sku` varchar(255) NOT NULL,
  `stock_quantity` int(11) NOT NULL DEFAULT 0,
  `has_variations` tinyint(1) NOT NULL DEFAULT 0,
  `default_variation_id` bigint(20) unsigned DEFAULT NULL,
  `average_rating` decimal(3,2) NOT NULL DEFAULT 0.00,
  `rating_count` int(11) NOT NULL DEFAULT 0,
  `is_featured` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `payment_method_restriction` varchar(255) NOT NULL DEFAULT 'any',
  `meta_title` varchar(255) DEFAULT NULL,
  `meta_description` text DEFAULT NULL,
  `images` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`images`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `highlighted_features` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`highlighted_features`)),
  `weight_kg` decimal(8,2) DEFAULT NULL,
  `package_dimensions` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`package_dimensions`)),
  `free_shipping` tinyint(1) NOT NULL DEFAULT 0,
  `strength` varchar(255) DEFAULT NULL,
  `dosage_form` varchar(255) DEFAULT NULL,
  `pack_size` varchar(255) DEFAULT NULL,
  `route_of_administration` varchar(255) DEFAULT NULL,
  `requires_prescription` tinyint(1) NOT NULL DEFAULT 0,
  `is_controlled_substance` tinyint(1) NOT NULL DEFAULT 0,
  `drug_schedule` varchar(255) DEFAULT NULL,
  `nafdac_number` varchar(255) DEFAULT NULL,
  `storage_conditions` text DEFAULT NULL,
  `batch_number` varchar(255) DEFAULT NULL,
  `expiry_date` date DEFAULT NULL,
  `directions_for_use` text DEFAULT NULL,
  `side_effects` text DEFAULT NULL,
  `warnings` text DEFAULT NULL,
  `contraindications` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `products_sku_unique` (`sku`),
  KEY `products_is_active_is_featured_index` (`is_active`,`is_featured`),
  KEY `products_price_sale_price_index` (`price`,`sale_price`),
  KEY `products_average_rating_index` (`average_rating`),
  KEY `products_store_id_foreign` (`store_id`),
  KEY `products_default_variation_id_foreign` (`default_variation_id`),
  KEY `products_category_id_is_active_index` (`category_id`,`is_active`),
  KEY `products_requires_prescription_is_active_index` (`requires_prescription`,`is_active`),
  KEY `products_expiry_date_index` (`expiry_date`),
  KEY `products_generic_name_index` (`generic_name`),
  CONSTRAINT `products_category_id_foreign` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL,
  CONSTRAINT `products_default_variation_id_foreign` FOREIGN KEY (`default_variation_id`) REFERENCES `product_variations` (`id`) ON DELETE SET NULL,
  CONSTRAINT `products_store_id_foreign` FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `reviews`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `reviews` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `product_id` bigint(20) unsigned NOT NULL,
  `rating` tinyint(3) unsigned NOT NULL,
  `title` varchar(255) DEFAULT NULL,
  `comment` text NOT NULL,
  `is_verified_purchase` tinyint(1) NOT NULL DEFAULT 0,
  `is_approved` tinyint(1) NOT NULL DEFAULT 0,
  `helpful_count` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `reviews_user_id_product_id_unique` (`user_id`,`product_id`),
  KEY `reviews_product_id_is_approved_index` (`product_id`,`is_approved`),
  KEY `reviews_user_id_product_id_index` (`user_id`,`product_id`),
  KEY `reviews_rating_index` (`rating`),
  CONSTRAINT `reviews_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `reviews_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `role_permission`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `role_permission` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `role_id` bigint(20) unsigned NOT NULL,
  `permission_id` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `role_permission_role_id_permission_id_unique` (`role_id`,`permission_id`),
  KEY `role_permission_permission_id_foreign` (`permission_id`),
  CONSTRAINT `role_permission_permission_id_foreign` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `role_permission_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `roles` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `display_name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `roles_name_unique` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sale_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sale_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `store_id` bigint(20) unsigned DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `type` varchar(100) NOT NULL,
  `discount_type` enum('percentage','fixed_amount') NOT NULL,
  `discount_value` decimal(10,2) NOT NULL,
  `start_date` datetime NOT NULL,
  `end_date` datetime NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `banner_image` varchar(500) DEFAULT NULL,
  `banner_text` varchar(255) DEFAULT NULL,
  `applicable_to` enum('all','specific_products','specific_categories') NOT NULL DEFAULT 'all',
  `applicable_ids` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`applicable_ids`)),
  `minimum_purchase` decimal(10,2) DEFAULT NULL,
  `maximum_discount` decimal(10,2) DEFAULT NULL,
  `auto_activate` tinyint(1) NOT NULL DEFAULT 0,
  `priority` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `sale_events_is_active_start_date_end_date_index` (`is_active`,`start_date`,`end_date`),
  KEY `sale_events_type_priority_index` (`type`,`priority`),
  KEY `sale_events_store_id_foreign` (`store_id`),
  CONSTRAINT `sale_events_store_id_foreign` FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sessions` (
  `id` varchar(255) NOT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `payload` longtext NOT NULL,
  `last_activity` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `sessions_user_id_index` (`user_id`),
  KEY `sessions_last_activity_index` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `shipping_rates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shipping_rates` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `logistics_company_id` bigint(20) unsigned DEFAULT NULL,
  `from_state` varchar(255) NOT NULL,
  `to_state` varchar(255) NOT NULL,
  `base_rate` decimal(10,2) NOT NULL,
  `per_kg_rate` decimal(10,2) NOT NULL DEFAULT 0.00,
  `weight_threshold` decimal(8,2) NOT NULL DEFAULT 5.00,
  `estimated_days_min` int(11) NOT NULL DEFAULT 1,
  `estimated_days_max` int(11) NOT NULL DEFAULT 7,
  `is_interstate` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `priority` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `shipping_rates_route_company_unique` (`from_state`,`to_state`,`logistics_company_id`),
  KEY `shipping_rates_from_state_to_state_index` (`from_state`,`to_state`),
  KEY `shipping_rates_is_active_index` (`is_active`),
  KEY `shipping_rates_logistics_company_id_index` (`logistics_company_id`),
  CONSTRAINT `shipping_rates_logistics_company_id_foreign` FOREIGN KEY (`logistics_company_id`) REFERENCES `logistics_companies` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `shipping_zones`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shipping_zones` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `origin_state` varchar(255) DEFAULT NULL,
  `type` enum('intrastate','interstate','any') NOT NULL DEFAULT 'any',
  `state` varchar(255) NOT NULL,
  `cities` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`cities`)),
  `postal_codes` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`postal_codes`)),
  `shipping_fee` decimal(10,2) NOT NULL DEFAULT 0.00,
  `is_free_shipping` tinyint(1) NOT NULL DEFAULT 0,
  `estimated_delivery_days` int(11) NOT NULL DEFAULT 3,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `store_payouts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `store_payouts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `store_id` bigint(20) unsigned NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `commission_deducted` decimal(10,2) NOT NULL DEFAULT 0.00,
  `net_amount` decimal(10,2) NOT NULL,
  `status` enum('pending','processing','completed','failed') NOT NULL DEFAULT 'pending',
  `requested_at` timestamp NULL DEFAULT NULL,
  `payout_method` varchar(255) DEFAULT NULL,
  `payout_details` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`payout_details`)),
  `notes` text DEFAULT NULL,
  `processed_at` timestamp NULL DEFAULT NULL,
  `payment_receipt` varchar(255) DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `store_payouts_store_id_foreign` (`store_id`),
  CONSTRAINT `store_payouts_store_id_foreign` FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `stores`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `stores` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `owner_id` bigint(20) unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  `slug` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `logo` varchar(255) DEFAULT NULL,
  `banner` varchar(255) DEFAULT NULL,
  `phone` varchar(255) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `pharmacy_license_number` varchar(255) DEFAULT NULL,
  `pharmacy_license_expiry` date DEFAULT NULL,
  `pharmacy_license_document` varchar(255) DEFAULT NULL,
  `premises_registration_number` varchar(255) DEFAULT NULL,
  `account_name` varchar(255) DEFAULT NULL,
  `account_number` varchar(255) DEFAULT NULL,
  `bank_name` varchar(255) DEFAULT NULL,
  `bank_code` varchar(255) DEFAULT NULL,
  `address` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`address`)),
  `state` varchar(255) DEFAULT NULL COMMENT 'Nigerian state where store is located',
  `city` varchar(255) DEFAULT NULL COMMENT 'City where store is located',
  `business_hours` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`business_hours`)),
  `commission_rate` decimal(5,2) NOT NULL DEFAULT 0.00,
  `status` enum('active','inactive','suspended') NOT NULL DEFAULT 'active',
  `settings` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`settings`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `superintendent_pharmacist_name` varchar(255) DEFAULT NULL,
  `superintendent_pharmacist_license` varchar(255) DEFAULT NULL,
  `superintendent_pharmacist_phone` varchar(255) DEFAULT NULL,
  `verification_status` enum('unsubmitted','pending','approved','rejected') NOT NULL DEFAULT 'unsubmitted',
  `verified_at` timestamp NULL DEFAULT NULL,
  `verified_by` bigint(20) unsigned DEFAULT NULL,
  `verification_notes` text DEFAULT NULL,
  `can_sell_prescription` tinyint(1) NOT NULL DEFAULT 0,
  `can_sell_controlled` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `stores_slug_unique` (`slug`),
  KEY `stores_owner_id_foreign` (`owner_id`),
  KEY `stores_verified_by_foreign` (`verified_by`),
  KEY `stores_verification_status_index` (`verification_status`),
  KEY `stores_pharmacy_license_expiry_index` (`pharmacy_license_expiry`),
  CONSTRAINT `stores_owner_id_foreign` FOREIGN KEY (`owner_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `stores_verified_by_foreign` FOREIGN KEY (`verified_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `system_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `system_settings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `category` varchar(100) NOT NULL,
  `key` varchar(100) NOT NULL,
  `value` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`value`)),
  `label` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `type` enum('array','string','boolean','number') NOT NULL DEFAULT 'array',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `system_settings_category_key_unique` (`category`,`key`),
  KEY `system_settings_category_index` (`category`),
  KEY `system_settings_key_index` (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `google_id` varchar(255) DEFAULT NULL,
  `avatar` varchar(255) DEFAULT NULL,
  `phone` varchar(255) DEFAULT NULL,
  `role_id` bigint(20) unsigned DEFAULT NULL,
  `store_id` bigint(20) unsigned DEFAULT NULL,
  `role` varchar(50) DEFAULT 'customer',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `email_verification_token` varchar(255) DEFAULT NULL,
  `email_verification_sent_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `remember_token` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email_unique` (`email`),
  UNIQUE KEY `users_google_id_unique` (`google_id`),
  KEY `users_role_id_foreign` (`role_id`),
  KEY `users_store_id_foreign` (`store_id`),
  CONSTRAINT `users_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE SET NULL,
  CONSTRAINT `users_store_id_foreign` FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `wishlists`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `wishlists` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `product_id` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `wishlists_user_id_product_id_unique` (`user_id`,`product_id`),
  KEY `wishlists_product_id_foreign` (`product_id`),
  CONSTRAINT `wishlists_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `wishlists_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*M!100616 SET NOTE_VERBOSITY=@OLD_NOTE_VERBOSITY */;

/*!999999\- enable the sandbox mode */ 
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (1,'0001_01_01_000000_create_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (2,'0001_01_01_000001_create_cache_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (3,'0001_01_01_000002_create_jobs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (4,'2024_01_01_000001_create_categories_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (5,'2024_01_01_000002_create_products_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (6,'2024_01_01_000003_create_coupons_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (7,'2024_01_01_000004_create_sale_events_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (8,'2024_01_01_000006_create_reviews_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (9,'2024_01_01_000007_create_carts_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (10,'2024_01_01_000008_create_wishlists_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (11,'2024_01_01_000009_create_orders_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (12,'2024_01_01_000010_create_order_items_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (13,'2024_01_01_000011_create_payment_transactions_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (14,'2024_01_01_000012_create_automation_settings_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (15,'2024_01_01_000013_add_role_to_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (16,'2024_01_01_000015_create_coupon_usages_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (17,'2024_11_19_000000_create_system_settings_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (18,'2024_11_20_000000_add_missing_product_attributes',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (19,'2024_11_20_000001_update_existing_products_with_defaults',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (20,'2024_11_28_010000_change_products_category_to_string',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (21,'2024_11_28_200000_create_roles_and_permissions_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (22,'2024_11_30_000001_populate_product_categories',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (23,'2024_11_30_000001_update_users_role_enum',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (24,'2024_11_30_000002_add_rating_fields_to_products_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (25,'2024_12_01_000001_add_session_id_to_orders_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (26,'2024_12_22_000001_update_products_for_phone_gadgets',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (27,'2025_11_20_041357_add_phone_and_is_active_to_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (28,'2025_11_22_011510_create_cart_sessions_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (29,'2025_11_22_031533_make_user_id_nullable_in_orders_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (30,'2025_11_22_051011_make_user_id_nullable_in_payment_transactions_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (31,'2025_11_23_001210_add_sale_event_name_to_orders_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (32,'2025_11_23_140500_create_addresses_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (33,'2025_11_28_010700_drop_categories_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (34,'2025_12_02_003639_add_email_verification_to_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (35,'2025_12_02_003734_create_email_automation_settings_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (36,'2025_12_02_003800_create_email_logs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (37,'2025_12_02_041328_update_orders_user_foreign_key_to_set_null',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (38,'2025_12_06_000001_modify_banner_image_column_in_sale_events',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (39,'2025_12_06_000002_modify_type_column_in_sale_events',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (40,'2025_12_19_000001_create_stores_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (41,'2025_12_19_000002_create_logistics_companies_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (42,'2025_12_19_000003_create_delivery_agents_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (43,'2025_12_19_000004_create_shipping_zones_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (44,'2025_12_19_000005_create_pricing_configurations_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (45,'2025_12_19_000006_add_store_and_delivery_fields_to_products',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (46,'2025_12_19_000007_add_delivery_and_payment_fields_to_orders',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (47,'2025_12_19_000008_create_store_payouts_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (48,'2025_12_19_000009_add_store_role_to_users',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (49,'2025_12_19_000010_add_store_id_to_coupons_and_sales',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (50,'2025_12_19_000011_create_product_images_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (51,'2025_12_22_235410_add_delivery_access_token_to_orders_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (52,'2025_12_23_000001_add_missing_gadget_fields_to_products',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (53,'2025_12_23_000002_update_store_owners_role_id',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (54,'2025_12_24_103509_add_bank_details_to_stores_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (55,'2025_12_24_103549_add_payment_receipt_to_store_payouts_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (56,'2025_12_25_000001_remove_store_verification_columns',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (57,'2025_12_26_000001_add_highlighted_features_to_products',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (58,'2026_01_01_221500_add_missing_columns_to_store_payouts_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (59,'2026_01_13_034300_add_payment_method_restriction_to_products',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (60,'2026_01_19_create_banners_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (61,'2026_01_27_000001_create_product_variations_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (62,'2026_01_27_000002_add_variation_to_carts_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (63,'2026_01_27_000003_add_variation_to_order_items_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (64,'2026_01_27_add_state_to_stores_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (65,'2026_01_30_000002_rename_storage_to_storage_capacity_in_variations',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (66,'2026_01_31_000001_create_delivery_tracking_events_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (67,'2026_01_31_000002_create_delivery_proofs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (68,'2026_01_31_000003_create_agent_payouts_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (69,'2026_01_31_000004_create_agent_earnings_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (70,'2026_01_31_000005_create_shipping_rates_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (71,'2026_01_31_000006_create_delivery_settings_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (72,'2026_01_31_000007_create_order_shipments_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (73,'2026_01_31_000008_create_agent_invitations_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (74,'2026_01_31_000009_create_agent_documents_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (75,'2026_01_31_000010_add_delivery_rating_to_orders_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (76,'2026_01_31_000011_enhance_delivery_agents_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (77,'2026_01_31_000012_enhance_logistics_companies_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (78,'2026_02_06_000001_add_origin_to_shipping_zones',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (79,'2026_02_06_150800_add_ready_for_pickup_status_to_orders',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (80,'2026_02_07_225054_add_delivery_statuses_to_orders_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (81,'2026_02_10_130000_make_store_id_nullable_on_order_shipments',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (82,'2026_02_11_230000_add_logistics_company_id_to_shipping_rates_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (83,'2026_02_12_100000_add_assigned_to_agent_status_to_orders_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (84,'2026_02_12_140000_add_arrived_at_hub_status_to_orders_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (85,'2026_02_15_070000_add_delivery_code_to_orders_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (86,'2026_02_18_add_google_id_to_users',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (87,'2026_02_18_add_is_first_order_coupon_to_coupons',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (88,'2026_02_19_add_pickup_agent_to_shipments',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (89,'2026_02_21_add_agreed_rate_to_agent_earnings',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (90,'2026_03_02_add_store_id_to_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (91,'2026_03_02_fix_users_role_to_varchar',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (92,'2026_08_17_000001_extend_categories_for_pharmacy',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (93,'2026_08_17_000002_create_category_attributes_table',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (94,'2026_08_17_000003_create_product_attribute_values_table',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (95,'2026_08_17_000004_transform_products_for_pharmacy',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (96,'2026_08_17_000005_transform_product_variations_for_pharmacy',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (97,'2026_08_17_000006_add_pharmacy_licensing_to_stores',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (98,'2026_08_17_000007_create_prescriptions_table',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (99,'2026_08_17_000008_link_prescriptions_to_order_items',2);
