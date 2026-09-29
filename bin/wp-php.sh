#!/usr/bin/env bash
#
# Run a PHP script against the Local WordPress install.
#
# Local ships PHP without a php.ini on the CLI side, so `php script.php` dies
# on wp-load.php with "missing the MySQL extension". Every probe that needs a
# booted WordPress goes through here instead.
#
# usage: bin/wp-php.sh <script.php> [args...]
set -euo pipefail

PHP_DIR="${DXAI_PHP_DIR:-/c/Users/DevriX/AppData/Roaming/Local/lightning-services/php-8.2.29+0/bin/win64}"
PHP="$PHP_DIR/php.exe"

if [ ! -x "$PHP" ]; then
	echo "bin/wp-php.sh: no php.exe at $PHP (set DXAI_PHP_DIR)" >&2
	exit 1
fi

# The extension directory has to be given as a Windows path: it is read by
# php.exe, not by the shell.
EXT_DIR="$(cd "$PHP_DIR/ext" && pwd -W 2>/dev/null || echo "$PHP_DIR/ext")"
EXT_DIR="${EXT_DIR//\//\\}"

# wp-config.php says DB_HOST 'localhost', which mysqli reads as port 3306.
# Local runs a per-site MySQL on a port it allocates, and injects that port
# through php.ini for its own php-fpm. Read it back out of Local's site
# registry rather than pinning a number that changes when a site is recreated.
SITES_JSON="${DXAI_LOCAL_SITES:-/c/Users/DevriX/AppData/Roaming/Local/sites.json}"
DB_PORT="${DXAI_DB_PORT:-}"
if [ -z "$DB_PORT" ] && [ -f "$SITES_JSON" ]; then
	DB_PORT="$(node -e '
		const fs = require( "fs" );
		const sites = JSON.parse( fs.readFileSync( process.argv[ 1 ], "utf8" ) );
		const want = ( process.argv[ 3 ] || "" ).toLowerCase();
		let found = "";
		for ( const site of Object.values( sites ) ) {
			if ( want && String( site.name || "" ).toLowerCase() !== want ) {
				continue;
			}
			const port = site?.services?.mysql?.ports?.MYSQL?.[ 0 ];
			if ( port ) {
				found = String( port );
				break;
			}
		}
		process.stdout.write( found );
	' "$SITES_JSON" "${DXAI_SITE_NAME:-DXAI-UI}" 2>/dev/null || true)"
fi

exec "$PHP" -n \
	-d extension_dir="$EXT_DIR" \
	${DB_PORT:+-d mysqli.default_port="$DB_PORT"} \
	-d extension=php_mbstring.dll \
	-d extension=php_mysqli.dll \
	-d extension=php_pdo_mysql.dll \
	-d extension=php_openssl.dll \
	-d extension=php_curl.dll \
	-d extension=php_gd.dll \
	-d extension=php_exif.dll \
	-d extension=php_fileinfo.dll \
	-d extension=php_zip.dll \
	-d extension=php_intl.dll \
	-d extension=php_sodium.dll \
	-d extension=php_bz2.dll \
	-d memory_limit=1024M \
	-d max_execution_time=0 \
	"$@"
