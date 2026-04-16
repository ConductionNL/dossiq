# Design: automatic-actions

## Architecture Overview

The automatic action engine hooks into the existing case status transition pipeline. When a status change is persisted, a Symfony event (`CaseStatusChangedEvent`) is dispatched. The `WorkflowActionExecutor` service listens for this event, resolves the active workflow template bound to the case, finds the matching transition definition, and fires each configured automatic action through a dedicated handler.

```
CaseService::updateStatus()
  └── dispatches CaseStatusChangedEvent
        └── WorkflowActionExecutor (EventListener)
              ├── resolves workflowTemplate + transition
              ├── interpolates template variables ({{case.fieldName}})
              └── dispatches each automaticAction to:
                    ├── SendEmailHandler      → Nextcloud Mailer
                    ├── CreateTaskHandler     → ObjectService (task schema)
                    ├── CreateSubCaseHandler  → ObjectService (case schema)
                    ├── WebhookHandler        → OpenRegister WebhookService
                    ├── SetFieldHandler       → ObjectService.saveObject()
                    └── NotifyHandler         → OpenRegister NotificationService
```

Step-entry actions use the same executor, triggered when a workflow step's status is first reached during case progression.

## File Map

### New Files

| File | Purpose |
|------|---------|
| `lib/Event/CaseStatusChangedEvent.php` | Symfony event carrying case ID, old status, new status, actor |
| `lib/EventListener/WorkflowActionExecutor.php` | Central event listener — resolves template, iterates actions, delegates to handlers |
| `lib/Service/WorkflowAction/ActionHandlerInterface.php` | Interface: `handle(array $actionConfig, array $caseContext): ActionResult` |
| `lib/Service/WorkflowAction/ActionResult.php` | Value object: `success`, `message`, `data` |
| `lib/Service/WorkflowAction/SendEmailHandler.php` | Sends templated email via Nextcloud Mailer; supports `to`, `subject`, `body` (template vars) |
| `lib/Service/WorkflowAction/CreateTaskHandler.php` | Creates a `task` object via ObjectService; supports `title`, `description`, `assigneeRole`, `dueDateOffsetDays` |
| `lib/Service/WorkflowAction/CreateSubCaseHandler.php` | Creates a child `case` object linked via `parentCase`; supports `caseType`, `title` (template), `assignee` |
| `lib/Service/WorkflowAction/WebhookHandler.php` | Delegates to OpenRegister `WebhookService`; sends CloudEvents-format POST to `url` with HMAC signature |
| `lib/Service/WorkflowAction/SetFieldHandler.php` | Calls `ObjectService.saveObject()` with patched field; supports `field` + `value` (template vars) |
| `lib/Service/WorkflowAction/NotifyHandler.php` | Delegates to OpenRegister `NotificationService`; supports `users`, `roles`, `message` (template vars) |
| `lib/Service/WorkflowTemplateVariableResolver.php` | Resolves `{{case.fieldName}}`, `{{case.identifier}}`, `{{case.assignee}}`, `{{now}}`, `{{now+Nd}}` in action config strings |

### Modified Files

| File | Changes |
|------|---------|
| `lib/Service/CaseService.php` | Dispatch `CaseStatusChangedEvent` after successful status update |
| `appinfo/info.xml` | Register `WorkflowActionExecutor` as event listener |

## Design Decisions

### DD-01: Event-driven over synchronous hook

**Decision**: Use a Symfony event (`CaseStatusChangedEvent`) rather than a direct method call from `CaseService`.

**Rationale**: Decouples action execution from the status update transaction. Handlers can be added or removed without touching `CaseService`. Follows Nextcloud/Symfony conventions. Future handlers (e.g., for scheduled actions) can subscribe to the same event bus.

### DD-02: Delegate to OpenRegister services — no custom HTTP or notification infrastructure

**Decision**: `WebhookHandler` delegates entirely to OpenRegister's `WebhookService`. `NotifyHandler` delegates to OpenRegister's `NotificationService`.

