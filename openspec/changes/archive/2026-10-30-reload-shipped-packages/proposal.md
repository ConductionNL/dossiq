# Reload the shipped templates from the case type list

## Why

The board DqZaaktypen draws "Sjablonen opnieuw laden" in the case type list's
Actions menu. dossiq ships its case type templates as packages
(`lib/Settings/packages/*`). A package marked `auto` installs on install and
upgrade through the repair step; a package an administrator installed on request
reaches its newer shipped version only when someone runs
`occ dossiq:package:install <id>` again. An administrator has no way to do that
from the app, and none to bring every installed template up to date in one go.

## What changes

- `PackageInstaller::reloadInstalled()` installs every package that is installed
  here or marked `auto`, in id order. It is `install()` per package, so a package
  already at the shipped version writes nothing and answers `current`, a newer one
  installs with the refresh rules of REQ-CT-49, and a refusal or failure is that
  package's outcome only.
- `POST /api/packages/reload` (`package#reload`) answers `{ results: [...] }`,
  one outcome per package. It is admin-only: `AuthorizedAdminSetting` refuses a
  non-admin in the middleware, before the controller runs, and the controller
  refuses a request without a user before calling the installer. It answers 500
  when any package failed, else 200.
- The case type list's Actions menu gets "Reload templates" as a header action
  that calls it after a confirmation.

## Out of scope

Installing an on-request package that was never installed: that stays a choice
per package (`occ dossiq:package:install <id>`).
