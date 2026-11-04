# Proposal: claim-to-edit-banner

## Why

Ruben decided (3 Nov 2026) that members of a case's assigned group may read the case within its organisation, up to
and including `vertrouwelijk`. A member who is not the case's handler reads it but may not change it. They need to see
that, and one button that makes them the handler, after which they can edit.

## What changes

- The case page reads the record rights (`config.permissions: true`), so its edit controls follow `@self.can.update`.
- A new banner in the case banner row, `case-claim-banner`: "Voeg jezelf toe als behandelaar om te kunnen bewerken",
  shown while `@self.can.update` is false, with one button that runs the declared OpenRegister action named in its
  `declaredAction` (`claim-as-handler`) and reads the page again.
- The library shows the banner only while `@self.can` grants that action, so it stays hidden until OpenRegister declares
  the action on the case schema.

## Scope

dossiq `src/manifest.json`, en/nl l10n and the vendored manifest schema. Needs nextcloud-vue
`detail-page-record-rights`. The read right for group members and the declared action itself belong to OpenRegister
and the case schema, not to this change.