**Rationale**: OpenRegister's `WebhookService` already provides CloudEvents formatting, HMAC signing, retry with exponential backoff, and delivery logging. Building a custom HTTP client would duplicate this infrastructure and bypass the centralized webhook audit. ADR-001 mandates using platform capabilities over rebuilding them.

### DD-03: Template variable interpolation in a single resolver service

**Decision**: All handler configs are preprocessed by `WorkflowTemplateVariableResolver` before being passed to handlers.

**Rationale**: Each handler would otherwise need its own interpolation logic. A single resolver is testable in isolation and ensures consistent `{{var}}` syntax across all action types.

### DD-04: Audit via AuditTrailService — no new schema

**Decision**: Action execution results are appended to the case's audit trail via OpenRegister's `AuditTrailService`, not stored in a new schema.

**Rationale**: OpenRegister's built-in audit trail is already available on every object, is searchable, and inherits the case's retention policy. Adding a separate `actionExecutionLog` schema would duplicate this and require its own CRUD, retention management, and UI.

## Reuse Analysis

| Capability | OpenRegister Service | Notes |
|---|---|---|
| Webhook delivery + retry | `WebhookService` | CloudEvents format, HMAC signing, exponential backoff — reused directly |
| In-app notifications | `NotificationService` | Nextcloud native notifications — reused directly |
| Object creation (task, case) | `ObjectService.saveObject()` | Used by CreateTaskHandler and CreateSubCaseHandler |
| Field update | `ObjectService.saveObject()` | Used by SetFieldHandler with a patched field map |
| Audit logging | `AuditTrailService` | Action execution results recorded on case audit trail |
| Email dispatch | Nextcloud Mailer (built-in) | PHP Mailer via Nextcloud — no custom SMTP client needed |

No overlap found with existing Procest services. The `WorkflowEngineRegistry` in OpenRegister provides hook registration but not the Procest-domain action handlers defined here.

## API Endpoints

No new API endpoints. Action execution is triggered internally by case status changes. The existing case audit trail endpoint (`GET /api/cases/{id}/audit`) surfaces action execution results.

## Seed Data

The following seed workflowTemplate objects demonstrate each action type with realistic Dutch municipal values. These are for the `workflowTemplate` schema in `procest_register.json`.

### Seed Object 1 — Omgevingsvergunning Workflow

