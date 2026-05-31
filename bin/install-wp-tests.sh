#!/usr/bin/env bash
# Installs the WordPress test scaffold (core + test library) for WP_UnitTestCase.
#
# Usage: bin/install-wp-tests.sh <db-name> <db-user> <db-pass> [db-host] [wp-version] [skip-db-create]
#
# Standard wordpress-develop installer, trimmed. Requires: svn, curl/wget, mysql,
# tar. On macOS the BSD sed quirks are handled. Safe to re-run (idempotent).

if [ $# -lt 3 ]; then
	echo "usage: $0 <db-name> <db-user> <db-pass> [db-host] [wp-version] [skip-db-create]"
	exit 1
fi

DB_NAME=$1
DB_USER=$2
DB_PASS=$3
DB_HOST=${4-localhost}
WP_VERSION=${5-latest}
SKIP_DB_CREATE=${6-false}

TMPDIR=${TMPDIR-/tmp}
TMPDIR=$(echo "$TMPDIR" | sed -e "s/\/$//")
WP_TESTS_DIR=${WP_TESTS_DIR-$TMPDIR/wordpress-tests-lib}
WP_CORE_DIR=${WP_CORE_DIR-$TMPDIR/wordpress/}

download() {
	if [ "$(which curl)" ]; then
		curl -fsSL "$1" >"$2"
	elif [ "$(which wget)" ]; then
		wget -nv -O "$2" "$1"
	else
		echo "error: neither curl nor wget found"
		exit 1
	fi
}

# Resolve the WP version/tag to download.
if [[ $WP_VERSION =~ ^[0-9]+\.[0-9]+\-(beta|RC)[0-9]+$ ]]; then
	WP_BRANCH=${WP_VERSION%\-*}
	WP_TESTS_TAG="branches/$WP_BRANCH"
elif [[ $WP_VERSION =~ ^[0-9]+\.[0-9]+$ ]]; then
	WP_TESTS_TAG="branches/$WP_VERSION"
elif [[ $WP_VERSION =~ [0-9]+\.[0-9]+\.[0-9]+ ]]; then
	if [[ $WP_VERSION =~ [0-9]+\.[0-9]+\.[0] ]]; then
		WP_TESTS_TAG="tags/${WP_VERSION%??}"
	else
		WP_TESTS_TAG="tags/$WP_VERSION"
	fi
elif [[ $WP_VERSION == 'nightly' || $WP_VERSION == 'trunk' ]]; then
	WP_TESTS_TAG="trunk"
else
	# Latest.
	download http://api.wordpress.org/core/version-check/1.7/ "$TMPDIR"/wp-latest.json
	grep '[0-9]+\.[0-9]+(\.[0-9]+)?' "$TMPDIR"/wp-latest.json >/dev/null
	LATEST_VERSION=$(grep -o '"version":"[^"]*' "$TMPDIR"/wp-latest.json | sed 's/"version":"//' | head -1)
	if [[ -z "$LATEST_VERSION" ]]; then
		echo "Latest WordPress version could not be found"
		exit 1
	fi
	WP_TESTS_TAG="tags/$LATEST_VERSION"
fi

set -ex

install_wp() {
	if [ -d "$WP_CORE_DIR" ]; then
		return
	fi
	mkdir -p "$WP_CORE_DIR"

	if [[ $WP_VERSION == 'nightly' || $WP_VERSION == 'trunk' ]]; then
		mkdir -p "$TMPDIR"/wordpress-trunk
		rm -rf "$TMPDIR"/wordpress-trunk/*
		svn export --quiet https://core.svn.wordpress.org/trunk "$TMPDIR"/wordpress-trunk/wordpress
		mv "$TMPDIR"/wordpress-trunk/wordpress/* "$WP_CORE_DIR"
	else
		if [ "$WP_VERSION" == 'latest' ]; then
			local ARCHIVE_NAME='latest'
		elif [[ $WP_VERSION =~ [0-9]+\.[0-9]+ ]]; then
			download https://wordpress.org/wordpress-"$WP_VERSION".tar.gz "$TMPDIR"/wordpress.tar.gz
			if tar tf "$TMPDIR"/wordpress.tar.gz &>/dev/null; then
				local ARCHIVE_NAME="wordpress-$WP_VERSION"
			else
				local ARCHIVE_NAME='latest'
			fi
		else
			local ARCHIVE_NAME="wordpress-$WP_VERSION"
		fi
		download https://wordpress.org/"${ARCHIVE_NAME}".tar.gz "$TMPDIR"/wordpress.tar.gz
		tar --strip-components=1 -zxmf "$TMPDIR"/wordpress.tar.gz -C "$WP_CORE_DIR"
	fi

	download https://raw.githubusercontent.com/markoheijnen/wp-mysqli/master/db.php "$WP_CORE_DIR"/wp-content/db.php
}

install_test_suite() {
	# Portable in-place sed.
	if [[ $(uname -s) == 'Darwin' ]]; then
		local ioption='-i.bak'
	else
		local ioption='-i'
	fi

	# Map the SVN-style tag to a wordpress-develop git ref.
	local WPD_REF="${WP_TESTS_TAG#tags/}"
	WPD_REF="${WPD_REF#branches/}"

	if [ ! -d "$WP_TESTS_DIR"/includes ]; then
		mkdir -p "$WP_TESTS_DIR"
		rm -rf "$WP_TESTS_DIR"/{includes,data}

		# Fetch the PHPUnit test library from the GitHub mirror. develop.svn.wordpress.org
		# is frequently unreachable from CI runners (SSL/connection errors), so we avoid
		# svn entirely. Try the wordpress-develop refs from most to least specific: the
		# matching tag, the exact branch, its major.minor branch, then trunk.
		local archive_base='https://github.com/WordPress/wordpress-develop/archive/refs'
		local candidate_refs="tags/${WPD_REF} heads/${WPD_REF} heads/${WPD_REF%.*} heads/trunk"

		local tmp_wpd downloaded=false
		tmp_wpd="$(mktemp -d)"
		for ref in $candidate_refs; do
			if download "${archive_base}/${ref}.tar.gz" "$tmp_wpd/wpd.tar.gz" \
				&& tar tzf "$tmp_wpd/wpd.tar.gz" >/dev/null 2>&1; then
				downloaded=true
				break
			fi
		done
		if ! $downloaded; then
			echo "Could not download the WordPress test library from GitHub." >&2
			exit 1
		fi

		# Copy just the PHPUnit test library out of the extracted source tree.
		local extracted="$tmp_wpd/extract"
		mkdir -p "$extracted"
		tar --strip-components=1 -xzf "$tmp_wpd/wpd.tar.gz" -C "$extracted"
		cp -r "$extracted/tests/phpunit/includes" "$WP_TESTS_DIR"/includes
		cp -r "$extracted/tests/phpunit/data" "$WP_TESTS_DIR"/data
		cp "$extracted/wp-tests-config-sample.php" "$WP_TESTS_DIR"/wp-tests-config-sample.php
	fi

	if [ ! -f wp-tests-config.php ]; then
		cp "$WP_TESTS_DIR"/wp-tests-config-sample.php "$WP_TESTS_DIR"/wp-tests-config.php
		# Point the sample config at our WP core dir + DB credentials in a single
		# rewrite (trailing slashes trimmed from the core dir first).
		WP_CORE_DIR=$(echo "$WP_CORE_DIR" | sed "s:/\+$::")
		sed $ioption \
			-e "s:dirname( __FILE__ ) . '/src/':'$WP_CORE_DIR/':" \
			-e "s:__DIR__ . '/src/':'$WP_CORE_DIR/':" \
			-e "s/youremptytestdbnamehere/$DB_NAME/" \
			-e "s/yourusernamehere/$DB_USER/" \
			-e "s/yourpasswordhere/$DB_PASS/" \
			-e "s|localhost|${DB_HOST}|" \
			"$WP_TESTS_DIR"/wp-tests-config.php
	fi
}

recreate_db() {
	shopt -s nocasematch
	if [[ $1 =~ ^(y|yes)$ ]]; then
		mysqladmin drop "$DB_NAME" -f --user="$DB_USER" --password="$DB_PASS"$EXTRA
		create_db
		echo "Recreated the database ($DB_NAME)."
	else
		echo "Leaving the existing database ($DB_NAME) in place."
	fi
	shopt -u nocasematch
}

create_db() {
	mysqladmin create "$DB_NAME" --user="$DB_USER" --password="$DB_PASS"$EXTRA
}

install_db() {
	if [ "${SKIP_DB_CREATE}" = "true" ]; then
		return 0
	fi

	# Parse DB_HOST for socket or port.
	local PARTS
	IFS=':' read -ra PARTS <<<"$DB_HOST"
	local DB_HOSTNAME=${PARTS[0]}
	local DB_SOCK_OR_PORT=${PARTS[1]}
	local EXTRA=""

	if ! [ -z "$DB_HOSTNAME" ]; then
		if [ $(echo "$DB_SOCK_OR_PORT" | grep -e '^[0-9]\{1,\}$') ]; then
			EXTRA=" --host=$DB_HOSTNAME --port=$DB_SOCK_OR_PORT --protocol=tcp"
		elif ! [ -z "$DB_SOCK_OR_PORT" ]; then
			EXTRA=" --socket=$DB_SOCK_OR_PORT"
		elif ! [ -z "$DB_HOSTNAME" ]; then
			EXTRA=" --host=$DB_HOSTNAME --protocol=tcp"
		fi
	fi

	if [ $(mysql --user="$DB_USER" --password="$DB_PASS"$EXTRA --execute='show databases;' 2>/dev/null | grep ^"$DB_NAME"$) ]; then
		recreate_db yes
	else
		create_db
	fi
}

install_wp
install_test_suite
install_db
