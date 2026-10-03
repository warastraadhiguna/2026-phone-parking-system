-- Runs once, when the postgres volume is first initialised.
-- A separate database for the automated test suite, so tests never touch dev data.
CREATE DATABASE pati_parking_test OWNER pati;

-- Like a typical Indonesian server: the default time zone is not UTC. The application must
-- not depend on it (config/database.php sets the session time zone to UTC).
ALTER DATABASE pati_parking_test SET timezone = 'Asia/Jakarta';
