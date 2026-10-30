## ADDED Requirements

### Requirement: Typed relations are read under the case schema

Reading a case's typed relations SHALL ask OpenRegister under the configured register and case schema, whatever register or schema an earlier call in the same request set.

#### Scenario: A case that waits on another
- GIVEN case 2026-0110 waits on "Advies verlichting fietspad"
- AND an earlier call in the request left another schema set on the object service
- WHEN the related cases card reads the case's relations
- THEN it lists the case under "Wacht op"
