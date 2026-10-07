# Tasks: case-delete-guard

Tier: V1. Kind: code. Row 2.17.

- [x] 1.1 `lib/Exception/CaseHeldException.php` carrying rule slugs and a
  message; mapped to 409 in the controller translation table (ADR-105).
- [x] 1.2 `lib/Listener/CaseDeleteGuardListener.php`: the four rules of D-1,
  each one bounded query; stop the event with the exception.
  - `tests/Unit/Listener/CaseDeleteGuardListenerTest.php`: one test per rule,
    one for two rules together, one for a free case
  - `@spec openspec/changes/case-delete-guard/specs/case-management/spec.md`
- [x] 1.3 `lib/AppInfo/Registrar/ObjectListenerRegistrar.php`: bind to
  schema `case` at the registration site.
- [x] 2.1 Remove `src/modals/DeelzaakDeleteWarningModal.vue` and its caller;
  the delete action shows the platform's refusal message.
- [x] 3.1 `tests/e2e/case-delete-guard.spec.ts`; `openspec validate
  case-delete-guard --strict`.
