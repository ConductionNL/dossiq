# Retrofit — automatic-actions

Describes observed behavior of 10 PHP files (~33 methods) implementing the per-handler action framework as 5 new REQs. The existing `automatic-actions` spec defines the abstract framework + per-handler behavior but is not pinned to the implementation classes.

## Affected code units
- lib/Service/Actions/ActionHandlerInterface.php — handler contract (type + handle)
- lib/Service/Actions/ActionResult.php — handler result value object
- lib/Service/Actions/HandlesTemplates.php — shared template-rendering trait
- lib/Service/Actions/ActionRegistry.php (7 methods) — handler lookup + listing
- lib/Service/Actions/CallWebhookHandler.php (5 methods) — webhook delivery
- lib/Service/Actions/CreateDocumentHandler.php (4 methods) — case-attached document creation
- lib/Service/Actions/MergeTemplateHandler.php (4 methods) — template rendering action
- lib/Service/Actions/NotifyRoleHandler.php (5 methods) — role-based notification
- lib/Service/Actions/ScheduleReminderHandler.php (4 methods) — deferred reminder via BackgroundJob
- lib/Service/Actions/SendEmailHandler.php (4 methods) — email via NotificatieService (canonical; the `Transitions/SendEmailHandler.php` duplicate is a separate transitions-engine concern)

## Approach
- File-level survey by class name + public method shape
- Group handlers by family: notification surface (webhook/email/notify), content surface (document/template), schedule surface (reminder); plus the contract types and the registry
- Note: `lib/Service/Actions/SendEmailHandler.php` is the canonical sendEmail handler for the automatic-actions framework; `lib/Service/Transitions/SendEmailHandler.php` is a parallel transitions-engine action and belongs to the status-transition-engine spec, not here.

Source: openspec/coverage-report.md generated 2026-05-24. Tracks ConductionNL/procest#565.
