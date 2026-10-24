#!/bin/sh
# Runs once, when the database volume is first created.
# Two roles: the owner (POSTGRES_USER) runs migrations and owns the tables; the app
# connects as DB_APP_USER, which is not the owner and has no BYPASSRLS, so the
# row-level security policies always apply to it. A second database, <name>_test, is
# for the test suite.
set -e
psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" <<SQL
CREATE ROLE ${DB_APP_USER} LOGIN PASSWORD '${DB_APP_PASSWORD}' NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS;
CREATE DATABASE ${POSTGRES_DB}_test OWNER ${POSTGRES_USER};
SQL
for db in "$POSTGRES_DB" "${POSTGRES_DB}_test"; do
  psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$db" <<SQL
GRANT CONNECT ON DATABASE ${db} TO ${DB_APP_USER};
GRANT USAGE ON SCHEMA public TO ${DB_APP_USER};
SQL
done