```json
{
  "@self": {
    "register": "procest",
    "schema": "workflowTemplate",
    "slug": "workflow-omgevingsvergunning-v1"
  },
  "title": "Omgevingsvergunning — Standaard Behandeling",
  "description": "Standaard workflow voor omgevingsvergunning aanvragen. Bevat automatische ontvangstbevestiging en taakgroep bij in behandeling nemen.",
  "version": 1,
  "isActive": true,
  "isDraft": false,
  "steps": "[{\"id\":\"a1b2c3d4-0001-0001-0001-000000000001\",\"title\":\"Ontvangst\",\"description\":\"Aanvraag ontvangen en geregistreerd\",\"order\":1,\"isRequired\":true,\"automaticActions\":[{\"type\":\"sendEmail\",\"config\":{\"to\":\"{{case.communicationChannel}}\",\"subject\":\"Ontvangstbevestiging aanvraag {{case.identifier}}\",\"body\":\"Geachte aanvrager, uw aanvraag {{case.identifier}} is ontvangen op {{now}}. Wij streven naar afhandeling binnen de gestelde termijn.\"}},{\"type\":\"notify\",\"config\":{\"roles\":[\"behandelaar\"],\"message\":\"Nieuwe omgevingsvergunning aanvraag {{case.identifier}} wacht op toewijzing.\"}}]},{\"id\":\"a1b2c3d4-0001-0001-0001-000000000002\",\"title\":\"In behandeling\",\"description\":\"Zaak wordt inhoudelijk beoordeeld\",\"order\":2,\"isRequired\":true,\"automaticActions\":[{\"type\":\"createTask\",\"config\":{\"title\":\"Controleer volledigheid aanvraag {{case.identifier}}\",\"description\":\"Controleer of alle vereiste documenten aanwezig zijn conform de checklist.\",\"assigneeRole\":\"behandelaar\",\"dueDateOffsetDays\":5}}]},{\"id\":\"a1b2c3d4-0001-0001-0001-000000000003\",\"title\":\"Besluit\",\"description\":\"Vergunning verleend of geweigerd\",\"order\":3,\"isRequired\":true,\"automaticActions\":[{\"type\":\"webhook\",\"config\":{\"url\":\"https://zaaksysteem.gemeente-voorbeeld.nl/api/webhooks/procest\",\"method\":\"POST\"}},{\"type\":\"setField\",\"config\":{\"field\":\"archiveNomination\",\"value\":\"bewaren\"}}]}]",
  "transitions": "[{\"id\":\"t1000001-0001-0001-0001-000000000001\",\"fromStatus\":\"ontvangen\",\"toStatus\":\"in-behandeling\",\"label\":\"In behandeling nemen\",\"guards\":[],\"automaticActions\":[{\"type\":\"setField\",\"config\":{\"field\":\"assignee\",\"value\":\"{{case.assignee}}\"}},{\"type\":\"notify\",\"config\":{\"users\":[\"{{case.assignee}}\"],\"message\":\"Zaak {{case.identifier}} is aan u toegewezen.\"}}],\"allowedRoles\":[]},{\"id\":\"t1000001-0001-0001-0001-000000000002\",\"fromStatus\":\"in-behandeling\",\"toStatus\":\"besluit\",\"label\":\"Besluit nemen\",\"guards\":[{\"type\":\"requiredDocument\",\"documentTypeSlug\":\"vergunningsbesluit\"}],\"automaticActions\":[{\"type\":\"sendEmail\",\"config\":{\"to\":\"{{case.communicationChannel}}\",\"subject\":\"Beslissing op uw aanvraag {{case.identifier}}\",\"body\":\"Geachte aanvrager, wij hebben een beslissing genomen op uw aanvraag. Zie bijgevoegd document voor de details.\"}}],\"allowedRoles\":[]}]"
}
```

### Seed Object 2 — Melding Openbare Ruimte Workflow

```json
{
  "@self": {
    "register": "procest",
    "schema": "workflowTemplate",
    "slug": "workflow-melding-openbare-ruimte-v1"
  },
  "title": "Melding Openbare Ruimte — Snelle Afhandeling",
  "description": "Gestroomlijnde workflow voor meldingen in de openbare ruimte (losliggende stoeptegels, kapotte straatverlichting e.d.). Maximaal 5 werkdagen doorlooptijd.",
  "version": 1,
  "isActive": true,
  "isDraft": false,
  "steps": "[{\"id\":\"a2b3c4d5-0002-0002-0002-000000000001\",\"title\":\"Melding ontvangen\",\"description\":\"Melding geregistreerd en doorgezet naar buitendienst\",\"order\":1,\"isRequired\":true,\"automaticActions\":[{\"type\":\"sendEmail\",\"config\":{\"to\":\"{{case.communicationChannel}}\",\"subject\":\"Uw melding {{case.identifier}} is ontvangen\",\"body\":\"Bedankt voor uw melding. Wij behandelen uw melding zo snel mogelijk.\"}},{\"type\":\"createTask\",\"config\":{\"title\":\"Inspecteer locatie voor melding {{case.identifier}}\",\"description\":\"Ga naar de locatie en beoordeel de ernst van de melding.\",\"assigneeRole\":\"buitendienst\",\"dueDateOffsetDays\":2}}]},{\"id\":\"a2b3c4d5-0002-0002-0002-000000000002\",\"title\":\"In uitvoering\",\"description\":\"Reparatie of actie wordt uitgevoerd\",\"order\":2,\"isRequired\":true,\"automaticActions\":[]},{\"id\":\"a2b3c4d5-0002-0002-0002-000000000003\",\"title\":\"Afgehandeld\",\"description\":\"Melding is opgelost en melder geïnformeerd\",\"order\":3,\"isRequired\":true,\"automaticActions\":[{\"type\":\"sendEmail\",\"config\":{\"to\":\"{{case.communicationChannel}}\",\"subject\":\"Uw melding {{case.identifier}} is afgehandeld\",\"body\":\"Geachte melder, de door u gemelde situatie is opgelost. Bedankt voor het melden.\"}},{\"type\":\"setField\",\"config\":{\"field\":\"endDate\",\"value\":\"{{now}}\"}}]}]",
  "transitions": "[{\"id\":\"t2000001-0002-0002-0002-000000000001\",\"fromStatus\":\"ontvangen\",\"toStatus\":\"in-uitvoering\",\"label\":\"In uitvoering nemen\",\"guards\":[],\"automaticActions\":[{\"type\":\"webhook\",\"config\":{\"url\":\"https://buitendienst.gemeente-voorbeeld.nl/api/meldingen\",\"method\":\"POST\"}}],\"allowedRoles\":[]},{\"id\":\"t2000001-0002-0002-0002-000000000002\",\"fromStatus\":\"in-uitvoering\",\"toStatus\":\"afgehandeld\",\"label\":\"Afsluiten\",\"guards\":[],\"automaticActions\":[],\"allowedRoles\":[]}]"
}
```

