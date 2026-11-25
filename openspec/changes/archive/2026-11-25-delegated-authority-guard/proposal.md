# Delegated authority guard

## Why

Decision 182 (procedures are configuration) and hydra ADR-118 move the mandate registry to decidiq (capability C17, refactor lane R9, question Q6 on its recommended option). The `mandaatGuard` asked an external mandaatregister over HTTP through `MandaatValidationService`, and accepted a manual confirmation flag (`mandaatHandmatigBevestigd`) that no schema declares and no screen sets.

## What changes

- New generic `Transitions/DelegatedAuthorityGuard`, guard type `delegatedAuthorityGuard`. It asks decidiq's `DelegatedAuthorityCheck` (paired decidiq PR) whether the acting person holds the authority for an act. Config: `act` or `actField`, optional `actorField` (default `signatory`, then the current user) and `amountField` (euro cents).
- The legacy type `mandaatGuard` stays answered by the same guard with `actField: mandaatCategorie`, so stored workflow definitions keep working.
- The `bvw-mandaatbesluit` template declares the new type.
- Deleted: `Transitions/MandaatGuard`, `MandaatValidationService`, `MandaatController` and its route `GET /api/besluitvorming/cases/{id}/mandaat-check` (no frontend caller), the config keys `mandaatregister_endpoint` and `mandaatregister_token`, and their unit tests.
- Every uncertain answer refuses: no decidiq, a decidiq that throws, no act, an amount that is not a number.

## Not in this change

The mandate matrix (`MandaatCheckService`, escalation, usage log, CSV import, registry, the three remaining controllers and their settings tabs) moves in a follow-up pair, after #3300 lands. `Beschikking/MandaatVerifier` moves with lane R5 (it sits inside `BeschikkingService`, which R5 owns).
