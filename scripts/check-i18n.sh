#!/usr/bin/env bash
#
# Fail when a catalogue has fallen behind the source.
#
# build-i18n.sh brings the catalogues up to date, but only when somebody runs
# it, and between 2026-10-12 and 2026-10-19 nobody did: 53 strings showed in
# English on a German site with every suite green. This asks the question from
# the source every time, and writes nothing in the repository:
#
#   1. every string the source has is translated in every shipped .po,
#   2. every .mo says what its .po says,
#   3. every JSON catalogue is the one make-json writes from that .po.
#
# Usage: scripts/check-i18n.sh   -> exits non-zero, naming what is missing.

set -euo pipefail
cd "$(dirname "$0")/.."

for tool in wp msgmerge msgattrib msgfmt msgunfmt; do
  command -v "$tool" >/dev/null || { echo "$tool is required (wp-cli, gettext)" >&2; exit 1; }
done

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

# The same extraction build-i18n.sh makes, references rewritten to the bundle
# that ships, because the JSON catalogue is named after that path.
wp i18n make-pot . "$TMP/easy-svg.pot" --exclude=build,node_modules,vendor,tests,docs --allow-root >/dev/null
perl -pi -e 's{^#: src/[^\s:]*\.jsx?:\d+$}{#: build/panel.js}' "$TMP/easy-svg.pot"
perl -0pi -e 's{(#: build/panel\.js\n)(?:#: build/panel\.js\n)+}{$1}g' "$TMP/easy-svg.pot"

fail=0
mkdir -p "$TMP/po"
for po in languages/*.po; do
  name="$(basename "$po")"
  msgmerge --quiet --no-fuzzy-matching --no-wrap "$po" "$TMP/easy-svg.pot" -o "$TMP/po/$name"
  # The header and the plugin's own name, URI and author are not translated:
  # they read the same in every language.
  missing="$(msgattrib --untranslated --no-wrap "$TMP/po/$name" \
    | awk 'BEGIN { RS = ""; FS = "\n" } !/of the plugin/ && !/^msgid ""\nmsgstr ""/ { for (i = 1; i <= NF; i++) if ($i ~ /^msgid "/) print $i }')"
  if [ -n "$missing" ]; then
    fail=1
    echo "RED  $name is missing $(printf '%s\n' "$missing" | wc -l | tr -d ' ') strings:"
    printf '%s\n' "$missing" | sed 's/^/       /' | head -20
  fi

  mo="${po%.po}.mo"
  msgfmt -o "$TMP/fresh.mo" "$po"
  # Entries only: make-mo and msgfmt order and date the header differently.
  entries() { msgunfmt --no-wrap --sort-output "$1" | awk 'BEGIN { RS = ""; ORS = "\n\n" } !/^msgid ""\n/'; }
  if ! cmp -s <(entries "$TMP/fresh.mo") <(entries "$mo"); then
    fail=1
    echo "RED  $(basename "$mo") is not built from $name; run scripts/build-i18n.sh"
  fi
done

# make-json reads the .po files and writes one JSON per script; compare its
# answer with what is committed, file by file.
mkdir -p "$TMP/json"
cp languages/*.po "$TMP/json/"
wp i18n make-json "$TMP/json" "$TMP/json" --no-purge --allow-root >/dev/null
for fresh in "$TMP"/json/*.json; do
  committed="languages/$(basename "$fresh")"
  if [ ! -f "$committed" ] || ! cmp -s <(python3 -c 'import json,sys; d=json.load(open(sys.argv[1])); d.pop("translation-revision-date", None); print(json.dumps(d, sort_keys=True))' "$fresh") \
                                     <(python3 -c 'import json,sys; d=json.load(open(sys.argv[1])); d.pop("translation-revision-date", None); print(json.dumps(d, sort_keys=True))' "$committed"); then
    fail=1
    echo "RED  $committed is not what make-json writes from the .po; run scripts/build-i18n.sh"
  fi
done

[ $fail -eq 0 ] && echo "catalogues current"
exit $fail
