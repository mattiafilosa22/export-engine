-- Creates the test database and grants privileges to the app user.
-- Mounted in /docker-entrypoint-initdb.d: runs ONLY on the first init of an empty DB volume.
CREATE DATABASE IF NOT EXISTS gamindo_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL ON gamindo_test.* TO 'gamindo'@'%';
FLUSH PRIVILEGES;
