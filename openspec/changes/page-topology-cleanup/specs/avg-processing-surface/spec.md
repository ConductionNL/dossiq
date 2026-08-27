# avg-processing-surface

## ADDED Requirements

### Requirement: The AVG processing-activities surface is owned by OpenRegister

Per ADR-047, the AVG/DSAR workflow and its register are OpenRegister
capabilities. Procest SHALL NOT host a processing-activities page of its own.
Any procest-specific framing that OpenRegister's `/avg` surface does not yet
provide — catalogue review status, the unclassified-processing counter, the
per-betrokkene inzageverzoek export entry point — SHALL be contributed to
OpenRegister before procest's page is retired, so no capability is lost in the
move.

#### Scenario: Procest hosts no processing-activities page

- **GIVEN** the procest manifest
- **THEN** no `/verwerkingen` page and no processing-activities NAVIGATION entry exist
- **AND** `VerwerkingenOverview.vue` is not registered as a component
- **AND** the link into OpenRegister's `/avg` surface is a `section: "integrations"` entry, gated on `visibleIf.appInstalled: "openregister"`

> **Clarified 2026-08-26 by ADR-110.** This requirement was already the strictest
> of the three surface specs — it forbade a menu entry outright where the
> automatic-actions and ai-oversight specs each mandated one, and ADR-047's
> migration step prescribed a deep link. That inconsistency is resolved in this
> spec's favour fleet-wide, and ADR-047 is amended to match. What changes here is
> only the destination being named: the link survives as an Integrations entry
> rather than vanishing, which is what keeps ADR-044 Decision 5 satisfied.

#### Scenario: The capability is reachable in OpenRegister

- **GIVEN** an FG or administrator opens OpenRegister's `/avg` page
- **THEN** the catalogue review status, the unclassified-processing counter and the inzageverzoek export are all available there

#### Scenario: Authorization stays fail-closed

- **GIVEN** a user who is neither FG nor administrator
- **WHEN** they call the AVG endpoints
- **THEN** the request is refused server-side, before any processing data is returned
