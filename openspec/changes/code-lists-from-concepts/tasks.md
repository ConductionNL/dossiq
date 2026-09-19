# Tasks: code-lists-from-concepts

Tier: V1. Kind: config. Row 11.10.

- [x] 1.1 `lib/Settings/dossiq_register.json` `propertyDefinition.conceptScheme`,
  schema version moved to 1.4.0 so an instance reconciles the new key.
  - `@spec openspec/changes/code-lists-from-concepts/specs/property-definition-management/spec.md`
- [x] 1.2 The case data property renderer: scheme picker when set (D-1,
  D-2); precedence unit test. `src/services/conceptScheme.js` holds the one
  precedence rule the form and the index both read; the binding is declared
  and not forwarded, because OpenRegister has not published
  `x-openregister-concept-scheme` yet (`PENDING_PLATFORM_KEYS`), and the
  picker is the platform's per D-2. Mutation-checked: dropping the scheme
  branch from `optionSourceFor()` reddens three assertions, one of them
  `expected 'inline' to be 'scheme'`.
- [x] 1.3 Property definitions index: Scheme column; authoring warning
  when both are set.
- [x] 2.1 `tests/e2e/code-lists-from-concepts.spec.ts`; `openspec validate
  code-lists-from-concepts --strict` passes.
