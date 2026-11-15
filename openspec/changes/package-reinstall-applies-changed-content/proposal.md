---
kind: code
depends_on: []
---

# Proposal: package-reinstall-applies-changed-content

## Why

On :8080 (23 Oct) the Melding Jeugdwet case type has no `specialDataCategories` and three side cards, while package `jeugdwet` 1.4.0 ships `specialDataCategories: [health]` and four side cards. `occ dossiq:package:install jeugdwet` answers "1.4.0 is already installed" and writes nothing. Two causes, read from the instance:

1. The installer decides "current" on the version alone. A package whose content changed without a version bump (or an install recorded before the content was what it is now) is never applied again.
2. The case type's ledger row was stamped at 1.0.0 (18 Oct). On 19 Oct an API call as `admin` wrote the 1.3.0 `sideCards` and `overviewCards` onto it by hand, so the ledger reads the row as changed here, and the 1.4.0 upgrade of 21 Oct kept it, as it must for an administrator's edit. Nothing lets an operator say "take the package's version over that edit" from the command line.

## What Changes

- The package record in the shipped-set ledger also fingerprints a digest of the package's files. An install is `current` only when the version AND the digest match. A record without a digest (every install before this change) installs once more, which writes what is missing and refreshes the rows nobody edited, exactly as a version bump does.
- `occ dossiq:package:install <id> --over-local-changes` takes the package's keys over a row the package shipped and somebody changed here (`ShippedConfigurationService::adoptOverLocalChange`, the act the starter-content screen already offers per row). Keys the package does not ship keep their local value; a row another set or nobody shipped is still kept. Without the option nothing changes for an edited row.

## Impact

- `lib/Service/CaseDefinition/PackageDirectory.php`, `lib/Service/CaseDefinition/PackageInstaller.php`, `lib/Service/Starter/ShippedConfigurationService.php`, `lib/Command/InstallPackageCommand.php`, `PackageInstallerTest`, `InstallPackageCommandTest`.
- No schema change: the digest is part of the package record's existing `fingerprint`.
- On the first install after this lands, every package already installed reports `installed` once (0 written, rows nobody edited refreshed) instead of `current`.
