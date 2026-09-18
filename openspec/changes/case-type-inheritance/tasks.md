# Tasks: case-type-inheritance

Tier: V1. Kind: code. Row 11.21.

- [ ] 1.1 `caseType.parentCaseType` in `lib/Settings/dossiq_register.json`, a
  reference to another `caseType`, optional, absent on every seeded type.
  - `@spec openspec/changes/case-type-inheritance/specs/case-type-inheritance/spec.md`
- [ ] 1.2 `inheritedFrom` on the inheritable elements, so a compiled element
  names the mother it came from.
- [ ] 2.1 `lib/Service/CaseType/CaseTypeInheritanceCompiler.php`: compile the
  mother's properties, status types, transitions, role types, document types
  and result types into the child, then apply the child's additions and
  overrides by key.
  - Unit test: a mother with three statuses and a child adding one compiles to
    four, in the mother's order with the child's last
- [ ] 2.2 The compiler runs on publish, from the existing publish path. It
  never runs on read.
- [ ] 3.1 `case-type-publish-validation` refuses a child that removes an
  element the mother declares required, naming the element.
- [ ] 3.2 It refuses a `parentCaseType` cycle, naming the two case types.
- [ ] 4.1 Publishing a mother lists its children and offers a new draft
  version per child through `CaseTypeVersionChain`. Nothing is republished
  without that press.
- [ ] 5.1 `src/manifest.json` `#CaseTypeDetail`: a Mother panel naming the
  parent and linking to it, and an inherited marker on every compiled element.
- [ ] 6.1 `tests/e2e/case-type-inheritance.spec.ts`: add a field to the
  mother, republish one child, the child carries it and the sibling does not
  until it is republished too.
