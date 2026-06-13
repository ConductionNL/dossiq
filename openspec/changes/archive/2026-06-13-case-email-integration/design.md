# Design: case-email-integration

## Architecture Overview

Email correspondence is surfaced on the `case` detail page by **consuming the OpenRegister `email` integration leaf** (NC Mail; id `email`, group `comms`, storage `link-table`). Per ADR-022, procest does NOT build a parallel mail client: display, compose, link, and unlink are the leaf's responsibility. Procest adds only the case-specific pieces the leaf cannot do — per-zaaktype templating, PDF/`caseDocument` archival, and unattended shared-mailbox ingest (a documented ADR-022 exception).

```
case detail page (CaseDetail.vue)
└── OR integration registry (ADR-019) / app manifest (ADR-024)
    ├── email leaf sidebar tab        ← NC Mail messages linked to this case (leaf-owned)
    └── CnEmailCard widget            ← leaf-owned, surface='single-entity'/'detail'
        (link/unlink/compose handled by the leaf + NC Mail; NOT procest)

procest extensions (leaf cannot do these):
├── EmailTemplateService              → per-zaaktype templates, version-on-edit, Dutch defaults
│     prefill → opens an NC Mail draft (compose stays in NC Mail)
├── EmailArchivalService              → on email-linked: Docudesk PDF → caseDocument (Archiefwet/ZGW)
│     └── EmailPdfRetryJob (TimedJob) → retry pdfStatus:failed 3× backoff
└── InboundEmailJob (TimedJob)        → ADR-022 EXCEPTION: shared/functional mailbox ingest
      ├── auto-link by [ZAAK-YYYY-NNNNNN] subject regex
      └── records link via leaf endpoint POST /api/objects/{register}/{schema}/{id}/email
            (NO procest emailMessage/emailThread store)
```

## How the leaf is consumed

The `email` leaf (see `openregister/openspec/changes/integration-email`) ships:

- `EmailProvider` (DI-tagged `IntegrationProvider`, id `email`, `requiredApp: mail`, `storageStrategy: link-table`) — present only when NC Mail is installed.
- A **sidebar tab** listing linked emails by date descending with a "Link existing email" picker. The tab does NOT compose/send.
- `CnEmailCard` widget across surfaces; `referenceType: 'email'` auto-renders it inline.
- Link endpoint: `POST /api/objects/{register}/{schema}/{id}/email` with `{mailAccountId, mailMessageId}`; cached subject/sender/date populated at link time. Unlink deletes the link record only (the Mail message is untouched).
- `requiresPermission()` returns `null` — access inherits from object RBAC + per-user NC Mail access.

Procest's job is to **register the `case` schema as a host surface** for the leaf (app manifest, ADR-024) so the tab + widget appear on the case detail page, and to consume the link endpoint from its template-prefill and shared-mailbox flows.

## File Map

### New Backend Files

| File | Purpose |
|------|---------|
| `lib/Service/EmailTemplateService.php` | Per-zaaktype template CRUD, versioning on edit, variable catalog, Dutch defaults, NC Mail draft prefill |
| `lib/Service/EmailArchivalService.php` | On email-linked: Docudesk PDF → `caseDocument`; tracks `pdfStatus`; ZGW informatieobject mapping |
| `lib/Controller/EmailTemplateController.php` | Authenticated API: template CRUD + draft-prefill helper |
| `lib/BackgroundJob/InboundEmailJob.php` | **ADR-022 exception** — TimedJob: shared-mailbox IMAP poll, subject-regex auto-link, records link via leaf endpoint |
| `lib/BackgroundJob/EmailPdfRetryJob.php` | TimedJob: retry failed Docudesk conversions up to 3× with exponential backoff |
| `lib/Settings/EmailSettings.php` | Admin settings section: shared-mailbox IMAP + transport choice |

### New Frontend Files

| File | Purpose |
|------|---------|
| `src/views/casetypes/components/EmailTemplateAdmin.vue` | Per-zaaktype template CRUD with variable sidebar + live preview |
| `src/views/settings/EmailSettings.vue` | Shared-mailbox IMAP config + transport choice + test-connection |

> **Not created** (consumed from the `email` leaf instead): `EmailComposer.vue`, `EmailThread.vue`, `EmailTab.vue`, `UnlinkedQueue.vue`. Compose lives in NC Mail; display + link + unlink come from the leaf tab + `CnEmailCard`.

