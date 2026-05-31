# Retrofit — berichtenbox-integration

Describes observed behavior of 5 PHP files (~17 methods) — Berichtenbox HTTP controller, service, pluggable adapter interface + mock, and daily polling background job — as 5 new REQs.

## Affected code units

- lib/Controller/BerichtenboxController.php (4 methods) — REST endpoints send / messages / poll
- lib/Service/BerichtenboxService.php (8 methods) — send orchestration, BSN 11-proef validation, message storage in OpenRegister, read-status polling, adapter resolution
- lib/Service/BerichtenboxAdapter/BerichtenboxAdapterInterface.php — adapter contract (sendMessage, getReadStatus)
- lib/Service/BerichtenboxAdapter/MockAdapter.php (3 methods) — MVP mock adapter
- lib/BackgroundJob/BerichtenboxReadStatusJob.php (2 methods) — daily TimedJob (86400s) scaffold

## Approach

- For each method: describe observed inputs, outputs, pre/postconditions, failure modes
- Draft REQs that match behavior (not aspirational)
- Notes section surfaces observed-but-suspicious behavior (e.g. MVP-hardcoded MockAdapter, placeholder attachment read, scaffold-only background job body)

Source: openspec/coverage-report.md generated 2026-05-24. Tracks ConductionNL/procest#565.
