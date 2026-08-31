-- Base de datos para la suite de tests (phpunit.xml apunta aqui con
-- DB_DATABASE=api_reservations_test). Se corre sobre MySQL/InnoDB real
-- porque SQLite serializa las escrituras y ocultaria las carreras.
CREATE DATABASE IF NOT EXISTS `api_reservations_test`
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

GRANT ALL PRIVILEGES ON `api_reservations_test`.* TO 'eltiempo'@'%';
FLUSH PRIVILEGES;
