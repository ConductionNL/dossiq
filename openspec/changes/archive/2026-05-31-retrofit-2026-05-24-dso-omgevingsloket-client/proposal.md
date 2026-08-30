# Retrofit — dso-omgevingsloket-client

Describes observed behavior of 1 PHP file (~3 methods) — procest's DSO intake adapter — as 3 new REQs. **Named `dso-omgevingsloket-client` (not `dso-omgevingsloket`) to avoid overlap with openconnector's full DSO protocol spec** — procest only owns intake (DSO vergunningaanvraag → procest zaak) here; the DSO-LV koppelvlak / mTLS / status pushback live in openconnector. There is also an in-flight design change at `openspec/changes/dso-omgevingsloket/` (also unarchived) that captures the broader scope.

## Affected code units

- lib/Service/DsoIntakeService.php (3 methods) — `processAanvraag(dsoMessage)`, `getDeadlineDuration(procedureType)`, constructor

Source: openspec/coverage-report.md generated 2026-05-24. Tracks ConductionNL/procest#565.
