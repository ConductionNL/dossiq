# Proposal: move-portals-to-portaliq

Refs: Conduction/procest#162 · hydra ADR-046 (Portaliq shared external portal).

## Summary

Move dossiq's four in-app "Portal" nav surfaces into the fleet's shared external
portal **Portaliq** (ADR-046), by extending the dependency-free
`OCA\Dossiq\Portal\PortalContributionProvider` from one audience to three and
retiring the in-app Vue portal views + their nav/routes. Portaliq discovers the
provider by convention FQCN, reads dossiq's OpenRegister collections RBAC-scoped
to the authenticated portal subject, and renders them in the one shared portal —
so dossiq no longer hosts its own external-facing portal shells.

The four retired surfaces and their new audiences:

| Retired in-app surface | Nav id | New Portaliq audience |
| --- | --- | --- |
| Supplier portal (`/leverancier`) | `LeverancierDashboard` | `supplier` (already shipped) |
| My municipality (`/portaal/mijn-zaken`) | `MijnZaken` | `citizen` (NEW) |
| Notification preferences (`/portaal/notificaties`) | `MijnNotificaties` | citizen berichtenbox inbox (NEW) |
| Field inspections (`/inspecties`) | `Inspecties` | `inspector` (NEW) |

## Why

- **One shared external portal** (ADR-046), not a portal per app. Portaliq owns the
  auth edge, the shell, the inbox and the subject resolution; dossiq only
  *declares* what each audience may see and do.
- **Zero coupling**: the provider references nothing from Portaliq, has no
  `implements`, no info.xml dependency, and is inert when Portaliq is absent.
- **No data duplication**: Portaliq reads dossiq's existing OpenRegister
  collections directly (ADR-022), subject-scoped + per-row verified.

## Scope

- Extend the provider to `getAudiences() = ['supplier','citizen','inspector']`
  with per-audience declarative collections/actions (fields-projected, fail-closed).
- Add three additive OpenRegister scope properties: `case.portaalSubject`,
  `inspectieRapport.assignedInspectorRef`, `inspectionChecklistRun.assignedInspectorRef`
  (external portal subjects have no NC account; the internal `inspector` NC-UID
  column is untouched).
- Retire the in-app portal Vue views, their manifest fragments, nav entries and
  routes; delete their now-orphaned frontend services/utils + e2e/vitest specs.

## Out of scope / kept

- The **backend** supplier + zaakportaal + inspection services and their
  `/api/leverancier-portaal/*`, `/api/portaal/*`, `/api/inspections/*` endpoints
  and the OpenRegister schemas — all KEPT (Portaliq reads OR directly; the bearer
  API may still be consumed).
- Employee-side inspection UI (case-detail `InspectionPanel` /
  `InspectionChecklistPanel`, the offline `field-inspection` leaf registration).
- The bezwaar (objection) create, the message-reply create and the inspection
  run-submit create — DEFERRED (write-IDOR, portaliq#16; see design.md).
- Raising `minTrust` from `low` to `substantial` once the DigiD/eHerkenning broker
  lands.

## Depends on

- Portaliq installed (discovers + renders the contribution; contract v2.2 reader).
- dossiq's existing OpenRegister schemas (case, supplier*, portaal*, inspectie*).
