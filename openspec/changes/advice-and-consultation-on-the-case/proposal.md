# Advice and consultation on the case

## Why

Row 3.35 (request advice and consultations on a case and track the responses and deadlines). The backend stores requests, answers and deadlines (`AdviceService`, routes `/api/vth/cases/{id}/advice-requests`, `/api/advice/{id}/transition`, `/remind`; `ConsultationService`, routes `/api/consultations...`), and the queue lists consultations waiting on you. The ticked changes `advice-requested-notice` and `consultation-settings-readers` covered the notice and the settings. What is missing: a place on the case to ask for advice or start a consultation and to read the answers. `AdviceDetail` (`/advice/:id`) is read-only and the case page has no advice or consultation widget.

## What changes

- Two header actions on the case: "Advies vragen" and "Consultatie starten", each a dialog naming the adviser or consulted body, the question and the deadline.
- A widget "Adviezen en consultaties" on the case listing each request with its state, deadline and answer, with remind and withdraw as row actions.
- The advice route moves off the `/api/vth/` path to `/api/cases/{id}/advice-requests` (generic, decision 182); the old path forwards for a release.

## Impact

- `src/manifest.json` CaseDetail: headerActions `advice-request`, `consultation-start`; widget `advice-and-consultations`.
- `src/dialogs/AdviceRequestDialog.vue`, `src/dialogs/ConsultationStartDialog.vue`.
- `appinfo/routes.php`: generic advice route plus forward.
