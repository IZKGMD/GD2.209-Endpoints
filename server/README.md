# GD 2.209 server endpoints from MuchoCore

This directory contains the actual PHP implementation copied from
[IZKGMD/GMDmucho-core](https://github.com/IZKGMD/GMDmucho-core/tree/856a864745462ceac687d8963ebb1a7ed82e4002),
pinned to commit `856a864745462ceac687d8963ebb1a7ed82e4002`.

- [52 HTTP endpoint files](public/database/)
- [Endpoint-to-handler index](ENDPOINTS.md)
- [Controllers, services, authentication and protocol encoders](src/)
- [Database schema and migrations](database/)
- [Machine-readable endpoint map](endpoints.json)
- [Upstream file provenance](UPSTREAM.json)

MuchoCore registers URL handlers in [Application.php](src/Core/Application.php).
Each added `public/database/<name>.php` file forwards the original HTTP request
to that front controller, which calls the corresponding controller and service.
The complete implementation and its dependencies are included under `src/`;
the entrypoint files do not replace the implementation with hardcoded replies.

## Run locally

Requires PHP 8.3+, Composer, a MariaDB/MySQL database and PDO MySQL.
From this `server` directory:

```sh
composer install --no-dev
cp .env.example .env
```

Set `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER` and `DB_PASS` in `.env` for a
separate local database. Create that database and grant the configured user
access, then initialize the schema and start the PHP development server:

```sh
php bin/migrate.php
php -S 127.0.0.1:8000 -t public public/index.php
```

The endpoint base is `http://127.0.0.1:8000/database/`. For Apache or another
web server, set its document root to **this directory's `public/` folder**.
Use the same database configuration as the server whose accounts you intend
to authenticate. Existing authenticated handlers preserve their credential
and role checks.

## Checks

```sh
composer test
```

This runs the three copied offline protocol checks for thread envelopes,
comment timestamps and level timestamps. Additional copied integration
scripts under `tests/application/` require an isolated MariaDB instance via
`TEST_DB_HOST=127.0.0.1`, `TEST_DB_PORT` and `TEST_DB_ROOT_PASSWORD`.
They create and remove their own uniquely named test databases.

Import validation passed PHP 8.3 syntax checks for all 327 PHP files, verified
that all 52 endpoint names have registered upstream routes and public handler
methods, and matched copied upstream bytes against their pinned Git blob SHAs.
The upstream Composer test commands were replaced with the three included
offline checks; this packaging edit is recorded in `UPSTREAM.json`.

Route availability does not certify full native GD 2.209 request/response
compatibility. Endpoint-specific protocol gaps documented by MuchoCore remain
part of this snapshot. These are server implementations from MuchoCore;
they were not decompiled from GeometryDash.exe.
