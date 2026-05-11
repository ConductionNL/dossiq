# Tasks: stuf-support

## 1. Backend Services

### Task 1: Create StufFieldMappingService
- **spec_ref**: `openspec/specs/stuf-support/spec.md#req-stuf-013-stuf-field-mapping-configuration`
- **files**: `lib/Service/StufFieldMappingService.php`
- **acceptance_criteria**:
  - Default ZKN field mappings pre-seeded (zaakidentificatie->identifier, omschrijving->title, etc.)
  - Date format conversion YYYYMMDD <-> ISO 8601
  - Confidentiality enum mapping (ZAAKVERTROUWELIJK -> case_sensitive, etc.)
- [x] Create StufFieldMappingService

### Task 2: Create StufMessageBuilder
- **spec_ref**: `openspec/specs/stuf-support/spec.md#req-stuf-011-stuf-xml-message-processing`
- **files**: `lib/Service/StufMessageBuilder.php`
- **acceptance_criteria**:
  - Constructs valid SOAP envelopes with correct StUF namespaces
  - Populates stuurgegevens with zender/ontvanger/referentienummer/tijdstipBericht
  - Handles noValue attributes
- [x] Create StufMessageBuilder

### Task 3: Create StufController
- **spec_ref**: `openspec/specs/stuf-support/spec.md#req-stuf-014-soap-server-within-nextcloud`
- **files**: `lib/Controller/StufController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - POST /api/stuf/zaken accepts raw XML, dispatches based on root element
  - Returns SOAP XML responses with Content-Type text/xml
  - Returns Fo01 fault on invalid XML
- [x] Create StufController
- [x] Register routes
