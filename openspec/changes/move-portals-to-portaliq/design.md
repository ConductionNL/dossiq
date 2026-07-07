# Design: move-portals-to-portaliq

Refs: Conduction/procest#162 · hydra ADR-046 · Portaliq contract v2.2.

## The 3-audience scoping map

All scoping uses the subject's server-derived **pseudonymous subjectRef** as the
scope VALUE (Portaliq's DEFAULT — no `scopeClaim`), matched against the reference
the OpenRegister row already stores in its scope field. Never a Nextcloud user id
(portal subjects have no NC account), never a raw BSN/KvK (one-way hashed into the
subjectRef upstream). Reads are field-projected AFTER Portaliq's per-row
verification (identifiers always survive; a malformed `fields` degrades to
identifiers-only). `minTrust: low` throughout (Portaliq's password edge) pending a
DigiD/eHerkenning broker.

| Audience | Collection | Schema | scopeField | via (match) | Field whitelist |
| --- | --- | --- | --- | --- | --- |
| supplier | tenders | `supplierTender` | `supplierRef` | — | (full row — unchanged v1) |
| supplier | contracts | `supplierContract` | `supplierRef` | — | (full row) |
| supplier | invoices | `supplierInvoice` | `supplierRef` | — | (full row) |
| supplier | messages (inbox) | `supplierMessage` | `supplierRef` | — | (full row) |
| citizen | mijnZaken | `case` | `portaalSubject` | — | identifier, title, caseType, status, result, startDate, endDate, deadline |
| citizen | berichten (inbox) | `portaalBericht` | `recipientRef` | — | caseReference, senderType, senderName, subject, content, attachments, direction, sentAt, readByRecipientAt |
| citizen | verzoeken | `portaalVerzoek` | `submitterRef` | — | soort, categorie, onderwerp, motivering, referentie, status, submittedAt, deadline, binnenTermijn |
| inspector | inspectieRapporten | `inspectieRapport` | `assignedInspectorRef` | — | case, checklist, inspectionDate, location, result, failedItems, remarks, followUpRequired |
| inspector | checklistRuns | `inspectionChecklistRun` | `assignedInspectorRef` | — | case, template, templateVersion, startedAt, completedAt, submittedAt, status, overallResult, followUpType, syncState |

No `via` joins are used: every audience scopes on a scalar reference the record
carries directly. (Portaliq's contract supports one-hop `via` joins and reverse
`match: 'scopeField'` joins — see scholiq — but procest's data model does not need
them here.)

### Dropped (staff/internal) columns, by collection

- **case**: assignee, confidentiality, priority, workflow*, quality*, archive*,
  payment*, source/handoff/initiator internals, geometry, statusHistory/activity.
- **portaalBericht**: senderRef, recipientRef (internal scope keys).
- **portaalVerzoek**: submitterRef/submitterName, betrokkenMedewerker,
  tegenZaakId/tegenBeschikkingId/nieuweZaakId (staff linkage).
- **inspectieRapport**: inspector (internal NC UID), items, photos.
- **inspectionChecklistRun**: inspector (internal NC UID), templateSnapshot
  (frozen/large), responses, photos.

## Claim-names contract (forward path)

Current scoping uses the DEFAULT subjectRef directly — the record already stores
the pseudonymous subject reference in its scope field. The **stable claim
contract** for the forward path, once Portaliq's `portalAccount` rows carry
verified claims and procest scopes via `scopeClaim`, is:

```
claims.procest.bsn          → the citizen's verified BSN-derived subject key
claims.procest.supplierRef  → the supplier's verified KvK-derived subject key
claims.procest.inspectorRef → the external inspector's verified subject key
```

**Deviation note (verified against HEAD).** The brief suggested the citizen
collections scope via `scopeClaim: 'bsn'` through a rol→zaak join. procest's actual
data model does NOT support that: cases carry no rol row that references a citizen
by BSN + a zaak ref, and the in-app `PortalCaseService` already scopes cases by a
single `case.portaalSubject == subjectRef` filter holding a *hashed* subject
reference (never a raw BSN). So a `scopeClaim: 'bsn'` would resolve a raw BSN that
`portaalSubject` never stores → zero rows. The honest, matching model is DEFAULT
subjectRef against `portaalSubject`. The `claims.procest.*` map above is the
forward indirection to adopt when verified claims land.

## Additive scope properties (Wave-3-style, noted)

Three properties were added additively to `lib/Settings/procest_register.json`
(no existing property removed; internal semantics untouched):

- `case.portaalSubject` — pseudonymous citizen subject reference. Mirrors the
  filter `PortalCaseService::listForSubject()` already uses; formalises it on the
  schema so the register-drift pin holds.
- `inspectieRapport.assignedInspectorRef` and
  `inspectionChecklistRun.assignedInspectorRef` — the EXTERNAL inspector's
  pseudonymous portal reference, distinct from the existing `inspector` column
  ("NC user UID — server-derived, never accepted from body"). External inspectors
  have no NC account, so their reports/runs cannot be scoped by an NC UID; the new
  ref is the portal scope key.

These fields have no writer yet (identity/write wiring is deferred with the DigiD /
inspector broker), so Portaliq reads them fail-closed to empty until populated —
the same aspirational state the in-app portals were in (`portaalSubject` was never
written either). The move relocates the declarative surface without regressing
behaviour.

## Actions — shipped vs deferred creates

**Shipped**: `createKlacht` (citizen) — a standalone complaint on `portaalVerzoek`
stamping `submitterRef == subjectRef`, whitelisting only the citizen's own content
(soort, categorie, onderwerp, motivering, attachments). No case cross-reference, so
it can never grant access to another party's record; the created object is owned by
the submitter and triaged by staff.

**Deferred (write-IDOR, portaliq#16)** — Portaliq's flat writer only server-stamps
the scope field; it does NOT verify a client-supplied cross-reference against the
subject's own set:

- **citizen bezwaar (objection)** — needs a client `tegenZaakId` cross-reference +
  AWB deadline validation (the in-app flow calls a deadline-validation endpoint
  first). Re-add once Portaliq validates create-body cross-refs.
- **citizen message reply** — needs a verified case/thread linkage (`caseId` /
  `recipientRef`) the writer cannot constrain.
- **inspector run submit** — needs client `case`/`template` cross-references the
  writer cannot verify against the inspector's assignment.

## Retired vs kept

**Retired (deleted):** `src/views/leverancier/*` (8 views), `src/views/portaal/*`
(MijnZaken, MijnNotificaties + 5 components), `src/services/leverancierApi.js`,
`src/utils/portaalForms.js` (orphaned), manifest fragments
`60-leverancier.json` / `50-zaakportaal.json` / `70-mobiel-inspectie.json`, the
`registry.js` + `customComponents.js` entries, the `PortaalGroup` group + its
menu-layout relocations (nav ids added to `removals`), and the e2e/vitest specs
that drove those views (`leverancier-zaakportaal.spec.ts`,
`zaakportaal-mijngemeente.spec.ts`, `spec-coverage/portaal-forms.spec.ts`,
`spec-coverage/mobiel-inspectie-offline.spec.ts`, `vitest/portaalForms.spec.js`).

**Kept:** all backend services + `/api/leverancier-portaal/*`, `/api/portaal/*`,
`/api/inspections/*` endpoints and their PHPUnit suites; the OpenRegister schemas;
employee-side case-detail inspection panels; the `field-inspection` OR integration
leaf registration in `src/main.js`; routes are derived from the manifest, so
deleting the fragments removes the routes with no router edit.
