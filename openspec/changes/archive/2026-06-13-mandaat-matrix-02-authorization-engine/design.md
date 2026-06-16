# Design — Member 02: Authorization Engine (code)

## Scope

`MandaatCheckService` + `AbacPolicyService`. Reads `Mandaat`, `OrganisatieRol`, and
`MedewerkerRolToewijzing` (declared in member 01) via OpenRegister `ObjectService` (ADR-001).

## Service contract

`MandaatCheckService.isAuthorized(userId, decisionType, caseId)` →
`{authorized, mandaatId?, escalatieId?, reden?}`:

1. `getApplicableMandaten(decisionType, caseType, date)` — query Mandaat where decisionType
   matches AND `geldigVanaf ≤ date ≤ geldigTotEnMet`.
2. `resolveUserRole(userId, date)` — look up MedewerkerRolToewijzing active on `date`
   (`toewijzingVanaf ≤ date ≤ toewijzingTotEnMet`); return primary role plus any waarnemer
   assignments with a waarnemer flag.
3. Match: does the resolved role (or a role it waarneems) hold one of the applicable mandaten?
   If not → `{authorized: false, reden: "niet_bevoegd"}`.
4. `evaluateConditions(mandaat, caseProperties)` — delegate to `AbacPolicyService`; parse
   `mandaat.voorwaarden` (plafond_bedrag, subdelegatie_toegestaan) and match case properties
   (e.g. bouwsom). On failure → `{authorized: false, reden: "plafond_overschreden" |
   "subdelegatie_niet_toegestaan"}`.
5. On success → `{authorized: true, mandaatId: mandaat.uuid}`.

`AbacPolicyService.evaluatePolicy(policyName, factSet)` → `{allowed, violations[]}` wraps the
OpenRegister policy engine. Fact set: `{userId, rolId, mandaatId, caseType, caseProperties,
decisionType}`. Conditions (plafond, subdelegatie) are evaluated atomically by the engine; this
member only assembles the fact set and interprets the result.

## ADR-031 note

Condition evaluation is delegated to OpenRegister's declarative policy engine rather than
hand-rolled imperative rule code wherever the engine can express the rule. The PHP service is the
orchestration + fact-assembly glue, which has no declarative analogue (ADR-032 "pure code, no
declarative surface").

## Security (ADR-005)

`isAuthorized()` is a server-authoritative check; it derives the user's role from server-side
MedewerkerRolToewijzing records, never from client input. The verdict's `reden` is safe to return
to the caller. No new HTTP endpoints in this member (the `POST /api/mandate/check` surface and any
guards land where they are consumed, member 05).
