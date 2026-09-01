# Tasks

- [x] Confirm Organisation carries every `partnerOrganization` property
- [x] `PartnerMigrationService`, idempotent by the partner's own id, preserving it
- [x] Mark migrated partners `type: partner` / `isLocalTenant: false`
- [x] Migrate a slug-less partner (slug is optional); refuse only a row with no id
- [x] `occ dossiq:migrate-partners`, registered in info.xml
- [ ] Run it on an instance with real partner rows and record the count
- [ ] Point the Partners page at Organisation, then retire the schema and the page
