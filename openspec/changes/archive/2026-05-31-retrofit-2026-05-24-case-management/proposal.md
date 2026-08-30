# Retrofit — case-management (sharing + transfer + email surface)

Describes observed behavior of 7 PHP files (~48 methods) implementing case-sharing, case-transfer, case-email, and public-share access as 5 new REQs in the case-management capability.

## Affected code units
- lib/Controller/CaseSharingController.php (5 methods) — share + transfer endpoints
- lib/Service/CaseSharingService.php (12 methods) — token + partner share creation/revocation
- lib/Service/CaseTransferService.php (5 methods) — case transfer/reassign
- lib/Service/CaseEmailService.php (13 methods) — case-email rendering + dispatch
- lib/Controller/EmailController.php (5 methods) — case-email API
- lib/Controller/PublicShareController.php (6 methods) — public-token case access
- lib/BackgroundJob/ShareMaintenanceJob.php (2 methods) — expire/cleanup of stale shares

## Approach
- File-level survey by class signature + public method shape
- Group 7 files into 5 logical REQs (sharing controller + service, transfer service, email surface, public-token access, share-lifecycle maintenance)

Source: openspec/coverage-report.md generated 2026-05-24. Tracks ConductionNL/procest#565.
