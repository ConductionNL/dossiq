# Tasks: brp-client-retired

- [x] Call sites checked: `git grep` for the interface, the three classes and the registrar in `lib/`, `appinfo/` and `src/`, and for `OCA\Dossiq\Service\External\Brp` in integriq, openregister and pipelinq.
- [x] `PersonRelationReader` and the conflict-of-interest check on it (red first).
- [x] BRP client classes, registrar, contract fixture and their tests deleted.
- [x] BRP card message, `ConnectionsDeclarationTest`, the vitest page spec, the e2e page spec and `docs/admin/integrations.md` say what is true now.
- [ ] Live pass: with integriq's `brp-haalcentraal` source in mock mode and `relationFields` true, a worker resolved to 999990019 is refused on a case of applicant 999993653 with the reason `partner`.
