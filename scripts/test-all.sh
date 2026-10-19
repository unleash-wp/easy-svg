#!/bin/zsh
#
# Every suite, read by EXIT CODE.
#
# There is no other honest way to ask. `php tests/x.php | tail -3` reports
# tail's exit code, not PHP's, so a fatal scrolls past and $? is 0. And a chain
# that ends `... && echo "green" && git commit` commits whatever echo returns,
# which is always success. Both mistakes were made here, and each one put a
# commit on a red tree.
#
# Usage: ./scripts/test-all.sh   -> prints only what is RED, exits non-zero if any.

set -u
cd "$(dirname "$0")/.."

fail=0
for f in tests/*.php; do
  php "$f" >/dev/null 2>&1 || { fail=1; echo "RED  $(basename "$f")"; }
done

node tests/build-swap.mjs >/dev/null 2>&1 || { fail=1; echo "RED  build-swap.mjs"; }

[ $fail -eq 0 ] && echo "all suites green"
exit $fail
