-- One database and one role per service (database-per-service on a shared
-- instance). Nobody gets access to another service's data by default.

CREATE ROLE commerce LOGIN PASSWORD 'commerce';
CREATE ROLE logistics LOGIN PASSWORD 'logistics';

CREATE DATABASE commerce OWNER commerce ENCODING 'UTF8' TEMPLATE template0;
CREATE DATABASE logistics OWNER logistics ENCODING 'UTF8' TEMPLATE template0;

REVOKE ALL ON DATABASE commerce FROM PUBLIC;
REVOKE ALL ON DATABASE logistics FROM PUBLIC;

-- Since PostgreSQL 15 the public schema is no longer writable by everyone;
-- each service owns the public schema of its own database.
\connect commerce
ALTER SCHEMA public OWNER TO commerce;

\connect logistics
ALTER SCHEMA public OWNER TO logistics;
