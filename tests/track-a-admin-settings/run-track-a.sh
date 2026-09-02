#!/usr/bin/env bash
# Runs every Track A backend admin-settings test against a headless
# WordPress instance. Exits non-zero if any assertion fails.
#
# Usage: WPSITE=/tmp/wpsite ./run-track-a.sh
set -uo pipefail

WPSITE="${WPSITE:-/tmp/wpsite}"
cd "$(dirname "$0")"

if [ ! -f "$WPSITE/wp-load.php" ]; then
    echo "No WordPress install found at $WPSITE — run setup-wordpress.sh first."
    exit 1
fi

cp 00-fixtures.php "$WPSITE/track-a-fixtures.php"
cp 01-appointments-booking-mode.php "$WPSITE/track-a-booking-mode.php"
cp 02-membership-content-restriction.php "$WPSITE/track-a-restriction.php"
cp 03-engine-and-events-settings.php "$WPSITE/track-a-engine-events.php"

fails=0
run() {
    echo "=== $1 ==="
    if ! php "$WPSITE/$1"; then
        fails=$((fails+1))
        echo ">>> FAILED: $1"
    fi
    echo
}

run track-a-fixtures.php
run track-a-booking-mode.php
run track-a-restriction.php
run track-a-engine-events.php

echo "============================================"
if [ "$fails" -eq 0 ]; then
    echo "Track A: ALL FILES PASSED"
    exit 0
else
    echo "Track A: $fails FILE(S) FAILED"
    exit 1
fi
