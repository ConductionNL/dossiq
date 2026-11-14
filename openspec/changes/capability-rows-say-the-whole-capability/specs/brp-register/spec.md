# brp-register

## ADDED Requirements

### Requirement: A person or company is taken over from its registry and keeps following it (REQ-CRS-05)

A handler SHALL be able to take a person or company over from its national registry into a contact. The contact SHALL then follow that registry, and a change the registry reports to a field it owns SHALL be written onto the contact.

Rows: `5.11` (dossiq matrix).

#### Scenario: A registry reports a new address
- **GIVEN** a contact taken over from the person registry
- **WHEN** the registry reports that the person moved
- **THEN** the contact carries the new address without a handler typing it

### Requirement: A person or company is looked up in the live registry when one is connected (REQ-CRS-23)

When a person or company registry is connected, a handler's lookup SHALL ask that registry and show its answer. Without a connection the lookup SHALL say that no registry is connected instead of an empty result.

Rows: `12.6` (dossiq matrix).

#### Scenario: No registry connected
- **GIVEN** an instance with no company registry connected
- **WHEN** a handler looks up a KvK number
- **THEN** the answer says no registry is connected
