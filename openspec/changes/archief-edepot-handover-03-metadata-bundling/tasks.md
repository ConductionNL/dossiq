# Tasks: archief-edepot-handover-03-metadata-bundling

Chain member 3 of 8 (`kind: code`, depends_on member 02). Traces to giant Tasks 5–6 / REQ-ARCH-002.

## 1. MetadataBundler

- [ ] Implement `buildBundle(caseId, mdtoVersion)`: load case + `BewaarTermijnRegel` + documents; return {metadataXml, metadataXsdVersion, documents[]}
- [ ] Generate MDTO/TMLO XML with all required fields (identificatie, aggregatieniveau, naam, classificatie, dekkingInTijd, beperkingGebruik, bewaartermijn, eventGeschiedenis, author) and present-optional fields
- [ ] Read/write via OpenRegister ObjectService (no bespoke SQL)

## 2. MDTO/TMLO XML builder

- [ ] Map procest case fields → MDTO XML elements per MDTO 1.1 spec
- [ ] Handle multi-language fields (Dutch required, English optional)
- [ ] Include per-document metadata (type, author, creation date, restrictions) and preserve digital-signature metadata
- [ ] Map document-type classification from procest documentType references

## 3. XSD validation + SipBundel

- [ ] Implement `validateXsd(xmlContent, xsdSchema)` against official MDTO 1.1 / TMLO 1.2.1 XSD; return {valid, errors[]}
- [ ] Implement `createSipBundel(caseId, metadataXml, documents[])` persisting status `prepared`

## 4. Document-type gate

- [ ] Implement `SchemaValidator.validateDocumentTypes(caseId)` returning {valid, missingDocuments[]}
- [ ] In bundler: validate document-types before XML generation
- [ ] On failure: block SipBundel creation, log `bundling-failed`, raise DIV task with filename + corrective action

## 5. Tests

- [ ] Test: bundle a case; validate XML against MDTO XSD; verify required fields present
- [ ] Test: missing document-type blocks bundling with the correct error message
- [ ] Test: validate both MDTO 1.1 and TMLO 1.2.1 paths (configurable per rule)
