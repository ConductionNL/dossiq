# Tasks: sensitive-fields-declared

Tier: V1. Kind: config. Row 5.6.

- [ ] 1.1 Inventory (D-1); record the list here.
- [ ] 1.2 Field rules on every listed property; repair step creates the
  group.
  - `@spec openspec/changes/sensitive-fields-declared/specs/security-hardening/spec.md`
- [ ] 1.3 `lib/Service/CitizenLookupGuard.php`: drop the field check, keep
  the rate limit; unit test updated.
- [ ] 1.4 `sociaalDomeinAuditLog`: stop writing reveal rows; keep the rest.
- [ ] 2.1 `tests/e2e/sensitive-fields.spec.ts`; `openspec validate
  sensitive-fields-declared --strict`.
