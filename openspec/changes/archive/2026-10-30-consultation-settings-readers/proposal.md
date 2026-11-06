# Proposal: consultation-settings-readers

## Summary

Adviseringsbeheer (admin, Systeem tab, board DqBeheerinstellingen) saves four settings that nothing read (ConfigKeys said so). Settings that do nothing must not ship (coordinator, 24 Oct). Each one now has a reader:

- **Default term** (`consultation_default_deadline_days`, 28): an advice request created without a deadline gets today plus that many days.
- **Warning offset** (`consultation_warning_offset_days`, 5): `GET /api/consultations/due-soon` lists the open advice requests whose deadline falls within that many days. The n8n deadline monitor reads it instead of its hard-coded T+5.
- **External response address** (`consultation_external_response_url`, https): when set, the invitation to an external advisory body answers with that address plus the link's token (`responseUrl`), and with a ready message naming the subject, the deadline and that address.
- **Bottleneck threshold** (`consultation_bottleneck_threshold`, 0.2): `GET /api/consultations/bottlenecks` gives, per advisory body over the last 30 days, the requests, the late ones, the late share and whether it passes the threshold. The n8n bottleneck workflow reads it, replacing a hard-coded 20% and an analytics endpoint that never existed.

What reads advice requests today: dossiq only (ConsultationService, ConsultationRepository, the external link service, the n8n consultation workflows). decidiq receives an advice Decision per request (AdviceDelegationService) and reads none of these settings.

## Access (coordinator, 24 Oct)

- The due-soon and overdue lists keep only requests on cases the caller may read (CaseAccessGuard, the case pages' check), before the list is built. The background service account sees them all: it is the documented system path the n8n workflows call as (docs/n8n-consultation-workflows.md), checked as the configured account in its group, not widened to users.
- The bottleneck report answers administrators, the existing `dossiq-coordinators` group (CaseRebindGate::COORDINATOR_GROUP) and the service account.

## Scope

- New `lib/Service/Consultation/ConsultationTerms.php`; ConsultationService, ConsultationRepository, ExternalConsultationLinkService, ConsultationController, routes; the two n8n workflows; ConfigKeys comment; tests. No schema change.
