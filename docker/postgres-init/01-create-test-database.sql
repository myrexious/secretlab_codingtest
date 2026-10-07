-- Keeps the test suite off the development data. Runs once, on first start of
-- an empty postgres volume.
CREATE DATABASE kvstore_test OWNER kvstore;
