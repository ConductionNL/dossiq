# Tasks: supplier-portal-reads-its-collections

- [ ] 1 Amend `openspec/specs/supplier-portal/spec.md` through this change's delta when archived: drop the `ContractController` endpoints, name the collections.
- [ ] 2 `lib/Portal/PortalContributionProvider.php`: give the supplier inbox collection a `reply` action like the citizen `berichten` inbox; a reply writes a `supplierMessage` with `direction` inbound. `tests/Unit/Portal/PortalContributionProviderTest.php` covers the shape and the scope.
- [ ] 3 Live pass (decision 139) on :8080 with portaliq: sign in as a supplier from the example set (`example-set-supplier-contracts`), confirm tenders, contracts and messages are the supplier's own, reply to a message and see it on the tender case.
- [ ] 4 `openspec/parity/capabilities.json` row 5.20: built, evidence the provider and the live pass; `screen.board` names portaliq's supplier pages if drawn, else DqContracten for the admin side.
