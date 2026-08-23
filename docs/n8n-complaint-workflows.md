# n8n workflows — complaint-management

> Companion to `openspec/changes/complaint-management/specs/complaint-management/spec.md`.
> All three workflows are checked in under `n8n/`. They are imported into the
> n8n instance shipped with the docker-compose dev environment.

The complaint feature uses **three** n8n workflows. Two run on a schedule
(intake polling, daily deadline scan); one is webhook-triggered (incoming-email
attachment matcher).

All workflows authenticate to dossiq's REST API using HTTP Basic auth
(credentials are stored as an n8n credential of type "HTTP Basic Auth", referenced
as `genericAuthType: httpBasicAuth`). The HTTP request nodes assume a service
account with the `dossiq-system` group. `OCS-APIRequest: true` is sent on every
call so the Nextcloud framework does not redirect to the login page.

## 1. `complaint-email-intake.json`

- **Trigger.** `n8n-nodes-base.scheduleTrigger`, every 5 minutes.
- **Steps.**
  1. `GET {{DOSSIQ_BASE_URL}}/index.php/apps/dossiq/api/integration/mail/poll` — returns `{messages: [...]}` from the configured klachten@ inbox adapter.
  2. Code node classifies each message as either NEW (no `KLA-YYYY-NNNN` in subject) or FOLLOW-UP (subject matches the pattern).
  3. NEW branch: `POST /api/complaints` with `ontvangstkanaal: "email"` and the parsed sender / body.
  4. FOLLOW-UP branch: `POST /api/complaints/{klachtNummer}/attachments`.
- **Idempotency.** Each new POST carries `externalMessageId` (the SMTP Message-ID); the controller deduplicates on this field.

## 2. `complaint-deadline-monitor.json`

- **Trigger.** `n8n-nodes-base.scheduleTrigger`, every 24h (configured for 06:00).
- **Endpoint contract.** `GET /api/complaints/deadline-alerts?warningDays=5` ⇒
  `{warning: [...], overdue: [...]}` (see `ComplaintController::deadlineAlerts`).
- **Fan-out rules.**
  - Each `warning` entry triggers a `complaint-deadline-warning` notification to the assigned handler (T-5 working days, Awb 9:11).
  - Each `overdue` entry triggers a `complaint-deadline-overdue` notification to the coordinator (legal breach).
- **Recipient resolution.** The code node falls back to the coordinator if the assigned handler is empty.
- **Outgoing.** `POST /api/notifications/send` with `{recipient, template, priority, context}`.

## 3. `complaint-attachment-matcher.json`

- **Trigger.** Webhook at `POST /webhook/dossiq/complaint-attachment-incoming`.
- **Expected payload.** `{from, subject, body, messageId, attachments: [...]}`
  (delivered by the mail adapter when a message has attachments).
- **Match strategy** (in order):
  1. `KLA-YYYY-NNNN` regex in subject → POST to that complaint.
  2. Sender email matches `klager.email` on an OPEN complaint
     (`status in [ontvangen, in_behandeling]`) AND the search returns exactly
     one result → POST to that complaint.
  3. Otherwise: `POST /api/complaints/intake-review` with
     `reason: "attachment-could-not-be-matched"` and `candidateCount` so a
     handler picks it up.
- **Audit.** The dossiq `/attachments` endpoint records `source: email-followup`
  and the source `messageId` on the complaint's activity timeline.

## Configuration

The workflows read two environment variables on the n8n side:

| Variable | Purpose | Default |
| --- | --- | --- |
| `DOSSIQ_BASE_URL` | Base URL of the dossiq Nextcloud instance | `http://nextcloud` |
| `DOSSIQ_FROM_EMAIL` | From-address for outbound mail | `consultations@gemeente.nl` |

The HTTP Basic credential MUST grant the configured service account permission to:

- Read & write under `/index.php/apps/dossiq/api/complaints/*`.
- POST to `/index.php/apps/dossiq/api/notifications/send`.

## Verifying the workflows locally

After importing the JSON files into n8n:

1. **Intake.** Drop an `.eml` file into the local mail adapter test fixture; wait 5 minutes; verify a complaint with `ontvangstkanaal=email` appears in `cases/klachten`.
2. **Deadline monitor.** Manually trigger the workflow; with the complaint-management seed data, the response includes 2 warning and 1 overdue complaint; observe 3 notification fan-outs in the execution log.
3. **Attachment matcher.** Curl the webhook with a payload containing `subject: "Aanvullende stukken bij KLA-2026-0001"` and a fake attachment list; assert the complaint's activity timeline shows the new attachment with `source: email-followup`.
