# Tasks: package-reinstall-applies-changed-content

- [x] 1. Tests first in PackageInstallerTest: same version with changed content, record without a digest, `--over-local-changes`; they fail on development.
- [x] 2. PackageDirectory::read() gives a digest of the package files; PackageInstaller is `current` only on version AND digest; `installOverLocalChanges(id)` beside `install(id)`.
- [x] 3. ShippedConfigurationService: `recordPackage` fingerprints the digest; `isPackageCurrent`; `refreshRows` takes the over-local-changes choice.
- [x] 4. `occ dossiq:package:install --over-local-changes`.
- [ ] 5. Operator, on an instance whose rows were hand-edited to package values (:8080 Melding Jeugdwet): `occ dossiq:package:install jeugdwet --over-local-changes`.
