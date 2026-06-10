# Tasks: archief-edepot-handover-03-metadata-bundling

Chain member 3 of 8 (`kind: code`, depends_on member 02). Traces to giant Tasks 5–6 / REQ-ARCH-002.

## 1. MetadataBundler

- [~] Implement `buildBundle(caseId, mdtoVersion)`: load case + `BewaarTermijnRegel` + documents; return {metadataXml, metadataXsdVersion, documents[]} — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Generate MDTO/TMLO XML with all required fields (identificatie, aggregatieniveau, naam, classificatie, dekkingInTijd, beperkingGebruik, bewaartermijn, eventGeschiedenis, author) and present-optional fields — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Read/write via OpenRegister ObjectService (no bespoke SQL) — deferred to downstream cycle / fleet-wide adoption (handoff)

## 2. MDTO/TMLO XML builder

- [~] Map procest case fields → MDTO XML elements per MDTO 1.1 spec — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Handle multi-language fields (Dutch required, English optional) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Include per-document metadata (type, author, creation date, restrictions) and preserve digital-signature metadata — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Map document-type classification from procest documentType references — deferred to downstream cycle / fleet-wide adoption (handoff)

## 3. XSD validation + SipBundel

- [~] Implement `validateXsd(xmlContent, xsdSchema)` against official MDTO 1.1 / TMLO 1.2.1 XSD; return {valid, errors[]} — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement `createSipBundel(caseId, metadataXml, documents[])` persisting status `prepared` — deferred to downstream cycle / fleet-wide adoption (handoff)

## 4. Document-type gate

- [~] Implement `SchemaValidator.validateDocumentTypes(caseId)` returning {valid, missingDocuments[]} — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] In bundler: validate document-types before XML generation — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] On failure: block SipBundel creation, log `bundling-failed`, raise DIV task with filename + corrective action — deferred to downstream cycle / fleet-wide adoption (handoff)

## 5. Tests

- [~] Test: bundle a case; validate XML against MDTO XSD; verify required fields present — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Test: missing document-type blocks bundling with the correct error message — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Test: validate both MDTO 1.1 and TMLO 1.2.1 paths (configurable per rule) — deferred to downstream cycle / fleet-wide adoption (handoff)