### Seed Object 3 — Bezwaar Workflow

```json
{
  "@self": {
    "register": "procest",
    "schema": "workflowTemplate",
    "slug": "workflow-bezwaar-v1"
  },
  "title": "Bezwaarprocedure — Standaard Awb",
  "description": "Workflow voor bezwaarzaken conform de Algemene wet bestuursrecht. Bevat automatische aanmaak van subzaak voor hoorzitting en notificaties aan commissieleden.",
  "version": 2,
  "isActive": true,
  "isDraft": false,
  "steps": "[{\"id\":\"a3b4c5d6-0003-0003-0003-000000000001\",\"title\":\"Bezwaar ontvangen\",\"description\":\"Bezwaarschrift ontvangen en geregistreerd\",\"order\":1,\"isRequired\":true,\"automaticActions\":[{\"type\":\"sendEmail\",\"config\":{\"to\":\"{{case.communicationChannel}}\",\"subject\":\"Ontvangstbevestiging bezwaar {{case.identifier}}\",\"body\":\"Uw bezwaarschrift is ontvangen op {{now}}. De termijn voor afhandeling is 6 weken, eventueel verlengbaar met 6 weken.\"}},{\"type\":\"createTask\",\"config\":{\"title\":\"Beoordeel ontvankelijkheid bezwaar {{case.identifier}}\",\"description\":\"Controleer tijdigheid (6-weken termijn Awb art. 6:7) en formele vereisten.\",\"assigneeRole\":\"behandelaar\",\"dueDateOffsetDays\":7}}]},{\"id\":\"a3b4c5d6-0003-0003-0003-000000000002\",\"title\":\"Hoorzitting\",\"description\":\"Hoorzitting plannen en uitvoeren\",\"order\":2,\"isRequired\":false,\"automaticActions\":[{\"type\":\"createSubCase\",\"config\":{\"caseType\":\"hoorzitting-organisatie\",\"title\":\"Hoorzitting voor bezwaar {{case.identifier}}\"}},{\"type\":\"notify\",\"config\":{\"roles\":[\"commissielid\",\"voorzitter\"],\"message\":\"Nieuwe hoorzitting gepland voor bezwaar {{case.identifier}}. Controleer de uitnodiging.\"}}]},{\"id\":\"a3b4c5d6-0003-0003-0003-000000000003\",\"title\":\"Beslissing op bezwaar\",\"description\":\"Formele beslissing genomen\",\"order\":3,\"isRequired\":true,\"automaticActions\":[{\"type\":\"sendEmail\",\"config\":{\"to\":\"{{case.communicationChannel}}\",\"subject\":\"Beslissing op uw bezwaar {{case.identifier}}\",\"body\":\"De beslissing op uw bezwaar is genomen. U ontvangt het besluit per post.\"}},{\"type\":\"setField\",\"config\":{\"field\":\"archiveNomination\",\"value\":\"bewaren\"}}]}]",
  "transitions": "[{\"id\":\"t3000001-0003-0003-0003-000000000001\",\"fromStatus\":\"ontvangen\",\"toStatus\":\"hoorzitting\",\"label\":\"Hoorzitting inplannen\",\"guards\":[{\"type\":\"requiredField\",\"field\":\"assignee\"}],\"automaticActions\":[],\"allowedRoles\":[]},{\"id\":\"t3000001-0003-0003-0003-000000000002\",\"fromStatus\":\"hoorzitting\",\"toStatus\":\"beslissing\",\"label\":\"Beslissing nemen\",\"guards\":[{\"type\":\"requiredDocument\",\"documentTypeSlug\":\"adviesrapport-commissie\"}],\"automaticActions\":[{\"type\":\"webhook\",\"config\":{\"url\":\"https://publicatieblad.gemeente-voorbeeld.nl/api/notifications\",\"method\":\"POST\"}}],\"allowedRoles\":[]}]"
}
```

