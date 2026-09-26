-- Runs once, when the postgres volume is first initialised.
-- A separate database for the automated test suite, so tests never touch dev data.
CREATE DATABASE pati_parking_test OWNER pati;
