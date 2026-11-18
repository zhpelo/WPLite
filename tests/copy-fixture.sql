-- Run only in a new disposable wplite_migration_* database.
CREATE TABLE custom_users (ID bigint unsigned NOT NULL PRIMARY KEY, user_login varchar(60) NOT NULL);
INSERT INTO custom_users VALUES (1,'migration-test');
CREATE TABLE custom_options (option_id bigint unsigned NOT NULL PRIMARY KEY, option_name varchar(191), option_value longtext);
INSERT INTO custom_options VALUES (1,'serialized','a:1:{s:4:"test";s:6:"中文";}'),(2,'_transient_cache','omit'),(3,'_site_transient_cache','omit');
CREATE TABLE custom_orders (id bigint unsigned PRIMARY KEY, amount decimal(12,2), payload longblob);
INSERT INTO custom_orders VALUES (9007199254740993,123.45,X'0001FF00');
CREATE TABLE custom_actionscheduler_logs (id int PRIMARY KEY, message text);
INSERT INTO custom_actionscheduler_logs VALUES (1,'omit');
CREATE TABLE custom_woocommerce_log LIKE custom_actionscheduler_logs;
INSERT INTO custom_woocommerce_log VALUES (1,'omit');
CREATE TABLE custom_woocommerce_sessions LIKE custom_actionscheduler_logs;
INSERT INTO custom_woocommerce_sessions VALUES (1,'omit');
CREATE TABLE custom_actionscheduler_actions LIKE custom_actionscheduler_logs;
INSERT INTO custom_actionscheduler_actions VALUES (1,'keep');
CREATE TABLE custom_duplicate (value varchar(60));
INSERT INTO custom_duplicate VALUES ('same'),('same'),('different');