### Modified Files

| File | Changes |
|------|---------|
| `appinfo/info.xml` / app manifest | Register the `email` leaf tab + `CnEmailCard` widget on the `case` detail surface (ADR-024 manifest entry) |
| `lib/Settings/procest_register.json` | Add ONLY the `emailTemplate` schema + seed templates. Add `referenceType: 'email'` to any `case` property that points at a primary correspondence message so `CnEmailCard` auto-renders |
| `lib/Service/SettingsService.php` | Add `email_template_schema` + shared-mailbox IMAP config keys |
| `appinfo/routes.php` | Add template CRUD + shared-mailbox settings routes before SPA catch-all |
| `src/views/cases/CaseDetail.vue` | Ensure the case detail page mounts the leaf tab + widget; "Verstuur email" action opens an NC Mail draft (prefilled from a template) rather than a bespoke composer |

## Data Model

### emailTemplate Schema (the ONLY new schema)

**Schema.org type:** `schema:DigitalDocument`

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| `name` | string | Yes | Template name (e.g. `Ontvangstbevestiging`) |
| `subject` | string | Yes | Subject pattern with `{{variable}}` placeholders |
| `body` | string (HTML) | Yes | Body content with `{{variable}}` placeholders |
| `caseType` | string | Yes | OpenRegister reference to `caseType` object |
| `variables` | array | No | Available variable names scanned from subject + body |
| `version` | integer | No | Auto-incremented on edit (starts at 1) |
| `isActive` | boolean | No | Whether template is selectable (default: true) |

> **No `emailMessage` / `emailThread` schema.** Linked emails are held in the `email` leaf's link-table (cached subject/sender/date + `mailAccountId`/`mailMessageId`). Querying a case's emails goes through the leaf, not a procest schema. This is the ADR-022 "no parallel link table / no parallel data model" rule.

## API Design

### Authenticated Endpoints (EmailTemplateController)

| Method | Path | Description |
|--------|------|-------------|
| `GET` | `/api/casetypes/{caseTypeId}/email-templates` | List templates for case type |
| `POST` | `/api/casetypes/{caseTypeId}/email-templates` | Create template |
| `PUT` | `/api/email-templates/{templateId}` | Update template (creates new version object) |
| `POST` | `/api/cases/{caseId}/email-templates/{templateId}/draft` | Resolve template variables and open an NC Mail draft (prefill only — send happens in NC Mail) |
| `GET` | `/api/settings/email` | Shared-mailbox IMAP + transport config (passwords masked) |
| `PUT` | `/api/settings/email` | Save shared-mailbox IMAP + transport config |
| `POST` | `/api/settings/email/test-imap` | Test the shared-mailbox IMAP connection |

> **Removed vs prior draft:** `POST/GET /api/cases/{caseId}/emails`, `/api/emails/unlinked`, `.../link`, `.../discard`, `.../test-smtp`. Send/list/link/unlink are the leaf's `POST /api/objects/{register}/{schema}/{id}/email` + NC Mail. No SMTP send endpoint.

### Leaf endpoints consumed (not authored here)

| Method | Path | Used by |
|--------|------|---------|
| `POST` | `/api/objects/{register}/{schema}/{id}/email` | `InboundEmailJob` (auto-link) + template-draft flow (link the sent draft) |
| `DELETE` | leaf unlink | NC Mail / leaf tab (user action) |

## Security & Reliability

- Shared-mailbox IMAP password stored via `IAppConfig` with `setSensitive(true)` — never appears in logs or audit trails. Per-user mail credentials stay in NC Mail.
- Case-number regex anchored as `\[([A-Z]+-\d{4}-\d{6})\]`; matched against subject header only, never body.
- Tenant isolation: case lookup by identifier scoped to current organization via OR's `_multitenancy` filter.
- `InboundEmailJob` catches all exceptions to prevent Nextcloud job deregistration; logs via `LoggerInterface`.
- Duplicate detection: before linking, the job checks the leaf link-table for the `mailMessageId` already linked to the case.
- PDF archival runs async for messages > 5 MB; sync for smaller; failures retried by `EmailPdfRetryJob`.
- Email access RBAC is inherited from the leaf (`requiresPermission()` → null; per-user NC Mail access) and OR object RBAC on the `case` — no app-local email RBAC.

## Reuse Analysis (ADR-022 + ADR-012)

