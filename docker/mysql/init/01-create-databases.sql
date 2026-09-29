-- Runs once on first container start (only when mysql_data volume is empty)

CREATE DATABASE IF NOT EXISTS `food_db`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

CREATE DATABASE IF NOT EXISTS `food_testing`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

GRANT ALL PRIVILEGES ON `food_db`.* TO 'food'@'%';
GRANT ALL PRIVILEGES ON `food_testing`.* TO 'food'@'%';

FLUSH PRIVILEGES;
