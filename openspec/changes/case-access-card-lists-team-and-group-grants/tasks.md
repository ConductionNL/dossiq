# Tasks: case-access-card-lists-team-and-group-grants

## 0. Dependencies
- [ ] 0.1 openregister `an-object-lists-its-grants-with-their-origin` merged: `GET /api/objects/{r}/{s}/{id}/grants` and the `readGrants` verb
- [ ] 0.2 nextcloud-vue record list `sections` (one card, several sources) and `meta.cases` with `op: contains` (design D-5)
- [ ] 0.3 people-tab-access-card merged (the card this change extends)

## 1. Who may read a case's grants
- [ ] 1.1 `lib/Settings/register.d/99-case-grants-read.json`: `readGrants` on the case schema, the assignee match and the background service (design D-4)
- [ ] 1.2 `CaseTeamAccessProjector` carries `readGrants` through a recompute; unit test beside its existing tests

## 2. The card (manifest config, `src/menu-layout.simple.json`)
- [ ] 2.1 `case-access-card` moves to `sections`: the link section unchanged, then a grants section over `/apps/openregister/api/objects/dossiq/case/@objectId/grants?principalTypes=user,group`, `responsePath` `results`
- [ ] 2.2 Avatar: `AccountGroup` icon for `principal.type` `group`, initials of `principal.displayName` for `user`, 32px (register `AccountGroup` in `src/icons.js` if absent)
- [ ] 2.3 Title `{principal.displayName}`; origin line from `meta.cases` per design D-3; pill `Expires {expiresAt|date}` when set; no row action
- [ ] 2.4 Hide `dossiq-background-service` by `principal.id`
- [ ] 2.5 Section texts: empty "Nobody else has access to this case", 403 "Only the people this case is assigned to can see who has access", 500 "Could not read who has access"
- [ ] 2.6 The card's `_note` names the board rows and this change

## 3. Strings (en source, nl in `l10n/nl.json` and `l10n/nl.js`)
- [ ] 3.1 "via the case type" / "via het zaaktype"
- [ ] 3.2 "via the role {role}" / "via de rol {role}"
- [ ] 3.3 "via a rule on all cases" / "via een regel op alle zaken"
- [ ] 3.4 "shared by {name}" / "gedeeld door {name}"; "shared by a former user" / "gedeeld door een oud-gebruiker"
- [ ] 3.5 "via the main case, shared by {name}" / "via de hoofdzaak, gedeeld door {name}"
- [ ] 3.6 "owner of the case" / "eigenaar van de zaak"
- [ ] 3.7 "Nobody else has access to this case" / "Niemand anders heeft toegang tot deze zaak"
- [ ] 3.8 "Only the people this case is assigned to can see who has access" / "Alleen de mensen aan wie deze zaak is toegewezen zien wie toegang heeft"
- [ ] 3.9 "Could not read who has access" / "Kon niet lezen wie toegang heeft"

## 4. Tests
- [ ] 4.1 vitest `tests/vitest/caseAccessCardBoard.spec.js`: the grants section's source, row fields and origin cases
- [ ] 4.2 Example data: on the Lindelaan case, the handling team "Team Woo en informatie" through its case type, and a share by Pieter Jansen with "Juridische zaken" (DATA)
- [ ] 4.3 Live pass against the board DqZaakBetrokkenen with a pixel diff of the Toegang card
