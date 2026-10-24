# Releasing Easy SVG Support

For whoever maintains this repository. Not part of the plugin: `.distignore`
and `.gitattributes` keep this file out of wordpress.org.

## One-time setup

The deploy workflow (`.github/workflows/deploy.yml`) needs two repository
secrets, under Settings -> Secrets and variables -> Actions:

| Secret         | What                                                        |
|----------------|-------------------------------------------------------------|
| `SVN_USERNAME` | a wordpress.org account with commit access to `easy-svg`    |
| `SVN_PASSWORD` | that account's SVN password (wordpress.org profile -> Account & Security) |

They live only there. Never in a file, a commit message or a workflow log.

Without them the run gets as far as the SVN step and stops there with
"Set the SVN_USERNAME secret". That is what happened to every tag from
`5.1.0` to `5.3.1`: as of 2026-10-24 wordpress.org still serves 4.1.

## A release

1. The version goes in three places, all the same: `Version:` in the
   `easy-svg.php` header, `Stable tag:` in `readme.txt`, and a `= x.y =`
   changelog entry (plus an Upgrade Notice when site owners should read
   something before updating). `php tests/run.php` checks the first two agree.
   New or changed strings: `scripts/build-i18n.sh` first, so every shipped
   catalogue carries them (the deploy runs `scripts/check-i18n.sh` and stops
   if one does not).
2. CI is green on `master`: CI, Static analysis, Plugin Check and Panel E2E.
3. Tag the merge commit with the bare version -- `4.3`, no `v` -- and push
   the tag:

       git tag 4.3
       git push origin 4.3

4. The workflow refuses to deploy if the tagged commit is not on `master`
   or the tag, the header and `Stable tag` differ, runs `tests/run.php` and
   `scripts/check-i18n.sh`, then commits trunk and `tags/4.3` to SVN. The zip
   it built is attached to the workflow run as an artifact.

5. Afterwards, check what wordpress.org actually serves:
   `https://api.wordpress.org/plugins/info/1.0/easy-svg.json`. The header on
   `master`, the highest tag and that answer are three different numbers
   until a deploy has gone through; measure each before saying which version
   is current.

To see the release tree without deploying (both give the same tree;
`tests/run.php` checks `.distignore` and `.gitattributes` against each other).
The `rsync` line is what the deploy action runs; use it on a clean checkout,
because in a working copy it also carries whatever is untracked:

    git archive --format=tar HEAD | tar -t
    rsync -rc --exclude-from=.distignore ./ /some/empty/dir/easy-svg/

Plugin Check is only meaningful on that tree, in a directory named
`easy-svg` (the slug decides the expected text domain). CI does exactly that
in `.github/workflows/plugin-check.yml`.

Never build a release zip by copying the working tree: untracked local files
would ride along. The workflow's zip or `git archive` are the only two ways.

## Assets

Banners, icons and screenshots live in SVN `assets/` and are not managed
from here (there is no `.wordpress-org/` folder, so the workflow leaves them
alone). SVN `assets/` also holds a duplicate of each banner and icon whose
name uses a multiplication sign instead of an `x` (`banner-1544×500.png`
and so on). wordpress.org only reads the `x` names; the others can be
deleted in SVN by hand.
