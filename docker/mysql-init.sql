-- Second database for the test suite, so tests never touch development data.
CREATE DATABASE IF NOT EXISTS trading_test;
GRANT ALL PRIVILEGES ON trading_test.* TO 'trading'@'%';
FLUSH PRIVILEGES;
