#!/usr/bin/env bash
#
# Two processes race for the same slot, twenty times over. Exactly one must win
# each round, and the database must be left holding exactly one booking.
#
#   export MACROLAB_TEST_DB_NAME=macrolab_test MACROLAB_TEST_DB_USER=... MACROLAB_TEST_DB_PASS=...
#   bash tests/concurrency.sh
#
# This exercises the equipment row lock from two separate connections, which is
# the situation it exists for. No web server needed.

set -uo pipefail

cd "$(dirname "$0")/.."

if [[ -z "${MACROLAB_TEST_DB_NAME:-}" ]]; then
    echo "Set MACROLAB_TEST_DB_NAME (and MACROLAB_TEST_DB_USER / _PASS) first." >&2
    exit 1
fi

ROUNDS=${ROUNDS:-20}
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
failures=0

reset_db() {
    php -r '
        require "vendor/autoload.php";
        Macrolab\Config::set(require "tests/test-config.php");
        date_default_timezone_set("UTC");
        $db = Macrolab\Db::init((array) Macrolab\Config::get("db"));
        $pdo = $db->pdo();
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
        foreach ($pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN) as $t) {
            $pdo->exec("DROP TABLE IF EXISTS `" . $t . "`");
        }
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
        // Through the Migrator, so a new migration needs no edit here.
        (new Macrolab\Migrator($db))->migrate();
        Macrolab\Users::create("alice");
        Macrolab\Users::create("bob");
    '
}

count_confirmed() {
    php -r '
        require "vendor/autoload.php";
        Macrolab\Config::set(require "tests/test-config.php");
        date_default_timezone_set("UTC");
        $db = Macrolab\Db::init((array) Macrolab\Config::get("db"));
        echo (int) $db->value("SELECT COUNT(*) FROM bookings WHERE status = \"confirmed\"");
    '
}

for ((round = 1; round <= ROUNDS; round++)); do
    if ! reset_db; then
        echo "round $round: could not prepare the database" >&2
        exit 1
    fi

    php tests/concurrency.php alice > "$work/a.out" 2> "$work/a.err" &
    pid_a=$!
    php tests/concurrency.php bob > "$work/b.out" 2> "$work/b.err" &
    pid_b=$!
    wait "$pid_a"; status_a=$?
    wait "$pid_b"; status_b=$?

    if [[ $status_a -ne 0 || $status_b -ne 0 ]]; then
        echo "round $round: a process errored"
        cat "$work/a.err" "$work/b.err" >&2
        failures=$((failures + 1))
        continue
    fi

    wins=$(grep -c '^OK' "$work/a.out" "$work/b.out" | awk -F: '{ sum += $2 } END { print sum }')
    rows=$(count_confirmed)

    if [[ "$wins" == "1" && "$rows" == "1" ]]; then
        echo "round $round: ok (one winner, one booking)"
    else
        echo "round $round: FAIL - $wins winner(s), $rows confirmed booking(s), expected 1 and 1"
        failures=$((failures + 1))
    fi
done

if [[ $failures -gt 0 ]]; then
    echo "$failures of $ROUNDS rounds did not behave correctly." >&2
    exit 1
fi

echo "All $ROUNDS rounds kept the equipment single-booked."
