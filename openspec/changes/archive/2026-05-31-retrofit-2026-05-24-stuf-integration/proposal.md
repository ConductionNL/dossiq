# Retrofit — stuf-integration

Describes observed behavior of 3 PHP files (~32 methods) — StUF SOAP controller, ZKN/BG field mapping service, and SOAP/StUF message builder — as 5 new REQs.

## Affected code units

- lib/Controller/StufController.php (11 methods) — `/api/stuf/{service}` raw-XML SOAP endpoint with per-message-type dispatch
- lib/Service/StufFieldMappingService.php (14 methods) — bidirectional StUF↔OpenRegister field mapping, date/enum transforms, custom-mapping override
- lib/Service/StufMessageBuilder.php (7 methods) — SOAP envelope construction with proper namespaces, stuurgegevens, noValue attribute handling

## Approach

- For each method: describe observed inputs, outputs, pre/postconditions, failure modes
- Draft REQs that match behavior (not aspirational)
- Notes flag observed stubs ("In a full implementation, create/update OpenRegister objects here" — handlers return Bv01 confirmations without persisting)

Source: openspec/coverage-report.md generated 2026-05-24. Tracks ConductionNL/procest#565.
