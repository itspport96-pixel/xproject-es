-- ESQUEMA DE ESTRUCTURA (solo DDL, sin filas).
-- GENERADO DESDE LA BD VIVA: no editar a mano.
-- Regenerar: php bin/regenerate-schema.php
-- Verificar: php bin/check-schema-drift.php


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `permissions` (
  `id` char(36) NOT NULL,
  `identificador` varchar(255) NOT NULL,
  `nombre` varchar(100) NOT NULL,
  `descripcion` text DEFAULT NULL,
  `modulo` varchar(50) DEFAULT NULL,
  `recurso` varchar(50) DEFAULT NULL,
  `accion` varchar(50) DEFAULT NULL,
  `criticidad` enum('LOW','MEDIUM','HIGH','CRITICAL') DEFAULT 'MEDIUM',
  `requiere_mfa` tinyint(4) DEFAULT 0,
  `activo` tinyint(4) DEFAULT 1,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `identificador` (`identificador`),
  KEY `idx_modulo` (`modulo`),
  KEY `idx_identificador` (`identificador`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `roles` (
  `id` char(36) NOT NULL,
  `nombre` varchar(100) NOT NULL,
  `descripcion` text DEFAULT NULL,
  `tipo` enum('BUILT_IN','CUSTOM') DEFAULT 'CUSTOM',
  `tenant_id` char(36) DEFAULT NULL,
  `activo` tinyint(4) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  `nombre_activo` varchar(100) GENERATED ALWAYS AS (if(`deleted_at` is null,`nombre`,NULL)) STORED,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_roles_tenant_nombre_activo` (`tenant_id`,`nombre_activo`),
  KEY `idx_tipo_activo` (`tipo`,`activo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `role_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `role_permissions` (
  `role_id` char(36) NOT NULL,
  `permission_id` char(36) NOT NULL,
  PRIMARY KEY (`role_id`,`permission_id`),
  KEY `fk_rp_permission` (`permission_id`),
  CONSTRAINT `fk_rp_permission` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_rp_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` char(36) NOT NULL,
  `tenant_id` char(36) NOT NULL,
  `email` varchar(255) NOT NULL,
  `email_hmac` char(64) DEFAULT NULL,
  `nombre` varchar(100) DEFAULT NULL,
  `apellido` varchar(100) DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `estado` enum('ACTIVO','INACTIVO','SUSPENDIDO','PENDIENTE_CONFIRMACION') DEFAULT 'ACTIVO',
  `email_verificado` tinyint(4) DEFAULT 0,
  `telefono` varchar(255) DEFAULT NULL,
  `avatar_url` varchar(255) DEFAULT NULL,
  `ultimo_login` timestamp NULL DEFAULT NULL,
  `fecha_expiracion_password` timestamp NULL DEFAULT NULL,
  `mfa_habilitado` tinyint(4) DEFAULT 0,
  `mfa_metodo` enum('TOTP','EMAIL','SMS') DEFAULT NULL,
  `mfa_secret` varchar(255) DEFAULT NULL,
  `mfa_backup_codes` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`mfa_backup_codes`)),
  `preferencias` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`preferencias`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_tenant_email_hmac` (`tenant_id`,`email_hmac`),
  KEY `idx_tenant_estado` (`tenant_id`,`estado`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `password_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `password_history` (
  `id` char(36) NOT NULL,
  `tenant_id` char(36) NOT NULL,
  `user_id` char(36) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_password_history_user` (`user_id`,`created_at`),
  CONSTRAINT `fk_password_history_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `temporary_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `temporary_permissions` (
  `id` char(36) NOT NULL,
  `user_id` char(36) NOT NULL,
  `permission_id` char(36) NOT NULL,
  `recurso_id` varchar(255) DEFAULT NULL,
  `granted_by_user_id` char(36) DEFAULT NULL,
  `motivo` text DEFAULT NULL,
  `fecha_inicio` timestamp NOT NULL DEFAULT current_timestamp(),
  `fecha_expiracion` datetime NOT NULL,
  `activo` tinyint(4) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user_expiracion` (`user_id`,`fecha_expiracion`),
  KEY `idx_activo` (`activo`),
  KEY `permission_id` (`permission_id`),
  KEY `granted_by_user_id` (`granted_by_user_id`),
  CONSTRAINT `temporary_permissions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `temporary_permissions_ibfk_2` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`),
  CONSTRAINT `temporary_permissions_ibfk_3` FOREIGN KEY (`granted_by_user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tenants`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `tenants` (
  `id` char(36) NOT NULL,
  `nombre_publico` varchar(255) NOT NULL,
  `nombre_interno` varchar(255) NOT NULL,
  `nombre_interno_hmac` char(64) DEFAULT NULL,
  `estado` enum('ACTIVO','SUSPENDIDO','PENDIENTE_ACTIVACION','CANCELED') DEFAULT 'ACTIVO',
  `plan` enum('FREE','STARTER','PRO','ENTERPRISE') DEFAULT 'FREE',
  `owner_user_id` char(36) DEFAULT NULL,
  `fecha_creacion` timestamp NOT NULL DEFAULT current_timestamp(),
  `fecha_expiracion` timestamp NULL DEFAULT NULL,
  `email_contacto` varchar(512) DEFAULT NULL,
  `telefono` varchar(255) DEFAULT NULL,
  `pais` varchar(100) DEFAULT NULL,
  `zona_horaria` varchar(50) DEFAULT NULL,
  `idioma_default` varchar(10) DEFAULT NULL,
  `logo_url` varchar(255) DEFAULT NULL,
  `dominio_custom` varchar(255) DEFAULT NULL,
  `direccion` varchar(500) DEFAULT NULL,
  `color_primario` varchar(7) DEFAULT NULL,
  `moneda` varchar(10) DEFAULT NULL,
  `formato_fecha` varchar(20) DEFAULT NULL,
  `caducidad_password_dias` int(10) unsigned DEFAULT NULL,
  `smtp_host` varchar(255) DEFAULT NULL,
  `smtp_puerto` int(10) unsigned DEFAULT NULL,
  `smtp_encryption` varchar(10) DEFAULT NULL,
  `smtp_username` varchar(255) DEFAULT NULL,
  `smtp_password` varchar(1024) DEFAULT NULL,
  `smtp_from_email` varchar(255) DEFAULT NULL,
  `smtp_from_name` varchar(255) DEFAULT NULL,
  `metadatos` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadatos`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `dominio_custom` (`dominio_custom`),
  UNIQUE KEY `uq_tenants_nombre_interno_hmac` (`nombre_interno_hmac`),
  KEY `idx_plan` (`plan`),
  KEY `idx_estado` (`estado`),
  KEY `owner_user_id` (`owner_user_id`),
  CONSTRAINT `tenants_ibfk_1` FOREIGN KEY (`owner_user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `audit_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `audit_logs` (
  `id` char(36) NOT NULL,
  `tenant_id` char(36) NOT NULL,
  `usuario_id` char(36) DEFAULT NULL,
  `operacion` varchar(100) DEFAULT NULL,
  `modulo` varchar(50) DEFAULT NULL,
  `recurso` varchar(100) DEFAULT NULL,
  `tipo_cambio` enum('CREATE','READ','UPDATE','DELETE','EXECUTE') DEFAULT 'EXECUTE',
  `valores_anteriores` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`valores_anteriores`)),
  `valores_nuevos` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`valores_nuevos`)),
  `resultado` enum('SUCCESS','FAILURE') DEFAULT 'SUCCESS',
  `razon_fallo` text DEFAULT NULL,
  `ip_address` varchar(255) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `dispositivo_id` varchar(255) DEFAULT NULL,
  `timestamp` timestamp(6) NOT NULL DEFAULT current_timestamp(6),
  `duracion_operacion` int(11) DEFAULT NULL,
  `detalles_adicionales` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`detalles_adicionales`)),
  `entidad_id` varchar(64) DEFAULT NULL,
  `request_id` varchar(64) DEFAULT NULL,
  `hash_integridad` varchar(64) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_tenant_timestamp` (`tenant_id`,`timestamp`),
  KEY `idx_usuario_timestamp` (`tenant_id`,`usuario_id`,`timestamp`),
  KEY `idx_recurso` (`tenant_id`,`recurso`),
  KEY `idx_modulo` (`tenant_id`,`modulo`),
  KEY `idx_resultado` (`tenant_id`,`resultado`),
  CONSTRAINT `audit_logs_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `idempotency_keys`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `idempotency_keys` (
  `id` char(36) NOT NULL,
  `tenant_id` char(36) NOT NULL,
  `idempotency_key` varchar(64) NOT NULL,
  `operacion` varchar(64) NOT NULL,
  `request_hash` char(64) NOT NULL,
  `response_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`response_json`)),
  `http_status` smallint(6) DEFAULT NULL,
  `actor_id` char(36) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_idempotency` (`tenant_id`,`idempotency_key`,`operacion`),
  KEY `idx_idempotency_created` (`created_at`),
  KEY `fk_idempotency_actor` (`actor_id`),
  CONSTRAINT `fk_idempotency_actor` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_idempotency_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `locations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `locations` (
  `id` char(36) NOT NULL,
  `tenant_id` char(36) NOT NULL,
  `codigo` varchar(100) NOT NULL,
  `nombre` varchar(255) DEFAULT NULL,
  `tipo` enum('ALMACENAMIENTO','PICKING','DAÑADOS','BLOQUEADOS','TRANSITO','DEVOLUCION') DEFAULT 'ALMACENAMIENTO',
  `jerarquia` varchar(255) DEFAULT NULL,
  `capacidad` int(11) DEFAULT NULL,
  `capacidad_usada` int(11) DEFAULT 0,
  `activa` tinyint(4) DEFAULT 1,
  `metadatos` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadatos`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  `codigo_activo` varchar(100) GENERATED ALWAYS AS (if(`deleted_at` is null,`codigo`,NULL)) STORED,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_locations_tenant_codigo_activo` (`tenant_id`,`codigo_activo`),
  KEY `idx_tenant_tipo` (`tenant_id`,`tipo`),
  KEY `idx_tenant_activa` (`tenant_id`,`activa`),
  CONSTRAINT `locations_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `inventory_counts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `inventory_counts` (
  `id` char(36) NOT NULL,
  `tenant_id` char(36) NOT NULL,
  `ubicacion_id` char(36) DEFAULT NULL,
  `estado` enum('PENDIENTE','EN_CURSO','COMPLETADO','RECONCILIADO') DEFAULT 'PENDIENTE',
  `usuario_responsable_id` char(36) DEFAULT NULL,
  `fecha_conteo` timestamp NOT NULL DEFAULT current_timestamp(),
  `lista_teorica` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`lista_teorica`)),
  `resultados` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`resultados`)),
  `discrepancias` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`discrepancias`)),
  `reconciliado` tinyint(4) DEFAULT 0,
  `ajustes_aplicados` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`ajustes_aplicados`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_tenant_estado` (`tenant_id`,`estado`),
  KEY `ubicacion_id` (`ubicacion_id`),
  KEY `usuario_responsable_id` (`usuario_responsable_id`),
  KEY `idx_tenant_fecha` (`tenant_id`,`fecha_conteo`),
  CONSTRAINT `inventory_counts_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`),
  CONSTRAINT `inventory_counts_ibfk_2` FOREIGN KEY (`ubicacion_id`) REFERENCES `locations` (`id`),
  CONSTRAINT `inventory_counts_ibfk_3` FOREIGN KEY (`usuario_responsable_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `password_reset_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `password_reset_tokens` (
  `id` char(36) NOT NULL,
  `tenant_id` char(36) NOT NULL,
  `user_id` char(36) NOT NULL,
  `token_hash` varchar(64) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_prt_token_hash` (`token_hash`),
  KEY `idx_prt_user` (`user_id`,`created_at`),
  KEY `fk_prt_tenant` (`tenant_id`),
  CONSTRAINT `fk_prt_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_prt_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `products`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `products` (
  `id` char(36) NOT NULL,
  `tenant_id` char(36) NOT NULL,
  `sku` varchar(100) NOT NULL,
  `nombre` varchar(255) NOT NULL,
  `descripcion` text DEFAULT NULL,
  `categoria` varchar(100) DEFAULT NULL,
  `unidad_medida` enum('UNIDAD','KG','LT','M','M2','M3') DEFAULT 'UNIDAD',
  `peso` decimal(10,2) DEFAULT NULL,
  `volumen` decimal(10,4) DEFAULT NULL,
  `dimensiones` varchar(100) DEFAULT NULL,
  `es_serializable` tinyint(4) DEFAULT 0,
  `es_loteable` tinyint(4) DEFAULT 0,
  `propiedades` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`propiedades`)),
  `activo` tinyint(4) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  `sku_activo` varchar(100) GENERATED ALWAYS AS (if(`deleted_at` is null,`sku`,NULL)) STORED,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_products_tenant_sku_activo` (`tenant_id`,`sku_activo`),
  KEY `idx_tenant_activo` (`tenant_id`,`activo`),
  KEY `idx_tenant_categoria` (`tenant_id`,`categoria`),
  CONSTRAINT `products_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `rate_limit_config`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `rate_limit_config` (
  `id` char(36) NOT NULL,
  `tenant_id` char(36) DEFAULT NULL,
  `endpoint` varchar(255) NOT NULL,
  `max_requests` int(11) NOT NULL DEFAULT 100,
  `tiempo_ventana` int(11) NOT NULL DEFAULT 60,
  `tipo_limit` enum('POR_USUARIO','POR_IP','POR_ENDPOINT') NOT NULL DEFAULT 'POR_USUARIO',
  `acciones_al_exceder` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`acciones_al_exceder`)),
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_tenant_endpoint` (`tenant_id`,`endpoint`),
  KEY `idx_endpoint` (`endpoint`),
  KEY `idx_activo` (`activo`),
  CONSTRAINT `rate_limit_config_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `rate_limit_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `rate_limit_log` (
  `id` char(36) NOT NULL,
  `tenant_id` char(36) DEFAULT NULL,
  `usuario_id` char(36) DEFAULT NULL,
  `ip_address` varchar(255) DEFAULT NULL,
  `endpoint` varchar(255) NOT NULL,
  `requests_contados` int(11) NOT NULL DEFAULT 0,
  `limite` int(11) NOT NULL DEFAULT 100,
  `timestamp` timestamp NOT NULL DEFAULT current_timestamp(),
  `accion_tomada` enum('DELAY','REJECT','CHALLENGE','NONE') NOT NULL DEFAULT 'NONE',
  `duracion_bloqueo` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_tenant_timestamp` (`tenant_id`,`timestamp`),
  KEY `idx_ip_timestamp` (`ip_address`,`timestamp`),
  KEY `idx_endpoint` (`endpoint`),
  KEY `idx_usuario` (`usuario_id`),
  KEY `idx_accion` (`accion_tomada`),
  CONSTRAINT `rate_limit_log_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `rate_limit_log_ibfk_2` FOREIGN KEY (`usuario_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `refresh_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `refresh_tokens` (
  `id` char(36) NOT NULL,
  `user_id` char(36) NOT NULL,
  `tenant_id` char(36) NOT NULL,
  `family_id` char(36) NOT NULL,
  `used` tinyint(1) NOT NULL DEFAULT 0,
  `revoked` tinyint(1) NOT NULL DEFAULT 0,
  `revoked_reason` enum('rotated','logout','reuse','inactivity','expired','password_change','admin_reset') DEFAULT NULL,
  `expires_at` datetime NOT NULL,
  `ip_address` varchar(255) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_family` (`family_id`),
  KEY `idx_user_tenant` (`user_id`,`tenant_id`),
  KEY `idx_expires` (`expires_at`),
  KEY `fk_refresh_tokens_tenant` (`tenant_id`),
  CONSTRAINT `fk_refresh_tokens_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_refresh_tokens_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `role_assignments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `role_assignments` (
  `id` char(36) NOT NULL,
  `user_id` char(36) NOT NULL,
  `role_id` char(36) NOT NULL,
  `tenant_id` char(36) NOT NULL,
  `asignado_por_user_id` char(36) DEFAULT NULL,
  `fecha_asignacion` timestamp NOT NULL DEFAULT current_timestamp(),
  `fecha_expiracion` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user_role` (`user_id`,`role_id`,`tenant_id`),
  KEY `idx_tenant_user` (`tenant_id`,`user_id`),
  KEY `role_id` (`role_id`),
  CONSTRAINT `role_assignments_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `role_assignments_ibfk_2` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`),
  CONSTRAINT `role_assignments_ibfk_3` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `secrets_vault`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `secrets_vault` (
  `id` char(36) NOT NULL,
  `tenant_id` char(36) NOT NULL,
  `nombre` varchar(255) NOT NULL,
  `tipo` enum('API_KEY','PASSWORD','ENCRYPTION_KEY','OAUTH_TOKEN','OTRO') DEFAULT 'OTRO',
  `valor_encriptado` text NOT NULL,
  `descripcion` text DEFAULT NULL,
  `rotacion_requerida` tinyint(4) DEFAULT 0,
  `ultima_rotacion` timestamp NULL DEFAULT NULL,
  `proxima_rotacion` timestamp NULL DEFAULT NULL,
  `activo` tinyint(4) DEFAULT 1,
  `owner_user_id` char(36) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_secrets_vault_tenant_nombre` (`tenant_id`,`nombre`),
  KEY `owner_user_id` (`owner_user_id`),
  CONSTRAINT `secrets_vault_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`),
  CONSTRAINT `secrets_vault_ibfk_2` FOREIGN KEY (`owner_user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `secrets_access_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `secrets_access_log` (
  `id` char(36) NOT NULL,
  `secret_id` char(36) NOT NULL,
  `user_id` char(36) DEFAULT NULL,
  `timestamp` timestamp NOT NULL DEFAULT current_timestamp(),
  `accion` enum('READ','CREATE','UPDATE','DELETE','ROTATE','ACTIVATE','DEACTIVATE') NOT NULL DEFAULT 'READ',
  `ip_address` varchar(255) DEFAULT NULL,
  `user_agent` varchar(500) DEFAULT NULL,
  `resultado` enum('SUCCESS','FAILURE') NOT NULL DEFAULT 'SUCCESS',
  PRIMARY KEY (`id`),
  KEY `idx_secret_timestamp` (`secret_id`,`timestamp`),
  KEY `idx_user_timestamp` (`user_id`,`timestamp`),
  KEY `idx_timestamp` (`timestamp`),
  CONSTRAINT `secrets_access_log_ibfk_1` FOREIGN KEY (`secret_id`) REFERENCES `secrets_vault` (`id`) ON DELETE CASCADE,
  CONSTRAINT `secrets_access_log_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `shipments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shipments` (
  `id` char(36) NOT NULL,
  `tenant_id` char(36) NOT NULL,
  `referencia_externa` varchar(255) DEFAULT NULL,
  `tipo` enum('VEHICULO','MENSAJERIA','PROPIO','TERCERO') DEFAULT 'PROPIO',
  `origen_ubicacion_id` char(36) DEFAULT NULL,
  `destino_direccion` text DEFAULT NULL,
  `destinatario_id` varchar(255) DEFAULT NULL,
  `destinatario_nombre` varchar(512) DEFAULT NULL,
  `destinatario_contacto` varchar(255) DEFAULT NULL,
  `conductor_nombre` varchar(512) DEFAULT NULL,
  `conductor_contacto` varchar(255) DEFAULT NULL,
  `vehiculo_referencia` varchar(100) DEFAULT NULL,
  `estado` enum('PREPARACION','EN_RUTA','ENTREGADO','FALLO_ENTREGA','DEVUELTO') DEFAULT 'PREPARACION',
  `hora_salida` timestamp NULL DEFAULT NULL,
  `hora_entrega_esperada` timestamp NULL DEFAULT NULL,
  `hora_entrega_real` timestamp NULL DEFAULT NULL,
  `observaciones` text DEFAULT NULL,
  `actor_id_autoriza_salida` char(36) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_tenant_estado` (`tenant_id`,`estado`),
  KEY `origen_ubicacion_id` (`origen_ubicacion_id`),
  KEY `actor_id_autoriza_salida` (`actor_id_autoriza_salida`),
  KEY `idx_tenant_fecha` (`tenant_id`,`created_at`),
  CONSTRAINT `shipments_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`),
  CONSTRAINT `shipments_ibfk_2` FOREIGN KEY (`origen_ubicacion_id`) REFERENCES `locations` (`id`),
  CONSTRAINT `shipments_ibfk_3` FOREIGN KEY (`actor_id_autoriza_salida`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `stock_units`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `stock_units` (
  `id` char(36) NOT NULL,
  `tenant_id` char(36) NOT NULL,
  `producto_id` char(36) NOT NULL,
  `propietario_id` varchar(255) DEFAULT NULL,
  `custodio_id` varchar(255) DEFAULT NULL,
  `numero_serie` varchar(255) DEFAULT NULL,
  `lote` varchar(255) DEFAULT NULL,
  `codigo_barcode` varchar(64) NOT NULL,
  `cantidad` decimal(15,4) NOT NULL,
  `peso` decimal(10,2) DEFAULT NULL,
  `volumen` decimal(10,2) DEFAULT NULL,
  `estado` enum('DISPONIBLE','RESERVADO','EN_PREPARACION','CARGADO','EN_TRANSITO','ENTREGADO','DEVUELTO','DAÑADO','BLOQUEADO','PERDIDO') DEFAULT 'DISPONIBLE',
  `ubicacion_id` char(36) DEFAULT NULL,
  `fecha_ingreso` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_stock_units_tenant_barcode` (`tenant_id`,`codigo_barcode`),
  KEY `idx_tenant_estado` (`tenant_id`,`estado`),
  KEY `idx_tenant_ubicacion` (`tenant_id`,`ubicacion_id`),
  KEY `idx_tenant_producto` (`tenant_id`,`producto_id`),
  KEY `producto_id` (`producto_id`),
  KEY `ubicacion_id` (`ubicacion_id`),
  CONSTRAINT `stock_units_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`),
  CONSTRAINT `stock_units_ibfk_2` FOREIGN KEY (`producto_id`) REFERENCES `products` (`id`),
  CONSTRAINT `stock_units_ibfk_3` FOREIGN KEY (`ubicacion_id`) REFERENCES `locations` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `deliveries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `deliveries` (
  `id` char(36) NOT NULL,
  `tenant_id` char(36) NOT NULL,
  `shipment_id` char(36) NOT NULL,
  `unidad_fisica_id` char(36) NOT NULL,
  `cantidad` decimal(15,4) DEFAULT NULL,
  `estado` enum('PENDIENTE','ENTREGADA','RECHAZADA','PARCIAL') DEFAULT 'PENDIENTE',
  `destinatario_real` varchar(255) DEFAULT NULL,
  `firma_comprobante` varchar(255) DEFAULT NULL,
  `observaciones` text DEFAULT NULL,
  `actor_id_entrega` char(36) DEFAULT NULL,
  `timestamp_entrega` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_shipment` (`shipment_id`),
  KEY `tenant_id` (`tenant_id`),
  KEY `unidad_fisica_id` (`unidad_fisica_id`),
  KEY `actor_id_entrega` (`actor_id_entrega`),
  KEY `idx_tenant_fecha` (`tenant_id`,`timestamp_entrega`),
  CONSTRAINT `deliveries_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`),
  CONSTRAINT `deliveries_ibfk_2` FOREIGN KEY (`shipment_id`) REFERENCES `shipments` (`id`),
  CONSTRAINT `deliveries_ibfk_3` FOREIGN KEY (`unidad_fisica_id`) REFERENCES `stock_units` (`id`),
  CONSTRAINT `deliveries_ibfk_4` FOREIGN KEY (`actor_id_entrega`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `incidents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `incidents` (
  `id` char(36) NOT NULL,
  `tenant_id` char(36) NOT NULL,
  `tipo` enum('DAÑO','PERDIDA','DISCREPANCIA_CANTIDAD','UBICACION_DESCONOCIDA','ENTREGA_RECHAZADA','TRANSPORTE_FALLIDO','INVENTARIO_DESAPARECIDO','OTRO') DEFAULT 'DAÑO',
  `unidad_fisica_id` char(36) DEFAULT NULL,
  `descripcion` text NOT NULL,
  `observaciones` text DEFAULT NULL,
  `ubicacion_id` char(36) DEFAULT NULL,
  `estado` enum('ABIERTA','INVESTIGACION','RESUELTA','NO_RESUELTO') DEFAULT 'ABIERTA',
  `inventory_count_id` char(36) DEFAULT NULL,
  `actor_id_reporta` char(36) DEFAULT NULL,
  `timestamp_deteccion` timestamp NOT NULL DEFAULT current_timestamp(),
  `resolucion` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`resolucion`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `prioridad` enum('NORMAL','ALTA') NOT NULL DEFAULT 'NORMAL',
  `restriccion_resolucion` longtext DEFAULT NULL CHECK (json_valid(`restriccion_resolucion`)),
  PRIMARY KEY (`id`),
  KEY `idx_tenant_estado` (`tenant_id`,`estado`),
  KEY `unidad_fisica_id` (`unidad_fisica_id`),
  KEY `ubicacion_id` (`ubicacion_id`),
  KEY `actor_id_reporta` (`actor_id_reporta`),
  KEY `inventory_count_id` (`inventory_count_id`),
  KEY `idx_tenant_fecha` (`tenant_id`,`timestamp_deteccion`),
  CONSTRAINT `incidents_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`),
  CONSTRAINT `incidents_ibfk_2` FOREIGN KEY (`unidad_fisica_id`) REFERENCES `stock_units` (`id`),
  CONSTRAINT `incidents_ibfk_3` FOREIGN KEY (`ubicacion_id`) REFERENCES `locations` (`id`),
  CONSTRAINT `incidents_ibfk_4` FOREIGN KEY (`actor_id_reporta`) REFERENCES `users` (`id`),
  CONSTRAINT `incidents_ibfk_5` FOREIGN KEY (`inventory_count_id`) REFERENCES `inventory_counts` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `movements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `movements` (
  `id` char(36) NOT NULL,
  `tenant_id` char(36) NOT NULL,
  `unidad_fisica_id` char(36) NOT NULL,
  `cantidad` decimal(15,4) NOT NULL,
  `ubicacion_origen_id` char(36) DEFAULT NULL,
  `ubicacion_destino_id` char(36) DEFAULT NULL,
  `motivo` varchar(50) DEFAULT NULL,
  `actor_id` char(36) DEFAULT NULL,
  `referencia_externa` varchar(255) DEFAULT NULL,
  `timestamp_movimiento` timestamp NOT NULL DEFAULT current_timestamp(),
  `hash_integridad` varchar(64) DEFAULT NULL,
  `es_correccion` tinyint(1) NOT NULL DEFAULT 0,
  `movimiento_referencia_id` char(36) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_tenant_unidad` (`tenant_id`,`unidad_fisica_id`),
  KEY `idx_origen_destino` (`ubicacion_origen_id`,`ubicacion_destino_id`),
  KEY `unidad_fisica_id` (`unidad_fisica_id`),
  KEY `ubicacion_destino_id` (`ubicacion_destino_id`),
  KEY `actor_id` (`actor_id`),
  KEY `idx_tenant_fecha` (`tenant_id`,`timestamp_movimiento`),
  KEY `fk_movements_referencia` (`movimiento_referencia_id`),
  CONSTRAINT `fk_movements_referencia` FOREIGN KEY (`movimiento_referencia_id`) REFERENCES `movements` (`id`),
  CONSTRAINT `movements_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`),
  CONSTRAINT `movements_ibfk_2` FOREIGN KEY (`unidad_fisica_id`) REFERENCES `stock_units` (`id`),
  CONSTRAINT `movements_ibfk_3` FOREIGN KEY (`ubicacion_origen_id`) REFERENCES `locations` (`id`),
  CONSTRAINT `movements_ibfk_4` FOREIGN KEY (`ubicacion_destino_id`) REFERENCES `locations` (`id`),
  CONSTRAINT `movements_ibfk_5` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `picking_operations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `picking_operations` (
  `id` char(36) NOT NULL,
  `tenant_id` char(36) NOT NULL,
  `id_lote_picking` varchar(100) DEFAULT NULL,
  `unidad_fisica_id` char(36) NOT NULL,
  `cantidad` decimal(15,4) DEFAULT NULL,
  `ubicacion_origen_id` char(36) DEFAULT NULL,
  `ubicacion_preparacion_id` char(36) DEFAULT NULL,
  `referencia_orden` varchar(255) DEFAULT NULL,
  `actor_id_selector` char(36) DEFAULT NULL,
  `actor_id_supervisor` char(36) DEFAULT NULL,
  `estado` enum('PENDIENTE','EN_CURSO','COMPLETADA','VERIFICADA') DEFAULT 'PENDIENTE',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_lote` (`id_lote_picking`),
  KEY `idx_tenant_unidad` (`tenant_id`,`unidad_fisica_id`),
  KEY `unidad_fisica_id` (`unidad_fisica_id`),
  KEY `ubicacion_origen_id` (`ubicacion_origen_id`),
  KEY `ubicacion_preparacion_id` (`ubicacion_preparacion_id`),
  KEY `idx_tenant_fecha` (`tenant_id`,`created_at`),
  KEY `fk_picking_selector` (`actor_id_selector`),
  KEY `fk_picking_supervisor` (`actor_id_supervisor`),
  CONSTRAINT `fk_picking_selector` FOREIGN KEY (`actor_id_selector`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_picking_supervisor` FOREIGN KEY (`actor_id_supervisor`) REFERENCES `users` (`id`),
  CONSTRAINT `picking_operations_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`),
  CONSTRAINT `picking_operations_ibfk_2` FOREIGN KEY (`unidad_fisica_id`) REFERENCES `stock_units` (`id`),
  CONSTRAINT `picking_operations_ibfk_3` FOREIGN KEY (`ubicacion_origen_id`) REFERENCES `locations` (`id`),
  CONSTRAINT `picking_operations_ibfk_4` FOREIGN KEY (`ubicacion_preparacion_id`) REFERENCES `locations` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `reservations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `reservations` (
  `id` char(36) NOT NULL,
  `tenant_id` char(36) NOT NULL,
  `unidad_fisica_id` char(36) NOT NULL,
  `cantidad` decimal(15,4) NOT NULL,
  `referencia_orden` varchar(255) DEFAULT NULL,
  `actor_id` char(36) DEFAULT NULL,
  `fecha_reserva` timestamp NOT NULL DEFAULT current_timestamp(),
  `fecha_expiracion` timestamp NULL DEFAULT NULL,
  `estado` enum('ACTIVA','CANCELADA','COMPLETADA') DEFAULT 'ACTIVA',
  `motivo_cancelacion` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_tenant_estado` (`tenant_id`,`estado`),
  KEY `idx_unidad` (`unidad_fisica_id`),
  KEY `actor_id` (`actor_id`),
  KEY `idx_tenant_fecha` (`tenant_id`,`created_at`),
  CONSTRAINT `reservations_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`),
  CONSTRAINT `reservations_ibfk_2` FOREIGN KEY (`unidad_fisica_id`) REFERENCES `stock_units` (`id`),
  CONSTRAINT `reservations_ibfk_3` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `return_operations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `return_operations` (
  `id` char(36) NOT NULL,
  `tenant_id` char(36) NOT NULL,
  `delivery_id` char(36) DEFAULT NULL,
  `unidad_fisica_id` char(36) NOT NULL,
  `cantidad` decimal(15,4) DEFAULT NULL,
  `motivo` enum('CAMBIO_CLIENTE','DAÑO','FALLO_ENTREGA','NO_APLICA','OTRO') DEFAULT 'OTRO',
  `descripcion_motivo` text DEFAULT NULL,
  `estado_al_devolver` enum('OK','DAÑADO','DETERIORADO','PERDIDO') DEFAULT 'OK',
  `ubicacion_destino_id` char(36) DEFAULT NULL,
  `actor_id_recibe_devolucion` char(36) DEFAULT NULL,
  `timestamp_devolucion` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_tenant_unidad` (`tenant_id`,`unidad_fisica_id`),
  KEY `delivery_id` (`delivery_id`),
  KEY `unidad_fisica_id` (`unidad_fisica_id`),
  KEY `ubicacion_destino_id` (`ubicacion_destino_id`),
  KEY `actor_id_recibe_devolucion` (`actor_id_recibe_devolucion`),
  KEY `idx_tenant_fecha` (`tenant_id`,`timestamp_devolucion`),
  CONSTRAINT `return_operations_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`),
  CONSTRAINT `return_operations_ibfk_2` FOREIGN KEY (`delivery_id`) REFERENCES `deliveries` (`id`),
  CONSTRAINT `return_operations_ibfk_3` FOREIGN KEY (`unidad_fisica_id`) REFERENCES `stock_units` (`id`),
  CONSTRAINT `return_operations_ibfk_4` FOREIGN KEY (`ubicacion_destino_id`) REFERENCES `locations` (`id`),
  CONSTRAINT `return_operations_ibfk_5` FOREIGN KEY (`actor_id_recibe_devolucion`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `shipment_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shipment_items` (
  `id` char(36) NOT NULL,
  `tenant_id` char(36) NOT NULL,
  `shipment_id` char(36) NOT NULL,
  `unidad_fisica_id` char(36) NOT NULL,
  `cantidad` decimal(15,4) DEFAULT NULL,
  `estado` enum('PREPARADA','CARGADA','EN_TRANSITO','ENTREGADA','RECHAZADA','DEVUELTA') DEFAULT 'PREPARADA',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_shipment` (`shipment_id`),
  KEY `tenant_id` (`tenant_id`),
  KEY `unidad_fisica_id` (`unidad_fisica_id`),
  CONSTRAINT `shipment_items_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`),
  CONSTRAINT `shipment_items_ibfk_2` FOREIGN KEY (`shipment_id`) REFERENCES `shipments` (`id`),
  CONSTRAINT `shipment_items_ibfk_3` FOREIGN KEY (`unidad_fisica_id`) REFERENCES `stock_units` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `stock_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `stock_events` (
  `id` char(36) NOT NULL,
  `tenant_id` char(36) NOT NULL,
  `unidad_fisica_id` char(36) NOT NULL,
  `tipo_evento` varchar(50) NOT NULL,
  `descripcion` text DEFAULT NULL,
  `datos_operacion` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`datos_operacion`)),
  `cantidad_anterior` decimal(15,4) DEFAULT NULL,
  `cantidad_posterior` decimal(15,4) DEFAULT NULL,
  `estado_anterior` varchar(50) DEFAULT NULL,
  `estado_posterior` varchar(50) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `hash_integridad` varchar(64) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_tenant_timestamp` (`tenant_id`,`created_at`),
  KEY `idx_tenant_unidad` (`tenant_id`,`unidad_fisica_id`),
  KEY `unidad_fisica_id` (`unidad_fisica_id`),
  CONSTRAINT `stock_events_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`),
  CONSTRAINT `stock_events_ibfk_2` FOREIGN KEY (`unidad_fisica_id`) REFERENCES `stock_units` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `stock_ledger`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `stock_ledger` (
  `id` char(36) NOT NULL,
  `tenant_id` char(36) NOT NULL,
  `unidad_fisica_id` char(36) NOT NULL,
  `cantidad_fisica` decimal(15,4) DEFAULT NULL,
  `cantidad_reservada` decimal(15,4) DEFAULT 0.0000,
  `cantidad_en_transito` decimal(15,4) DEFAULT 0.0000,
  `cantidad_disponible` decimal(15,4) DEFAULT NULL,
  `estado_actual` varchar(50) DEFAULT NULL,
  `ultima_actualizacion` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_tenant_unidad` (`tenant_id`,`unidad_fisica_id`),
  KEY `unidad_fisica_id` (`unidad_fisica_id`),
  KEY `idx_tenant_fecha` (`tenant_id`,`ultima_actualizacion`),
  CONSTRAINT `stock_ledger_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`),
  CONSTRAINT `stock_ledger_ibfk_2` FOREIGN KEY (`unidad_fisica_id`) REFERENCES `stock_units` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `user_groups`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `user_groups` (
  `id` char(36) NOT NULL,
  `tenant_id` char(36) NOT NULL,
  `nombre` varchar(100) DEFAULT NULL,
  `descripcion` text DEFAULT NULL,
  `propietario_id` char(36) DEFAULT NULL,
  `activo` tinyint(4) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_tenant` (`tenant_id`),
  KEY `user_groups_ibfk_2` (`propietario_id`),
  CONSTRAINT `user_groups_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`),
  CONSTRAINT `user_groups_ibfk_2` FOREIGN KEY (`propietario_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `group_members`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `group_members` (
  `id` char(36) NOT NULL,
  `group_id` char(36) NOT NULL,
  `user_id` char(36) NOT NULL,
  `added_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `added_by_user_id` char(36) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_group_user` (`group_id`,`user_id`),
  KEY `user_id` (`user_id`),
  KEY `group_members_ibfk_3` (`added_by_user_id`),
  CONSTRAINT `group_members_ibfk_1` FOREIGN KEY (`group_id`) REFERENCES `user_groups` (`id`),
  CONSTRAINT `group_members_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `group_members_ibfk_3` FOREIGN KEY (`added_by_user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `group_roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `group_roles` (
  `id` char(36) NOT NULL,
  `group_id` char(36) NOT NULL,
  `role_id` char(36) NOT NULL,
  `assigned_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_group_role` (`group_id`,`role_id`),
  KEY `role_id` (`role_id`),
  CONSTRAINT `group_roles_ibfk_1` FOREIGN KEY (`group_id`) REFERENCES `user_groups` (`id`),
  CONSTRAINT `group_roles_ibfk_2` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

