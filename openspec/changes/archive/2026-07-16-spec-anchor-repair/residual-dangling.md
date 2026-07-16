# Residual dangling @spec anchors — procest

2300 anchors could not be repointed unambiguously and need human triage.


## D. no-fragment tasks.md ref, slug is not a canonical capability (1081)
- `lib/BackgroundJob/AppointmentReminderJob.php` → `openspec/changes/retrofit-2026-05-24-case-management/tasks.md`
- `lib/BackgroundJob/DailyTermijnScanJob.php` → `openspec/changes/termijnbewaking-dwangsom-engine-04-daily-scan-escalation/tasks.md`
- `lib/BackgroundJob/DailyTermijnScanJob.php` → `openspec/changes/termijnbewaking-dwangsom-engine-04-daily-scan-escalation/tasks.md`
- `lib/BackgroundJob/ShareMaintenanceJob.php` → `openspec/changes/retrofit-2026-05-24-case-management/tasks.md`
- `lib/BackgroundJob/AdviceDeadlineJob.php` → `openspec/changes/retrofit-2026-05-24-case-management/tasks.md`
- `lib/BackgroundJob/ResetMonthlyQuotasJob.php` → `openspec/changes/tenant-zaaksysteem-saas-09-quotas-enforcement/tasks.md`
- `lib/BackgroundJob/TermijnNotificationDispatchJob.php` → `openspec/changes/termijnbewaking-dwangsom-engine-08-burger-notifications/tasks.md`
- `lib/Controller/MandaatMatrixController.php` → `openspec/changes/mandaat-matrix-09-tests-and-docs/tasks.md`
- `lib/Controller/MandaatMatrixController.php` → `openspec/changes/mandaat-matrix-02-authorization-engine/tasks.md`
- `lib/Controller/MandaatMatrixController.php` → `openspec/changes/mandaat-matrix-04-decidesk-import/tasks.md`
- `lib/Controller/MandaatMatrixController.php` → `openspec/changes/mandaat-matrix-04-decidesk-import/tasks.md`
- `lib/Controller/MandaatMatrixController.php` → `openspec/changes/mandaat-matrix-03-escalation-engine/tasks.md`
- `lib/Controller/MandaatMatrixController.php` → `openspec/changes/mandaat-matrix-03-escalation-engine/tasks.md`
- `lib/Controller/MandaatMatrixController.php` → `openspec/changes/mandaat-matrix-05-case-decision-integration/tasks.md`
- `lib/Controller/MandaatMatrixController.php` → `openspec/changes/mandaat-matrix-08-user-ui/tasks.md`
- … +1066 more

## D. non-tasks.md ref (decimal task / design.md / proposal.md / specs anchor re-headed) (1060)
- `lib/BackgroundJob/InboundEmailJob.php` → `openspec/changes/case-email-integration/tasks.md#T08`
- `lib/BackgroundJob/InboundEmailJob.php` → `openspec/changes/case-email-integration/tasks.md#T08`
- `lib/BackgroundJob/InboundEmailJob.php` → `openspec/changes/case-email-integration/tasks.md#T08`
- `lib/BackgroundJob/EmailPdfRetryJob.php` → `openspec/changes/case-email-integration/tasks.md#T09`
- `lib/BackgroundJob/EmailPdfRetryJob.php` → `openspec/changes/case-email-integration/tasks.md#T09`
- `lib/BackgroundJob/DsoDeadlineJob.php` → `openspec/changes/dso-omgevingsloket/tasks.md#T06`
- `lib/BackgroundJob/DsoDeadlineJob.php` → `openspec/changes/dso-omgevingsloket/tasks.md#T06`
- `lib/BackgroundJob/DsoDeadlineJob.php` → `openspec/changes/dso-omgevingsloket/tasks.md#T06`
- `lib/BackgroundJob/DsoDeadlineJob.php` → `openspec/changes/dso-omgevingsloket/tasks.md#T06`
- `lib/BackgroundJob/SentimentAnalysisJob.php` → `openspec/changes/kcc-werkplek-zaaksysteem-bridge/tasks.md#T15`
- `lib/BackgroundJob/SentimentAnalysisJob.php` → `openspec/changes/kcc-werkplek-zaaksysteem-bridge/tasks.md#T15`
- `lib/BackgroundJob/BezwaarTermijnJob.php` → `openspec/changes/beschikking-generatie/tasks.md#T12`
- `lib/BackgroundJob/BezwaarTermijnJob.php` → `openspec/changes/beschikking-generatie/tasks.md#T12`
- `lib/BackgroundJob/SpecialistBeschikbaarheidRefreshJob.php` → `openspec/changes/kcc-werkplek-zaaksysteem-bridge/tasks.md#T16`
- `lib/BackgroundJob/SpecialistBeschikbaarheidRefreshJob.php` → `openspec/changes/kcc-werkplek-zaaksysteem-bridge/tasks.md#T16`
- … +1045 more

