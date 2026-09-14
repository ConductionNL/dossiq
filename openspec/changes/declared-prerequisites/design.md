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
