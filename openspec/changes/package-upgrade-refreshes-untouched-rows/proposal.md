# A package upgrade refreshes the rows nobody edited

## Why

REQ-CT-49 says a newer package version writes the rows the instance lacks and
leaves every row it already holds as it is. That keeps a local edit, and it also
keeps every untouched row at the old version, so a corrected status name or a
changed flow never reaches an instance that installed the package before.

The shipped-set ledger already tells the two apart. Every row a package writes is
stamped with a fingerprint of its authored content (`ShippedFingerprint`), and
`ShippedConfigurationService::adopt()` takes a newer shipped version only when
the row still hashes to its stamp.

## What changes

- `PackageWriter::writePackage()` reports each row it found already present as
  `held`, with the row the package now ships. The write itself still never
  replaces a row, so a failed install can still take back everything it wrote.
- After the write and the ledger record succeed, `PackageInstaller` refreshes
  each held row through the new `ShippedConfigurationService::refreshRows()`:
  - a row whose content already is what the version ships writes nothing,
  - an untouched row this package shipped takes the new version through
    `adopt()` and is stamped with the new version,
  - a row edited here, a row another set or nobody shipped, and a row that can
    no longer be read are kept, and each is logged by id.
- `adopt()` takes an optional version to stamp, so a package version that
  `ShippedSets` does not know is still recorded.
- The install outcome adds `refreshed` (a count) and `kept` (the ids).
- Rows of a seed file bound for another app's register are left as before: the
  ledger reads only dossiq's register.

## Impact

An existing instance gets this on the next upgrade that raises a package
version (`occ upgrade` runs `InstallShippedPackages`), or with
`occ dossiq:package:install <id>` once a newer version is on disk.
