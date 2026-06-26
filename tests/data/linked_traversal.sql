-- Схема для LinkedTraversalDeepTest: много_hop-обход через "_" в обе стороны.

DROP TABLE IF EXISTS `tags`;
DROP TABLE IF EXISTS `photos`;
DROP TABLE IF EXISTS `employees`;
DROP TABLE IF EXISTS `clients`;
DROP TABLE IF EXISTS `cities`;
DROP TABLE IF EXISTS `regions`;
DROP TABLE IF EXISTS `users`;
DROP TABLE IF EXISTS `products`;
DROP TABLE IF EXISTS `catalogs`;
DROP TABLE IF EXISTS `brands`;

CREATE TABLE `regions` (
  `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `title` VARCHAR(255) NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

CREATE TABLE `cities` (
  `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `title` VARCHAR(255) NULL,
  `region_id` INT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

CREATE TABLE `users` (
  `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(255) NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

CREATE TABLE `clients` (
  `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `title` VARCHAR(255) NULL,
  `city_id` INT NULL,
  `user_id` INT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

CREATE TABLE `employees` (
  `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(255) NULL,
  `client_id` INT NULL,
  `city_id` INT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

CREATE TABLE `photos` (
  `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `url` VARCHAR(255) NULL,
  `employee_id` INT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

CREATE TABLE `tags` (
  `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `label` VARCHAR(255) NULL,
  `photo_id` INT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

CREATE TABLE `brands` (
  `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `title` VARCHAR(255) NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

CREATE TABLE `catalogs` (
  `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `title` VARCHAR(255) NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

CREATE TABLE `products` (
  `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `title` VARCHAR(255) NULL,
  `brand_id` INT NULL,
  `catalog_id` INT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

INSERT INTO `regions` (`id`, `title`) VALUES
  (1, 'North'), (2, 'South'), (3, 'East'), (4, 'West');

INSERT INTO `cities` (`id`, `title`, `region_id`) VALUES
  (1, 'NorthCity', 1),
  (2, 'EastCity1', 3),
  (3, 'EastCity2', 3),
  (4, 'WestCity', 4);

INSERT INTO `users` (`id`, `name`) VALUES
  (1, 'Alice'), (2, 'Bob');

INSERT INTO `clients` (`id`, `title`, `city_id`, `user_id`) VALUES
  (1, 'CompA', 2, 1),
  (2, 'CompB', 4, 2),
  (3, 'CompC', 2, 1);

INSERT INTO `employees` (`id`, `name`, `client_id`, `city_id`) VALUES
  (1, 'Emp1', 1, 2),
  (2, 'Emp2', 1, 2),
  (3, 'Emp3', 2, 4),
  (4, 'Emp4', 3, 2),
  (5, 'Emp5', NULL, 4);

INSERT INTO `photos` (`id`, `url`, `employee_id`) VALUES
  (1, 'p1', 1),
  (2, 'p2', 1),
  (3, 'p3', 3),
  (4, 'p4', 4);

INSERT INTO `tags` (`id`, `label`, `photo_id`) VALUES
  (1, 'red', 1),
  (2, 'big', 1),
  (3, 'small', 2),
  (4, 'green', 4);

INSERT INTO `brands` (`id`, `title`) VALUES
  (1, 'Apple'), (2, 'Samsung');

INSERT INTO `catalogs` (`id`, `title`) VALUES
  (1, 'Phones'), (2, 'Laptops');

INSERT INTO `products` (`id`, `title`, `brand_id`, `catalog_id`) VALUES
  (1, 'iPhone', 1, 1),
  (2, 'MacBook', 1, 2),
  (3, 'Galaxy', 2, 1);
