---
kind: code
depends_on: [package-reinstall-applies-changed-content]
---

# Proposal: repair-updates-installed-packages

## Why

The `InstallShippedPackages` repair step installs only `auto` packages. An `on-request` package an administrator installed (jeugdwet, bezwaar-beroep, subsidie, aanbesteding, besluitvorming) never gets a newer version or changed content on upgrade: on :8080 Melding Jeugdwet stayed on old content until someone ran `occ dossiq:package:install jeugdwet` by hand. Coordinator decision 23 Oct: the upgrade updates every package that is already installed, whatever its install mode.

## What Changes

- `InstallShippedPackages` calls `PackageInstaller::reloadInstalled()` (every `auto` package, plus every package the ledger records as installed) instead of `installAutomatic()`. Each goes through `install()`, so a package whose version and digest are recorded writes nothing, and a changed one writes what is missing and refreshes only rows nobody edited. Rows edited here are kept, as on every normal install.
- An `on-request` package that was never installed is still left alone.
- No `--over-local-changes` on upgrade: taking an edit over stays an operator's act.

## Impact

- `lib/Repair/InstallShippedPackages.php`, `InstallShippedPackagesTest`, `PackageInstallerTest`.