### Seed Object 4 — Kapvergunning Workflow

```json
{
  "@self": {
    "register": "procest",
    "schema": "workflowTemplate",
    "slug": "workflow-kapvergunning-v1"
  },
  "title": "Kapvergunning — Eenvoudige Aanvraag",
  "description": "Compacte workflow voor kapvergunningaanvragen. Automatische doorstroom bij volledige aanvraag.",
  "version": 1,
  "isActive": true,
  "isDraft": false,
  "steps": "[{\"id\":\"a4b5c6d7-0004-0004-0004-000000000001\",\"title\":\"Aanvraag ontvangen\",\"order\":1,\"isRequired\":true,\"automaticActions\":[{\"type\":\"sendEmail\",\"config\":{\"to\":\"{{case.communicationChannel}}\",\"subject\":\"Kapvergunning aanvraag {{case.identifier}} ontvangen\",\"body\":\"Uw aanvraag voor een kapvergunning is geregistreerd. De behandeltermijn is 8 weken.\"}}]},{\"id\":\"a4b5c6d7-0004-0004-0004-000000000002\",\"title\":\"Beoordeling\",\"order\":2,\"isRequired\":true,\"automaticActions\":[{\"type\":\"createTask\",\"config\":{\"title\":\"Veldcontrole boom {{case.identifier}}\",\"description\":\"Controleer de staat van de te kappen boom ter plaatse.\",\"assigneeRole\":\"toezichthouder\",\"dueDateOffsetDays\":10}}]},{\"id\":\"a4b5c6d7-0004-0004-0004-000000000003\",\"title\":\"Vergunning verleend\",\"order\":3,\"isRequired\":false,\"automaticActions\":[{\"type\":\"setField\",\"config\":{\"field\":\"paymentIndication\",\"value\":\"geen-betaling\"}},{\"type\":\"sendEmail\",\"config\":{\"to\":\"{{case.communicationChannel}}\",\"subject\":\"Kapvergunning {{case.identifier}} verleend\",\"body\":\"Uw kapvergunning is verleend. De vergunning is geldig voor 26 weken na dagtekening.\"}}]}]",
  "transitions": "[{\"id\":\"t4000001-0004-0004-0004-000000000001\",\"fromStatus\":\"ontvangen\",\"toStatus\":\"beoordeling\",\"label\":\"Starten beoordeling\",\"guards\":[],\"automaticActions\":[{\"type\":\"notify\",\"config\":{\"roles\":[\"toezichthouder\"],\"message\":\"Kapvergunning {{case.identifier}} klaar voor veldcontrole.\"}}],\"allowedRoles\":[]},{\"id\":\"t4000001-0004-0004-0004-000000000002\",\"fromStatus\":\"beoordeling\",\"toStatus\":\"vergund\",\"label\":\"Vergunning verlenen\",\"guards\":[{\"type\":\"checklist\",\"checklistId\":\"veldcontrole\"}],\"automaticActions\":[],\"allowedRoles\":[]}]"
}
```