| Capability needed | Consumed from | NOT rebuilt |
|-------------------|---------------|-------------|
| Email display on a case (tab + widget) | `email` leaf (NC Mail) — sidebar tab + `CnEmailCard` | No `EmailTab`/`EmailThread` Vue |
| Compose / send an email | NC Mail (the leaf is link-only) | No `EmailComposer`, no SMTP transport |
| Linking an email to a case | leaf `POST .../email` link-table | No `emailMessage`/`emailThread` schema, no parallel link table |
| Manual "link existing email" / unlink | leaf tab affordance | No `UnlinkedQueue.vue`, no link/discard endpoints |
| Email access control | leaf `requiresPermission()` + OR object RBAC | No app-local email RBAC |
| Object CRUD for `emailTemplate` | OpenRegister `ObjectService` | No custom Mapper/Entity |
| PDF generation | Docudesk (existing in procest) | No custom renderer |
| Audit / activity | OR audit trail on the linked `case` object | No custom audit table |

New code is limited to: per-zaaktype templating + versioning, PDF→`caseDocument` archival, and the **shared-mailbox poller** (ADR-022 exception). All email display/compose/link comes from the leaf.

## Seed Data

Per ADR-001, `procest_register.json` MUST seed `emailTemplate` objects using the `@self` envelope. (No `emailMessage`/`emailThread` seeds — linked emails live in the leaf link-table, populated at runtime.)

### emailTemplate — 3 seed objects

```json
{
  "@self": {
    "register": "procest",
    "schema": "emailTemplate",
    "slug": "email-template-ontvangstbevestiging"
  },
  "name": "Ontvangstbevestiging",
  "subject": "[{{case.identifier}}] Ontvangstbevestiging — {{case.title}}",
  "body": "<p>Geachte {{contact.salutation}},</p><p>Hierbij bevestigen wij de ontvangst van uw aanvraag <strong>{{case.title}}</strong> (zaaknummer {{case.identifier}}).</p><p>Uw aanvraag is geregistreerd op {{case.startDate}}. De wettelijke beslistermijn bedraagt {{caseType.processingDeadline}}.</p><p>Met vriendelijke groet,<br>Gemeente Westerhaven</p>",
  "caseType": "ref:caseType:omgevingsvergunning-regulier",
  "variables": ["case.identifier", "case.title", "case.startDate", "contact.salutation", "caseType.processingDeadline"],
  "version": 1,
  "isActive": true
}
```

```json
{
  "@self": {
    "register": "procest",
    "schema": "emailTemplate",
    "slug": "email-template-informatieverzoek"
  },
  "name": "Informatieverzoek",
  "subject": "[{{case.identifier}}] Verzoek om aanvullende informatie — {{case.title}}",
  "body": "<p>Geachte {{contact.salutation}},</p><p>In het kader van uw aanvraag <strong>{{case.title}}</strong> ({{case.identifier}}) verzoeken wij u de volgende informatie aan te leveren vóór {{task.dueDate}}:</p><ul><li></li></ul><p>Zonder de gevraagde informatie kunnen wij uw aanvraag niet verder in behandeling nemen.</p><p>Met vriendelijke groet,<br>Gemeente Westerhaven</p>",
  "caseType": "ref:caseType:omgevingsvergunning-regulier",
  "variables": ["case.identifier", "case.title", "contact.salutation", "task.dueDate"],
  "version": 1,
  "isActive": true
}
```

```json
{
  "@self": {
    "register": "procest",
    "schema": "emailTemplate",
    "slug": "email-template-besluit"
  },
  "name": "Besluit",
  "subject": "[{{case.identifier}}] Besluit op uw aanvraag — {{case.title}}",
  "body": "<p>Geachte {{contact.salutation}},</p><p>Op uw aanvraag <strong>{{case.title}}</strong> ({{case.identifier}}) heeft het college van burgemeester en wethouders op {{decision.decisionDate}} een besluit genomen.</p><p>Het besluit luidt: <strong>{{decision.title}}</strong>.</p><p>Het volledige besluit treft u aan als bijlage bij dit bericht. Tegen dit besluit kunt u binnen zes weken bezwaar maken.</p><p>Met vriendelijke groet,<br>Gemeente Westerhaven</p>",
  "caseType": "ref:caseType:omgevingsvergunning-regulier",
  "variables": ["case.identifier", "case.title", "contact.salutation", "decision.decisionDate", "decision.title"],
  "version": 1,
  "isActive": true
}
```
