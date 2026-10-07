---
kind: config
depends_on: []
---

# Proposal: sensitive-fields-declared

Competitor gap register, row 5.6 "Sensitive personal data behind an extra
permission" (`procest/_gaps/gap-register.md` in
ConductionNL/market-intelligence, 2026-09-13). Rated partial, owner
openregister, slug `sensitive-fields-declared (dossiq)`, size S. This is
dossiq's half; the mechanism is OpenRegister's `row-field-level-security`.

## Why

dossiq guards sensitive data in code where the platform guards it in
declaration. `register.d/50-sociaal-domein.json#gdprClassification.accessRestriction`
names a restriction, `lib/Service/CitizenLookupGuard.php` enforces one in
PHP, and `sociaalDomeinAuditLog` records reveals for the sociaal domein
only. The BSN on a requester and the medical fields on a Wmo case are
readable by anyone who may read the case.

The best competitor in the register: xxllnc Zaken,
`backend/zaken/src/zsnl_domains/case_management/entities/person_sensitive_data.py`
(`_round2/compare/M1-functionality.md`).

## What changes

- The BSN fields and every field classified `gdprClassification` special
  category are declared with a field rule requiring the extra group
  `dossiq-sensitive`, in the `row-field-level-security` vocabulary, on the
  schemas that carry them.
- The reveal is audited by OpenRegister's field-access audit; the
  `sociaalDomeinAuditLog` rows that only recorded reveals are retired.
- `CitizenLookupGuard` keeps only what the declaration cannot express
  (the lookup rate limit); its field check goes.

## Ownership

dossiq builds the declarations and the retirement. It consumes openregister
`row-field-level-security` (spec exists on openregister `development`) for
the rule and the audit.

## ADRs

- Company ADR-023 rule 1: data RBAC is OpenRegister's; dossiq does not
  roll its own.
- Company ADR-031: the rule is declared on the schema.
- Company ADR-047: the AVG audit is OpenRegister's surface.

## Capabilities

- Modified: `security-hardening`: sensitive fields are declared behind an
  extra group and their reveal is audited by the platform.

## Impact

`lib/Settings/dossiq_register.json` and `register.d/50-sociaal-domein.json`
(field rules); `lib/Service/CitizenLookupGuard.php` (narrowed);
`sociaalDomeinAuditLog` (reveal rows retired); unit tests; one e2e spec.
