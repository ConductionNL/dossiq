# Spec delta: archief-edepot-handover-03-metadata-bundling

## ADDED Requirements

### Requirement: Bundler produces XSD-valid MDTO/TMLO metadata
The system MUST generate a metadata bundle conforming to MDTO 1.1 (or TMLO 1.2.1, configurable per e-Depot) with all required and present-optional fields, validate it against the official XSD, and persist a `SipBundel`.

#### Scenario: Complete bundle validates against XSD
- **GIVEN** a ready-for-transfer case with titel, omschrijving, behandelaar, betrokken organisaties and 7 documents that all have a document-type
- **WHEN** the metadata bundler runs against an e-Depot configured for MDTO
- **THEN** a `SipBundel` is created with MDTO 1.1 XML containing identificatie, aggregatieniveau, naam, classificatie, dekkingInTijd, beperkingGebruik, bewaartermijn and eventGeschiedenis
- **AND** the XML validates against the official MDTO XSD
- **AND** `SipBundel.metadataXsdValid` = true
- **AND** an `OverdrachtAuditLog` entry records "MDTO bundeling voltooid; XSD validatie geslaagd"

#### Scenario: Per-document metadata included
- **GIVEN** a case with documents of types Beschikking, Situatietekening and Milieueffectrapport
- **WHEN** the bundler generates MDTO XML
- **THEN** each document's type code, author (if available), creation date, access restrictions and digital-signature metadata (if signed) are included in the XML

### Requirement: Missing document-type blocks bundling
The system MUST block bundling when any document lacks a document-type, create no partial `SipBundel`, and raise an actionable DIV task.

#### Scenario: One untyped document blocks the bundle
- **GIVEN** a case with 7 documents where one lacks a document-type
- **WHEN** the bundler runs
- **THEN** bundling is blocked and no `SipBundel` is created
- **AND** an `OverdrachtAuditLog` `bundling-failed` event is recorded
- **AND** a DIV task is raised: "Zaak [id] kan niet worden overgedragen; document '[filename]' heeft geen documenttype"
- **AND** the case remains `gereed-voor-overdracht` so bundling can be retried after correction
