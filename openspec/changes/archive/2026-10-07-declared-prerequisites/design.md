# Design: declared-prerequisites

## D-1. The declaration is a PHP constant array

`Prerequisites::DECLARED`: `php` (`>=8.3`), `extensions` (`zip`, `json`,
`mbstring`, the ones `composer.json` names), `nextcloud` (min, max from
`info.xml`), `apps.required` (`openregister`), `apps.optional` keyed by app
id with a one-line "unlocks" text. Nothing else in the app hardcodes a
prerequisite.

## D-2. Live check, no cache

`Prerequisites::check()` answers each item with `present: bool` from
`extension_loaded()`, `PHP_VERSION_ID`, `IAppManager::isInstalled()`. It
runs on the admin page only, so cost is irrelevant.

## D-3. The drift test

`PrerequisitesTest` parses `composer.json`, `appinfo/info.xml` and the
README table and asserts each agrees with the declaration. The README row
is generated text with a marker comment.

## D-4. What the build found that the design did not know

**ext-mbstring was used and never required.** Nineteen files under `lib/` call
`mb_*` and `composer.json` asked for `ext-zip` alone. So the extension is added
to `composer.json` in the same commit rather than only declared: a declaration
composer does not enforce lets an install succeed and then report itself broken
on the settings page, which is the same class of problem this change exists to
end.

**The declaration and composer.json must name the same set.** `testComposerAgrees`
compares them both ways round. A declared extension composer does not require is
an install that should have been refused; a required extension the declaration
does not name is back to a stack trace.

**The README table is delimited by markers.** `testTheReadmeTableAgrees` reads
between `<!-- prerequisites:start -->` and `<!-- prerequisites:end -->` and
nothing else. A Nextcloud version named in prose elsewhere in the README is
somebody's sentence, not a requirement, and matching against the whole file
would let the test pass on the wrong text.

**A broken sibling app must not take the settings page.**
`IAppManager::isInstalled()` can throw on a broken app directory, so the read is
wrapped and a throw reports missing. That is the fail-safe direction here: the
block reports, it never blocks.
