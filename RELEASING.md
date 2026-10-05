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

## A release

1. The version goes in three places, all the same: `Version:` in the
   `easy-svg.php` header, `Stable tag:` in `readme.txt`, and a `= x.y =`
   changelog entry (plus an Upgrade Notice when site owners should read
   something before updating). `php tests/run.php` checks the first two agree.
2. CI is green on `master`.
3. Tag the merge commit with the bare version -- `4.3`, no `v` -- and push
   the tag:

       git tag 4.3
       git push origin 4.3

4. The workflow refuses to deploy if the tag, the header and `Stable tag`
   differ, runs the tests, then commits trunk and `tags/4.3` to SVN. The zip
   it built is attached to the workflow run as an artifact.

To see the release tree without deploying:

    git archive --format=tar HEAD | tar -t

## Assets

Banners, icons and screenshots live in SVN `assets/` and are not managed
from here (there is no `.wordpress-org/` folder, so the workflow leaves them
alone). SVN `assets/` also holds a duplicate of each banner and icon whose
name uses a multiplication sign instead of an `x` (`banner-1544×500.png`
and so on). wordpress.org only reads the `x` names; the others can be
deleted in SVN by hand.