## D. archived change dir not located (110)
- `lib/BackgroundJob/WOODeadlineCheckJob.php` → `openspec/changes/woo-case-type/tasks.md#task-4`
- `lib/BackgroundJob/WOODeadlineCheckJob.php` → `openspec/changes/woo-case-type/tasks.md#task-4`
- `lib/BackgroundJob/WOODeadlineCheckJob.php` → `openspec/changes/woo-case-type/tasks.md#task-4`
- `lib/Controller/WOOAssessmentController.php` → `openspec/changes/woo-case-type/tasks.md#task-5`
- `lib/Controller/WOOAssessmentController.php` → `openspec/changes/woo-case-type/tasks.md#task-7`
- `lib/Controller/WOOAssessmentController.php` → `openspec/changes/woo-case-type/tasks.md#task-5`
- `lib/Controller/WOOAssessmentController.php` → `openspec/changes/woo-case-type/tasks.md#task-7`
- `lib/Controller/WOOAssessmentController.php` → `openspec/changes/woo-case-type/tasks.md#task-5`
- `lib/Controller/WOOAssessmentController.php` → `openspec/changes/woo-case-type/tasks.md#task-4`
- `lib/Controller/WOOAssessmentController.php` → `openspec/changes/woo-case-type/tasks.md#task-7`
- `lib/Controller/VTHTemplateController.php` → `openspec/changes/vth-module/tasks.md#task-2`
- `lib/Controller/VTHTemplateController.php` → `openspec/changes/vth-module/tasks.md#task-2`
- `lib/Controller/VTHTemplateController.php` → `openspec/changes/vth-module/tasks.md#task-2`
- `lib/Controller/VTHTemplateController.php` → `openspec/changes/vth-module/tasks.md#task-2`
- `lib/Controller/VTHTemplateController.php` → `openspec/changes/vth-module/tasks.md#task-2`
- … +95 more

## D. change uses non-annotate tasks.md (no task-N: cap#REQ line) — needs spec delta read (49)
- `lib/Cron/OriDataQualityCheck.php` → `openspec/changes/open-raadsinformatie/tasks.md#task-8`
- `lib/Cron/OriDataQualityCheck.php` → `openspec/changes/open-raadsinformatie/tasks.md#task-8`
- `lib/Cron/OriDataQualityCheck.php` → `openspec/changes/open-raadsinformatie/tasks.md#task-8`
- `lib/BackgroundJob/VergaderingDeadlineJob.php` → `openspec/changes/open-raadsinformatie/tasks.md#task-5`
- `lib/BackgroundJob/VergaderingDeadlineJob.php` → `openspec/changes/open-raadsinformatie/tasks.md#task-5`
- `lib/BackgroundJob/VergaderingDeadlineJob.php` → `openspec/changes/open-raadsinformatie/tasks.md#task-5`
- `lib/Controller/ZrcController.php` → `openspec/changes/retrofit-2026-05-24-annotate-procest/tasks.md#task-1`
- `lib/Controller/BrcController.php` → `openspec/changes/retrofit-2026-05-24-annotate-procest/tasks.md#task-1`
- `lib/Controller/RaadsinformatieFeedController.php` → `openspec/changes/open-raadsinformatie/tasks.md#task-7`
- `lib/Controller/RaadsinformatieFeedController.php` → `openspec/changes/open-raadsinformatie/tasks.md#task-7`
- `lib/Controller/RaadsinformatieFeedController.php` → `openspec/changes/open-raadsinformatie/tasks.md#task-7`
- `lib/Controller/RaadsinformatieFeedController.php` → `openspec/changes/open-raadsinformatie/tasks.md#task-7`
- `lib/Controller/RaadsinformatieFeedController.php` → `openspec/changes/open-raadsinformatie/tasks.md#task-7`
- `lib/Controller/ZtcController.php` → `openspec/changes/retrofit-2026-05-24-annotate-procest/tasks.md#task-1`
- `lib/Controller/ZgwMappingController.php` → `openspec/changes/retrofit-2026-05-24-annotate-procest/tasks.md#task-1`
- … +34 more
