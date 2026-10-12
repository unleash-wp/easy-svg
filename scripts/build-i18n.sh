#!/bin/zsh
#
# Rebuild languages/easy-svg.pot.
#
# Two things have to be true at once, and doing the obvious thing breaks one of
# them. tests/run.php asserts both:
#
#   1. The JS references must point at build/panel.js, never at src/. WordPress
#      names each JS catalogue after md5() of the script path it enqueues, so a
#      POT that references src/*.jsx produces .json filenames nothing ever asks
#      for, and every translation silently fails to load.
#
#   2. The `translators:` notes must survive. They live in the source and the
#      minifier strips them, so extracting straight from build/panel.js reaches
#      translate.wordpress.org with bare %d placeholders and nothing saying what
#      they count.
#
# So: extract from SOURCE for the comments, then rewrite the JS references to
# the shipped bundle. Running `make-pot --exclude=src` satisfies (1) and
# quietly destroys (2) -- which is how this script came to exist.
#
# Usage: ./scripts/build-i18n.sh

set -eu
cd "$(dirname "$0")/.."

command -v wp >/dev/null || { echo "wp-cli is required"; exit 1; }

# build/ is excluded so each string is extracted once, from the source that
# still has its comments.
wp i18n make-pot . languages/easy-svg.pot \
  --exclude=build,node_modules,vendor,tests,docs \
  --allow-root

# Now point the JS strings at what actually ships.
perl -pi -e 's{^#: src/[^\s:]*\.jsx?:\d+$}{#: build/panel.js}' languages/easy-svg.pot
perl -0pi -e 's{(#: build/panel\.js\n)(?:#: build/panel\.js\n)+}{$1}g' languages/easy-svg.pot

# A NEW string reaches a catalogue only through msgmerge: make-mo and make-json
# read the .po, and a .po does not learn a msgid from the POT by itself. Skip
# this and new strings are simply English in every language, with a green suite
# and a freshly built POT to suggest otherwise.
command -v msgmerge >/dev/null || { echo "msgmerge is missing (brew install gettext)"; exit 1; }
for po in languages/*.po; do
  [ -f "$po" ] || continue
  msgmerge --quiet --no-fuzzy-matching --backup=none --update "$po" languages/easy-svg.pot
done

wp i18n make-mo languages languages --allow-root

# --no-purge: without it make-json STRIPS the JS strings out of the .po it just
# read, so the next make-mo would compile a catalogue with the panel missing.
wp i18n make-json languages languages --no-purge --allow-root

echo "languages/easy-svg.pot rebuilt ($(grep -c '^msgid' languages/easy-svg.pot) msgids, $(grep -c '^#\.' languages/easy-svg.pot) translator notes)"
