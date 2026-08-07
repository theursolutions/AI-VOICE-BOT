-- Runs once, on first initialisation of an empty MySQL data volume
-- (mounted at /docker-entrypoint-initdb.d). Executes AFTER the official
-- image has created MYSQL_DATABASE (ai-crm-config) and MYSQL_USER.
--
-- The app provisions a per-project chat DB on the fly
-- (`php artisan tenant:provision <id>` -> CREATE DATABASE `ai-crm-client-<id>`).
-- This grant lets the *application* user do that, so the tenant connection
-- does NOT need the MySQL root account.
--
-- NOTE: the username is hard-coded here (init SQL can't read env vars). It must
-- match DB_USERNAME in your .env. If you change DB_USERNAME from `aicrm`,
-- update this line too (and recreate the mysql volume, since this runs only
-- on first init).

GRANT ALL PRIVILEGES ON `ai-crm-client-%`.* TO 'aicrm'@'%';
FLUSH PRIVILEGES;
