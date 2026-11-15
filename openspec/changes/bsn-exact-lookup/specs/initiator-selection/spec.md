# initiator-selection Delta

**Status**: proposed
**Scope**: dossiq
**OpenSpec changes**:

- [bsn-exact-lookup](../../)

## ADDED Requirements

### Requirement: A person is found by BSN through a guarded, recorded lookup

The party picker SHALL find a person by a typed BSN only through dossiq's lookup. The lookup SHALL check the caller
against the citizen-lookup guard before reading, SHALL record every refused and answered lookup, SHALL read the person
under the caller's rights, and SHALL answer the person without their number.

#### Scenario: A KCC employee types a BSN

- **WHEN** Daan types "999990627" in the requester picker
- **THEN** Sanne de Vries is listed and one answered lookup is recorded

#### Scenario: A caller without the KCC rule

- **WHEN** a Woo handler types a BSN
- **THEN** the lookup answers 403 and records the refusal