### Seed Object 5 — Subsidieaanvraag Workflow

```json
{
  "@self": {
    "register": "procest",
    "schema": "workflowTemplate",
    "slug": "workflow-subsidieaanvraag-v1"
  },
  "title": "Subsidieaanvraag — Standaard Procedure",
  "description": "Workflow voor subsidieaanvragen met meervoudige goedkeuring en externe webhookintegratie met het financieel systeem.",
  "version": 1,
  "isActive": false,
  "isDraft": true,
  "steps": "[{\"id\":\"a5b6c7d8-0005-0005-0005-000000000001\",\"title\":\"Aanvraag ingediend\",\"order\":1,\"isRequired\":true,\"automaticActions\":[{\"type\":\"sendEmail\",\"config\":{\"to\":\"{{case.communicationChannel}}\",\"subject\":\"Subsidieaanvraag {{case.identifier}} ingediend\",\"body\":\"Uw subsidieaanvraag is ontvangen. De beoordeling duurt maximaal 13 weken.\"}},{\"type\":\"notify\",\"config\":{\"roles\":[\"subsidieadviseur\"],\"message\":\"Nieuwe subsidieaanvraag {{case.identifier}} vereist beoordeling.\"}}]},{\"id\":\"a5b6c7d8-0005-0005-0005-000000000002\",\"title\":\"Inhoudelijke beoordeling\",\"order\":2,\"isRequired\":true,\"automaticActions\":[{\"type\":\"createTask\",\"config\":{\"title\":\"Controleer begroting subsidieaanvraag {{case.identifier}}\",\"description\":\"Beoordeel de ingediende begroting op realisme en subsidiabiliteit van de kosten.\",\"assigneeRole\":\"financieel-adviseur\",\"dueDateOffsetDays\":14}}]},{\"id\":\"a5b6c7d8-0005-0005-0005-000000000003\",\"title\":\"Beschikking\",\"order\":3,\"isRequired\":true,\"automaticActions\":[{\"type\":\"webhook\",\"config\":{\"url\":\"https://financieel.gemeente-voorbeeld.nl/api/subsidies/beschikkingen\",\"method\":\"POST\"}},{\"type\":\"sendEmail\",\"config\":{\"to\":\"{{case.communicationChannel}}\",\"subject\":\"Beschikking subsidieaanvraag {{case.identifier}}\",\"body\":\"De beschikking op uw subsidieaanvraag is genomen. U ontvangt binnen 5 werkdagen bericht per post.\"}},{\"type\":\"setField\",\"config\":{\"field\":\"archiveNomination\",\"value\":\"bewaren\"}}]}]",
  "transitions": "[{\"id\":\"t5000001-0005-0005-0005-000000000001\",\"fromStatus\":\"ingediend\",\"toStatus\":\"beoordeling\",\"label\":\"Starten beoordeling\",\"guards\":[{\"type\":\"requiredField\",\"field\":\"assignee\"}],\"automaticActions\":[],\"allowedRoles\":[]},{\"id\":\"t5000001-0005-0005-0005-000000000002\",\"fromStatus\":\"beoordeling\",\"toStatus\":\"beschikking\",\"label\":\"Beschikking nemen\",\"guards\":[{\"type\":\"requiredDocument\",\"documentTypeSlug\":\"subsidiebeschikking\"},{\"type\":\"checklist\",\"checklistId\":\"begrotingstoets\"}],\"automaticActions\":[],\"allowedRoles\":[]}]"
}
```
