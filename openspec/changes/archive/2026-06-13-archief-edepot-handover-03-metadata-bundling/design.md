# Design: archief-edepot-handover-03-metadata-bundling

## Scope

TMLO/MDTO metadata bundling and the initial `SipBundel` record only. Document PDF/A export (member 04), SIP/BagIt packaging + checksums (member 05), submission (member 05), proof/rollback (member 06) are out of scope.

## Declarative-first (ADR-031)

MDTO/TMLO serialization is a standards-bound XML transformation with XSD constraints — genuinely imperative work with no declarative-schema analogue, so a builder service is the correct shape per ADR-031. The retention/classification inputs it reads (`BewaarTermijnRegel`) remain declarative (member 01).

## Data access (ADR-001)

Case, document, and `BewaarTermijnRegel` reads and `SipBundel` / `OverdrachtAuditLog` writes go through the OpenRegister ObjectService. No bespoke SQL.

## Service layout

### MetadataBundler
- `buildBundle(caseId, mdtoVersion)` — load case + rule + documents; emit MDTO/TMLO XML with all required fields (identificatie, aggregatieniveau, naam, classificatie, dekkingInTijd, beperkingGebruik, bewaartermijn, eventGeschiedenis, author) and present-optional fields; return {metadataXml, metadataXsdVersion, documents[]}.
- `validateXsd(xmlContent, xsdSchema)` — validate against the official MDTO 1.1 / TMLO 1.2.1 XSD; return {valid, errors[]}.
- `createSipBundel(caseId, metadataXml, documents[])` — persist `SipBundel` with status `prepared`.

### MDTO/TMLO XML builder
- Maps procest case fields → MDTO elements; multi-language (Dutch required, English optional); per-document metadata (type, author, creation date, restrictions); preserves digital-signature metadata.

### SchemaValidator
- `validateDocumentTypes(caseId)` — every document must carry a documentType; returns {valid, missingDocuments[]}.

## Document-type gate (no partial bundles)

If any document lacks a document-type, bundling is blocked, no `SipBundel` is created, an `OverdrachtAuditLog` `bundling-failed` event is logged, and a DIV task is raised with the filename and corrective guidance. The case stays `gereed-voor-overdracht` so bundling can be retried after correction.

## Security (ADR-005)

This member exposes no new HTTP endpoint; bundling is invoked by the pipeline/daemon. Input from the case is validated against the XSD before any submission, closing the malformed-metadata path. No IDOR surface added.

## Traceability

Giant Task 5 (bundler) + Task 6 (XSD validation); REQ-ARCH-002-A/B/C.
