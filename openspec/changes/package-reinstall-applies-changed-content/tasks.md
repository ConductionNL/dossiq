# Tasks: package-reinstall-applies-changed-content

- [x] 1. Tests first in PackageInstallerTest: same version with changed content, record without a digest, `--over-local-changes`; they fail on development.
- [x] 2. PackageInstaller: digest of the package files; `current` only on version AND digest; `install(id, overLocalChanges)`.
- [x] 3. ShippedConfigurationService: `recordPackage` fingerprints the digest; `packageRecorded`; `refreshRows`/`adoptOverLocalChange` take an over-local-changes flag and a version.
- [x] 4. `occ dossiq:package:install --over-local-changes`.
- [ ] 5. Operator, on an instance whose rows were hand-edited to package values (:8080 Melding Jeugdwet): `occ dossiq:package:install jeugdwet --over-local-changes`.
