# Proposal: Mandaat-matrix voor zaak-gestuurde besluitvorming

## Summary

Implement a data-driven mandate registry in Procest that enforces real-time authorization checks on case decisions, tracks mandates against legislative decisions (mandateringsbesluit), supports effective dating, handles role-based escalations, and maintains complete audit trails for compliance. This enables Dutch municipalities to move from manual mandate lookups (Word/Excel documents) to automated, verifiable decision authority.

## Why

In Dutch local government, municipalities handle hundreds to thousands of decisions annually through delegates (via mandaat under Awb article 10:3). Currently, mandate tables are static Word/Excel documents managed by Legal Affairs, leading to:
- **Risk of illegal decisions** — decision-makers without proper mandate authority
- **Delayed decision-making** — manual escalations for authority verification  
- **Lost audit trails** — difficult to reconstruct who was authorized to decide
- **Operational friction** — mandate updates on personnel changes require table rewrites
- **Compliance gaps** — no enforceable link between mandate and legislative authorization (mandateringsbesluit)

The mandate-matrix capability automates this by:
1. Registering mandates per organization and governing body (college, burgemeester, raad, secretaris)
2. Linking each mandate to its legal basis (mandateringsbesluit) and validity period
3. Checking real-time authorization on every decision (role + plafond + conditions)
4. Auto-escalating when authority is exceeded
5. Maintaining immutable audit trails per decision
6. Supporting personnel changes without mandate-table rewrites

## What Changes

1. **New OpenRegister schemas** — MandateringsBesluit, Mandaat, OrganisatieRol, MedewerkerRolToewijzing, MandaatGebruik, MandaatEscalatie
2. **Real-time bevoegdheidscheck** — Executed on every decision action via abac-policy-engine integration
3. **Escalation engine** — Auto-routes decisions exceeding authority to correct mandaathouder
4. **Effective dating** — Mandates with retroactive or future-dated entry
5. **Waarnemer (substitute) support** — Delegates authority during absence without mandate-table changes
6. **Complete audit trail** — Snapshot of role, mandate, and conditions used at decision time
7. **Admin UI** — Browse mandate matrix per zaaktype, view bevoegdheden, import from decidesk besluiten
8. **Person-to-role mapping** — Automatic delegation on personnel mutations; escalation rerouting

## Impact

- **New schemas** — 6 new OpenRegister entities
- **Procest integration** — Bevoegdheidscheck service, escalation handler, mandate import
- **OpenRegister policy engine** — New ABAC policy engine integration for authorization
- **Cross-app** — decidesk (mandateringsbesluit source), openconnector (HR sync), mydash (analytics)
- **Affected workflows** — All zaaktype decision points enforce authorization
- **Data dependencies** — Requires procest base (zaak, decision), HR role registry, organizational hierarchy

## Out of Scope

- Generic RBAC/ABAC engine (handled by openregister abac-policy-engine)
- Delegation (volmacht) vs mandate differentiation (volmacht handled separately)
- Mandaatbesluit drafting and publication workflow (delegated to decidesk)
- Bezwaar/beroep authority routing (separate capability)
- Integration with national mandate registry (future)

## Dependencies

- **procest base** (REQUIRED) — zaaktype, zaak, decision infrastructure
- **openregister abac-policy-engine** (REQUIRED) — Fine-grained authorization policy evaluation
- **decidesk** (REQUIRED) — Source of mandateringsbesluit (legislative authority)
- **openconnector** (optional) — HR system sync (AFAS, ADP) for role assignments
- **mydash** (optional) — Mandate analytics and KPI dashboards

## Acceptance Criteria

1. GIVEN a case with decision type requiring mandate M.3.1.2, WHEN a user with the appropriate role attempts the decision, THEN system validates authority in <100ms and either approves or escales
2. GIVEN a mandateringsbesluit "Algemene mandaatregeling 2026" in decidesk, WHEN legal affairs import it, THEN Mandaat records are created per table row with draft status for review
3. GIVEN a mandate with plafond €100K and a decision with impact €250K, WHEN the user attempts the decision, THEN system auto-escalates to next-level mandaathouder and notifies
4. GIVEN a user on leave with waarnemer assigned, WHEN a decision is needed, THEN the waarnemer's authority is checked and logged as "via waarnemer"
5. GIVEN a zaak completed in 2025, WHEN an auditor reviews the decision in 2026, THEN the audit trail shows which mandate and conditions were used (snapshot, not current state)
6. GIVEN a person change (new teamleider), WHEN HR updates role assignment, THEN all mandates transfer to new person automatically without mandaatregeling change
