## ADDED Requirements

### Requirement: ZGW endpoints respond within 200 ms on average
All ZGW API endpoints (ZRC, ZTC, DRC, BRC) SHALL have an average response time under 200 ms when querying registers with up to 1 000 objects.

#### Scenario: Zaak retrieval within latency target
- **WHEN** a client calls `GET /api/zgw/zaken/v1/zaken/{uuid}`
- **AND** the register contains up to 1 000 zaken
- **THEN** the response SHALL be returned within 200 ms on average (p50)

#### Scenario: Zaak listing within latency target
- **WHEN** a client calls `GET /api/zgw/zaken/v1/zaken` with default page size
- **THEN** the response SHALL be returned within 200 ms on average (p50)

### Requirement: Batch cross-register lookups via property inversion
The system SHALL use OpenRegister's `ObjectService::getObjects()` with `_filters` (property inversion) to batch all related-object lookups, replacing per-object individual calls.

#### Scenario: Zaaktype lookup uses batched query
- **WHEN** a ZGW endpoint needs to resolve a zaaktype reference
- **THEN** the system SHALL call `ObjectService::getObjects()` with a `zaaktype` filter
- **AND** SHALL NOT make individual `ObjectService::getObject()` calls per zaak in a loop

#### Scenario: Statustype resolution uses batched query
- **WHEN** enriching a list of zaken with their current statustype details
- **THEN** the system SHALL collect all statustype UUIDs from the result set
- **AND** fetch them in a single `ObjectService::getObjects()` call with `_filters[uuid][in]`
- **AND** map results back to their parent zaken in memory

### Requirement: Related-object queries capped at 1 000 results
To prevent unbounded result sets during enrichment, all `_limit` parameters on internal ObjectService queries for related objects SHALL be capped at 1 000.

#### Scenario: Internal query respects limit cap
- **WHEN** the enrichment layer calls `ObjectService::getObjects()` for related objects
- **THEN** the `_limit` parameter SHALL be set to at most 1 000

### Requirement: Modified capabilities — procest-case-management and procest-object-store

#### Scenario: ZGW zaak lifecycle side effects match VNG standard exactly
- **WHEN** an eindstatus is created
- **THEN** all side effects (einddatum, archiefactiedatum derivation, indicatieGebruiksrecht cascade) SHALL execute in a defined order: validate first, then update zaak, then update linked objects
- **AND** no legacy enrichment logic SHALL duplicate or conflict with these side effects

#### Scenario: Cross-register sync uses cascade-delete path
- **WHEN** a ZIO is deleted
- **THEN** OIO deletion SHALL occur within the same request lifecycle (synchronous, not background)
- **AND** failures SHALL be logged but SHALL NOT roll back the ZIO deletion
