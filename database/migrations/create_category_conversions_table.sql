-- 创建类别转换表
CREATE TABLE `category_conversions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tax_standard_value` varchar(255) NOT NULL COMMENT '税务代缴数据口径（标准值）',
  `medical_export_values` text COMMENT '医保数据导出对象口径（多个值用逗号分隔）',
  `national_dict_values` text COMMENT '国家字典值名称（多个值用逗号分隔）',
  `description` text COMMENT '描述信息',
  `status` enum('active','inactive') DEFAULT 'active' COMMENT '状态：active-启用，inactive-禁用',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_tax_standard_value` (`tax_standard_value`),
  KEY `idx_status` (`status`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='类别转换配置表';

-- 创建医保数据导出对象口径映射表（用于快速查询）
CREATE TABLE `medical_export_mappings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `category_conversion_id` int(11) NOT NULL COMMENT '类别转换ID',
  `medical_export_value` varchar(255) NOT NULL COMMENT '医保数据导出对象口径值',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_medical_export_value` (`medical_export_value`),
  KEY `idx_category_conversion_id` (`category_conversion_id`),
  CONSTRAINT `fk_medical_export_category_conversion` FOREIGN KEY (`category_conversion_id`) REFERENCES `category_conversions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='医保数据导出对象口径映射表';

-- 创建国家字典值名称映射表（用于快速查询）
CREATE TABLE `national_dict_mappings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `category_conversion_id` int(11) NOT NULL COMMENT '类别转换ID',
  `national_dict_value` varchar(255) NOT NULL COMMENT '国家字典值名称',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_national_dict_value` (`national_dict_value`),
  KEY `idx_category_conversion_id` (`category_conversion_id`),
  CONSTRAINT `fk_national_dict_category_conversion` FOREIGN KEY (`category_conversion_id`) REFERENCES `category_conversions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='国家字典值名称映射表'; 