# Inventory: procedure and standard classes in dossiq

Measured on `origin/development` at `b22243053`, 10 October 2026. One row per class under `lib/` whose path names a procedure or a standard. Capability ids refer to the catalogue in hydra `openspec/changes/procedures-are-configuration/design.md` section 4. "What it does" is the class docblock, shortened.

Kinds: procedure logic moves onto a capability; standards adapter stays code; generic code is renamed or moved; seeders become packages; one-off migrations retire.

| Family | Classes | Lines |
|---|---:|---:|
| zgw | 57 | 26,530 |
| termijn | 34 | 8,990 |
| bezwaar | 25 | 7,900 |
| seed | 24 | 6,808 |
| beschikking | 28 | 6,493 |
| stuf | 25 | 5,587 |
| woo | 15 | 4,109 |
| vth | 13 | 3,770 |
| mandaat | 13 | 3,426 |
| brp-kvk-bag | 20 | 3,384 |
| intake | 12 | 3,135 |
| migration | 8 | 2,318 |
| subsidie | 8 | 2,219 |
| money | 6 | 1,653 |
| events | 6 | 767 |
| digid-eherkenning | 6 | 578 |

## zgw

| Class | Lines | What it does | Kind | Capability | Target |
|---|---:|---|---|---|---|
| `AppInfo/Registrar/ExternalZgwRegistrar.php` | 70 | Registers the dormant external-ZGW and ZTC / Catalogi-API client aliases. | standards adapter | - | integriq (outbound ZGW client) |
| `Controller/BrcController.php` | 955 | BRC (Besluiten) API Controller Handles ZGW Besluiten register resources: besluiten and besluitinformatieobjecten. Implements... | standards adapter | - | dossiq (ZGW provider surface, allowlisted; integriq later) |
| `Controller/DrcController.php` | 2129 | DRC (Documenten) API Controller Handles ZGW Documenten register resources with EIO-specific features: base64 file content handling,... | standards adapter | - | dossiq (ZGW provider surface, allowlisted; integriq later) |
| `Controller/NrcController.php` | 334 | NRC (Notificaties) API Controller Handles ZGW Notificaties register resources: kanaal and abonnement, plus a notificatie acceptance... | standards adapter | - | dossiq (ZGW provider surface, allowlisted; integriq later) |
| `Controller/ZaakdossierController.php` | 448 | Controller for the ZGW DRC zaakdossier. | generic, procedure name | C27 case documents | dossiq core, rename to CaseDocument* |
| `Controller/ZaakdossierDownloadController.php` | 224 | Controller for zaakdossier binary downloads. | generic, procedure name | C27 case documents | dossiq core, rename to CaseDocument* |
| `Controller/ZgwController.php` | 66 | Abstract base class for all ZGW API controllers. ZgwAuthMiddleware uses `instanceof ZgwController` to identify which controllers fall... | standards adapter | - | dossiq (ZGW provider surface, allowlisted; integriq later) |
| `Controller/ZgwMappingController.php` | 229 | Controller for managing ZGW API mapping configurations. | standards adapter | - | dossiq (ZGW provider surface, allowlisted; integriq later) |
| `Controller/ZgwOpenApiController.php` | 181 | Discovery + spec-serving controller for Dossiq's ZGW OpenAPI documents. | standards adapter | - | dossiq (ZGW provider surface, allowlisted; integriq later) |
| `Controller/ZrcController.php` | 2562 | ZRC (Zaken Register) Controller Serves ZGW-compliant Zaken API endpoints on top of English-language OpenRegister data with bidirectional... | standards adapter | - | dossiq (ZGW provider surface, allowlisted; integriq later) |
| `Controller/ZtcController.php` | 1324 | ZTC (Catalogi) API Controller Handles ZGW Catalogi register resources with publish support for zaaktypen, besluittypen, and... | standards adapter | - | dossiq (ZGW provider surface, allowlisted; integriq later) |
| `Middleware/ZgwAuthException.php` | 62 | Exception for ZGW authentication and authorization failures. | standards adapter | - | dossiq (ZGW provider surface, allowlisted; integriq later) |
| `Middleware/ZgwAuthMiddleware.php` | 470 | Middleware that validates JWT tokens and enforces ZGW scopes. Applied to all ZgwController requests. Validates the Authorization header,... | standards adapter | - | dossiq (ZGW provider surface, allowlisted; integriq later) |
| `Repair/BackfillInformatieobjectMetadata.php` | 328 | Repair step that back-fills informatieobject metadata for existing files. | generic, procedure name | C27 case documents | dossiq core, rename to CaseDocument* |
| `Repair/LoadDefaultZgwMappings.php` | 2139 | Repair step that loads default ZGW API mapping configurations. | standards adapter | - | dossiq (ZGW provider surface, allowlisted; integriq later) |
| `Service/External/Zgw/LogZgwExternalAdapter.php` | 175 | Dormant log-backed Dossiq external-ZGW adapter. | standards adapter | - | integriq (outbound ZGW client) |
| `Service/External/Zgw/NoteEnvelope.php` | 207 | Builds the ZGW document envelope one note travels in. | standards adapter | - | integriq (outbound ZGW client) |
| `Service/External/Zgw/NotePush.php` | 425 | Pushes one case note to a neighbouring ZGW register, or says why it did not. | standards adapter | - | integriq (outbound ZGW client) |
| `Service/External/Zgw/ZgwExternalAdapterInterface.php` | 129 | External-ZGW client port. Implementations MUST be side-effect-free when the dormant flag is set; a dormant adapter records the intent... | standards adapter | - | integriq (outbound ZGW client) |
| `Service/External/Zgw/ZgwPushResult.php` | 66 | Result of an external-ZGW push attempt. `pushStatus` is one of `PUSHED`, `REJECTED`, `PUSH_DEFERRED`, `PUSH_ERROR`. `PUSHED` means the... | standards adapter | - | integriq (outbound ZGW client) |
| `Service/External/Ztc/LogZtcCatalogiAdapter.php` | 129 | Dormant log-backed Dossiq ZTC / Catalogi-API adapter. | standards adapter | - | integriq (outbound ZGW client) |
| `Service/External/Ztc/ZtcCatalogiAdapterInterface.php` | 127 | ZTC / Catalogi-API client port. Implementations MUST be side-effect-free when the dormant flag is set; a dormant adapter records the... | standards adapter | - | integriq (outbound ZGW client) |
| `Service/External/Ztc/ZtcResult.php` | 61 | Result of a ZTC / Catalogi-API resolve / import attempt. `outcome` is one of `FOUND`, `IMPORTED`, `NOT_FOUND`, `LOOKUP_DEFERRED`,... | standards adapter | - | integriq (outbound ZGW client) |
| `Service/InformatieobjectAccessGuard.php` | 286 | Enforces vertrouwelijkheidaanduiding-based access control on informatieobjecten. | generic, procedure name | C27 case documents | dossiq core, rename to CaseDocument* |
| `Service/Zaakdossier/BulkDocumentActions.php` | 129 | One act over many informatieobjecten. | generic, procedure name | C27 case documents | dossiq core, rename to CaseDocument* |
| `Service/Zaakdossier/CorrespondentWriter.php` | 389 | The one place a document's correspondents are written. Two things happen together and never apart. The document gets `sender` and... | generic, procedure name | C27 case documents | dossiq core, rename to CaseDocument* |
| `Service/Zaakdossier/DocumentApprovalClearance.php` | 195 | Reads decidiq's clearance answer for one document. | generic, procedure name | C27 case documents | dossiq core, rename to CaseDocument* |
| `Service/Zaakdossier/DocumentCorrespondents.php` | 383 | Which party a document came from, and which parties it went to. 🔴 A CORRESPONDENT IS A PARTY OF THE CASE, NEVER A TYPED NAME.... | generic, procedure name | C27 case documents | dossiq core, rename to CaseDocument* |
| `Service/Zaakdossier/DocumentDefaults.php` | 197 | What a document's record says when nothing but the file is known. The derived defaults of a drop (REQ-DPR-001): the title from the name,... | generic, procedure name | C27 case documents | dossiq core, rename to CaseDocument* |
| `Service/Zaakdossier/DocumentJoinHoming.php` | 115 | What the ZRC does with a document's file when a zaakinformatieobject lands. The ZGW API creates the informatieobject before any join... | generic, procedure name | C27 case documents | dossiq core, rename to CaseDocument* |
| `Service/Zaakdossier/DocumentProjectionService.php` | 387 | Keeps a case's ZGW document records in step with the files in its folder. A document is a normal file in the case's folder first and an... | generic, procedure name | C27 case documents | dossiq core, rename to CaseDocument* |
| `Service/Zaakdossier/DocumentRecordStore.php` | 410 | The OpenRegister side of the document projection: cases, records, joins and document types, read and written as plain rows. Kept apart... | generic, procedure name | C27 case documents | dossiq core, rename to CaseDocument* |
| `Service/Zaakdossier/DossierUploadHandler.php` | 243 | Decodes, screens and stores dossier document uploads. | generic, procedure name | C27 case documents | dossiq core, rename to CaseDocument* |
| `Service/Zaakdossier/DossierZipExporter.php` | 146 | Builds clearance-filtered dossier ZIP exports. | generic, procedure name | C27 case documents | dossiq core, rename to CaseDocument* |
| `Service/Zaakdossier/GeneratedDocumentFiler.php` | 200 | Turns a generated file into an informatieobject on the case. | generic, procedure name | C27 case documents | dossiq core, rename to CaseDocument* |
| `Service/Zaakdossier/InformatieobjectMetadataNormaliser.php` | 119 | Coercion of `informatieobject.keywords` and `informatieobject.direction`. | generic, procedure name | C27 case documents | dossiq core, rename to CaseDocument* |
| `Service/Zaakdossier/InformatieobjectReader.php` | 150 | Resolves informatieobjecten behind the per-object clearance guard. | generic, procedure name | C27 case documents | dossiq core, rename to CaseDocument* |
| `Service/Zaakdossier/InformatieobjectStatusLifecycle.php` | 288 | The forward-only status state machine for a ZGW informatieobject. | generic, procedure name | C27 case documents | dossiq core, rename to CaseDocument* |
| `Service/Zaakdossier/LinkedDocumentsReader.php` | 173 | The documents a case is joined to whose file lives in another case's folder. The Files tab shows the case's own folder; a document... | generic, procedure name | C27 case documents | dossiq core, rename to CaseDocument* |
| `Service/ZaakdossierService.php` | 734 | Service orchestrating the ZGW DRC zaakdossier. The per-document status state machine is owned by {@see InformatieobjectStatusLifecycle};... | generic, procedure name | C27 case documents | dossiq core, rename to CaseDocument* |
| `Service/Zgw/BrondatumArchiefValidator.php` | 261 | Validates brondatumArchiefprocedure cross-field constraints (ztc-003 to ztc-008). | standards adapter | - | dossiq (ZGW provider surface, allowlisted; integriq later) |
| `Service/Zgw/ZgwRulesDispatcher.php` | 330 | Routes a validated ZGW request to the rules service that owns its resource. | standards adapter | - | dossiq (ZGW provider surface, allowlisted; integriq later) |
| `Service/Zgw/ZgwSearchScope.php` | 140 | Whether a ZGW mapping's register and schema can actually be searched. 🔴 A SEARCH SCOPE OPENREGISTER CANNOT RESOLVE ANSWERS EXACTLY LIKE... | standards adapter | - | dossiq (ZGW provider surface, allowlisted; integriq later) |
| `Service/Zgw/ZgwZrcZaakinformatieobjectRules.php` | 365 | ZRC zaakinformatieobjecten validation and enrichment. | standards adapter | - | dossiq (ZGW provider surface, allowlisted; integriq later) |
| `Service/Zgw/ZgwZtcResultaattypeRules.php` | 287 | ZTC resultaattypen validation and enrichment. | standards adapter | - | dossiq (ZGW provider surface, allowlisted; integriq later) |
| `Service/ZgwAuthValidationException.php` | 34 | Exception for ZGW JWT validation failures. | standards adapter | - | dossiq (ZGW provider surface, allowlisted; integriq later) |
| `Service/ZgwBrcRulesService.php` | 641 | BRC (Besluiten API) business rule validation and enrichment. | standards adapter | - | dossiq (ZGW provider surface, allowlisted; integriq later) |
| `Service/ZgwBusinessRulesService.php` | 307 | Applies the cross-cutting ZGW guards, then delegates to the rules dispatcher. Handles cross-register concerns like concept protection... | standards adapter | - | dossiq (ZGW provider surface, allowlisted; integriq later) |
| `Service/ZgwDocumentService.php` | 450 | Service for managing binary document storage in the DRC. A case document belongs to the case, not to whoever uploaded it. It is stored... | standards adapter | - | dossiq (ZGW provider surface, allowlisted; integriq later) |
| `Service/ZgwDrcRulesService.php` | 785 | DRC (Documenten API) business rule validation and enrichment. | standards adapter | - | dossiq (ZGW provider surface, allowlisted; integriq later) |
| `Service/ZgwJwtValidator.php` | 291 | Validates ZGW JWT bearer tokens against OpenRegister Consumer credentials. OpenRegister's AuthorizationService::authorizeJwt() is a... | standards adapter | - | dossiq (ZGW provider surface, allowlisted; integriq later) |
| `Service/ZgwMappingService.php` | 241 | Service for managing ZGW API mapping configuration. Stores mapping configuration as JSON in IAppConfig under keys like... | standards adapter | - | dossiq (ZGW provider surface, allowlisted; integriq later) |
| `Service/ZgwPaginationHelper.php` | 104 | ZGW pagination helper Wraps standard pagination results into the ZGW HAL-style format: { "count": N, "next": url/null, "previous":... | standards adapter | - | dossiq (ZGW provider surface, allowlisted; integriq later) |
| `Service/ZgwRulesBase.php` | 912 | Base class for ZGW register-specific business rule services. Provides shared utilities: UUID extraction, URL validation, external URL... | standards adapter | - | dossiq (ZGW provider surface, allowlisted; integriq later) |
| `Service/ZgwService.php` | 2042 | Shared ZGW API service. Contains all shared utility methods extracted from the monolithic ZgwController. Register-specific controllers... | standards adapter | - | dossiq (ZGW provider surface, allowlisted; integriq later) |
| `Service/ZgwZrcRulesService.php` | 1127 | ZRC (Zaken API) business rule validation and enrichment. | standards adapter | - | dossiq (ZGW provider surface, allowlisted; integriq later) |
| `Service/ZgwZtcRulesService.php` | 829 | ZTC (Catalogi API) business rule validation and enrichment. | standards adapter | - | dossiq (ZGW provider surface, allowlisted; integriq later) |

## termijn

| Class | Lines | What it does | Kind | Capability | Target |
|---|---:|---|---|---|---|
| `AppInfo/Registrar/TermijnTimerRegistrar.php` | 78 | Registers the engine timer fired-listener. | procedure logic | C01 term and deadline timer | OpenRegister engine timers + dossiq Term core |
| `BackgroundJob/WOODeadlineCheckJob.php` | 246 | Daily timed job that checks WOO case deadlines and emits T-7 warnings. | procedure logic | C01 term and deadline timer | OpenRegister engine timers + dossiq Term core |
| `Controller/TermijnController.php` | 398 | REST surface for TermijnInstance lifecycle. | procedure logic | C01 term and deadline timer | OpenRegister engine timers + dossiq Term core |
| `Controller/TermijnDefinitieController.php` | 214 | Admin CRUD for TermijnDefinities. | procedure logic | C01 term and deadline timer | OpenRegister engine timers + dossiq Term core |
| `Exception/NoTermijnDefinitieException.php` | 45 | No active TermijnDefinitie matches a case type (REQ-TERM-001-A). | procedure logic | C01 term and deadline timer | OpenRegister engine timers + dossiq Term core |
| `Lifecycle/BezwaarDeadlineGuard.php` | 99 | Allows the bezwaar `beslissen` transition only while the statutory decision deadline (AWB art. 7:10) has not been exceeded. | procedure logic | C01 term and deadline timer | OpenRegister engine timers + dossiq Term core |
| `Listener/BezwaarArchiveTimerFiredListener.php` | 112 | Turns a bezwaartermijn breach into the archive or the switch-off. | procedure logic | C01 term and deadline timer | OpenRegister engine timers + dossiq Term core |
| `Listener/BezwaarArchiveTimerListener.php` | 103 | Syncs the bezwaartermijn timer on every save that moved its dates or switch. | procedure logic | C01 term and deadline timer | OpenRegister engine timers + dossiq Term core |
| `Listener/DsoDeadlineTimerFiredListener.php` | 143 | Turns a DSO term rung into the notification or the overdue marker. | procedure logic | C01 term and deadline timer | OpenRegister engine timers + dossiq Term core |
| `Listener/DsoDeadlineTimerListener.php` | 109 | Syncs the DSO timer on every case save that moved its status or deadline. | procedure logic | C01 term and deadline timer | OpenRegister engine timers + dossiq Term core |
| `Listener/TermijnTimerFiredListener.php` | 383 | Maps engine rung fires onto the AWB termijn domain actions. service account's refusal: a fire from cron writes as that account or not at... | procedure logic | C01 term and deadline timer | OpenRegister engine timers + dossiq Term core |
| `Repair/ArmBezwaarArchiveTimers.php` | 158 | Syncs the timer of every active bezwaar trigger once. | procedure logic | C01 term and deadline timer | OpenRegister engine timers + dossiq Term core |
| `Repair/ArmDsoDeadlineTimers.php` | 150 | Syncs the timer of every open DSO case once. | procedure logic | C01 term and deadline timer | OpenRegister engine timers + dossiq Term core |
| `Repair/ArmTermijnEngineTimers.php` | 270 | Arms engine timers for existing in-flight TermijnInstances. | procedure logic | C01 term and deadline timer | OpenRegister engine timers + dossiq Term core |
| `Repair/RearmBeslistermijnTimers.php` | 233 | Re-arms every running beslistermijn timer once, so it breaches the day after the last day. | procedure logic | C01 term and deadline timer | OpenRegister engine timers + dossiq Term core |
| `Service/Beschikking/BezwaarArchiveTimer.php` | 272 | Keeps one engine timer per active bezwaar trigger in step with the trigger. | procedure logic | C01 term and deadline timer | OpenRegister engine timers + dossiq Term core |
| `Service/Beschikking/BezwaarTermijnScheduler.php` | 160 | Computes and schedules the Awb 6:7 bezwaartermijn of a beschikking. | procedure logic | C01 term and deadline timer | OpenRegister engine timers + dossiq Term core |
| `Service/Dso/DsoDeadlineActs.php` | 188 | Notifies the assignee and marks an overdue DSO case. | procedure logic | C02 notice from a template | dossiq notice core (filinq render, integriq send) |
| `Service/Dso/DsoDeadlineTimer.php` | 310 | Keeps one engine timer per open DSO case in step with the case. | procedure logic | C01 term and deadline timer | OpenRegister engine timers + dossiq Term core |
| `Service/Termijn/CaseDeadlineMirror.php` | 322 | Which statutory term decides a case's deadline, and the write that applies it. | procedure logic | C01 term and deadline timer | OpenRegister engine timers + dossiq Term core |
| `Service/Termijn/TermDefinitions.php` | 317 | The term definitions a case type carries, and what their duration implies. | procedure logic | C01 term and deadline timer | OpenRegister engine timers + dossiq Term core |
| `Service/Termijn/TermEndRoll.php` | 258 | Rolls a term end onto the first ordinary day the calendar allows. | procedure logic | C01 term and deadline timer | OpenRegister engine timers + dossiq Term core |
| `Service/Termijn/TermInstanceStore.php` | 239 | Where a term instance is read and written. | procedure logic | C01 term and deadline timer | OpenRegister engine timers + dossiq Term core |
| `Service/Termijn/TermLetters.php` | 566 | The wording of every term notification. | procedure logic | C01 term and deadline timer | OpenRegister engine timers + dossiq Term core |
| `Service/Termijn/TermMoveHistory.php` | 169 | The moves an instance's deadline has already made. | procedure logic | C01 term and deadline timer | OpenRegister engine timers + dossiq Term core |
| `Service/Termijn/TermNoticeLedger.php` | 178 | Claims, outcomes and releases of term notices. | procedure logic | C01 term and deadline timer | OpenRegister engine timers + dossiq Term core |
| `Service/Termijn/TermNoticeSender.php` | 401 | Mails one term notice, once, if integriq allows it. | procedure logic | C01 term and deadline timer | OpenRegister engine timers + dossiq Term core |
| `Service/Termijn/TermRearm.php` | 367 | Re-arming a case's running terms against another case type's definition. | procedure logic | C01 term and deadline timer | OpenRegister engine timers + dossiq Term core |
| `Service/Termijn/WorkingDayRoll.php` | 496 | Rolls a date to the next ordinary day, on the engine's calendar. | procedure logic | C01 term and deadline timer | OpenRegister engine timers + dossiq Term core |
| `Service/TermijnNotificationService.php` | 244 | Burger notification template renderer + dispatcher. | procedure logic | C02 notice from a template | dossiq notice core (filinq render, integriq send) |
| `Service/TermijnService.php` | 534 | Server-authoritative TermijnInstance lifecycle. | procedure logic | C01 term and deadline timer | OpenRegister engine timers + dossiq Term core |
| `Service/TermijnTimerService.php` | 698 | Arms, suspends, resumes, extends and cancels engine timers for AWB terms. | procedure logic | C01 term and deadline timer | OpenRegister engine timers + dossiq Term core |
| `Service/WOODeadlineService.php` | 370 | Service for WOO-mandated deadline calculation and tracking. | procedure logic | C01 term and deadline timer | OpenRegister engine timers + dossiq Term core |
| `Woo/WooDecisionNotice.php` | 160 | Tells the resident that the decision on their Woo request is published. | procedure logic | C02 notice from a template | dossiq notice core (filinq render, integriq send) |

## bezwaar

| Class | Lines | What it does | Kind | Capability | Target |
|---|---:|---|---|---|---|
| `Controller/BezwaarHearingController.php` | 143 | Controller for bezwaar hearing (hoorzitting) attendance capture. The endpoint carries the NoAdminRequired annotation and requires an... | procedure logic | C12 session with parties and minutes | decidiq meeting + OpenRegister calendar |
| `Controller/BezwaarObjectionController.php` | 135 | Say which besluit a bezwaar case is against, and open the objection. | procedure logic | C11 linked case with a role | dossiq Relation core |
| `Controller/ComplaintHearingController.php` | 163 | Controller for complaint hearings (hoorgesprekken). | procedure logic | C12 session with parties and minutes | decidiq meeting + OpenRegister calendar |
| `Controller/DeelzaakController.php` | 298 | REST controller for sub-case operations. | procedure logic | C11 linked case with a role | dossiq Relation core |
| `Flow/DossiqEnsureCommitteeNode.php` | 392 | Make sure the objection's advisory committee exists as a governance body. COMMITTEES ARE THE DECISION APP'S. A bezwaaradviescommissie is... | procedure logic | C13 advisory body and advice request | decidiq governance body |
| `Listener/BeroepEscalationListener.php` | 345 | Derives the dwingendStatus marker on the source bezwaar when a non-terminal beroep exists within the 6-week filing window. | procedure logic | C11 linked case with a role | dossiq Relation core |
| `Listener/BezwaarAdviceRequestedListener.php` | 222 | Auto-assigns the default BAC when bezwaar enters "Hearing planned". | procedure logic | C13 advisory body and advice request | decidiq governance body |
| `Listener/BezwaarHearingScheduledListener.php` | 217 | Seeds a default hearingSession when bezwaar status becomes "Hearing planned". | procedure logic | C12 session with parties and minutes | decidiq meeting + OpenRegister calendar |
| `Listener/BezwaarLegalHoldListener.php` | 389 | Translates Awb bezwaar/beroep lifecycle events into OpenRegister legal holds. | procedure logic | C14 legal hold and archival trigger | OpenRegister LegalHold + lifecycle annotation |
| `Service/BeroepDossierExport.php` | 172 | Builds the ordered, numbered export plan for a beroep dossier. | procedure logic | C11 linked case with a role | dossiq Relation core |
| `Service/Beschikking/BezwaarArchiveTrigger.php` | 180 | Archives a beschikking whose objection term ran out, and switches the trigger off. | procedure logic | C14 legal hold and archival trigger | OpenRegister LegalHold + lifecycle annotation |
| `Service/Bezwaar/AdvisoryCommitteeService.php` | 705 | BAC service: committee assignment + advice request lifecycle. | procedure logic | C13 advisory body and advice request | decidiq governance body |
| `Service/Bezwaar/BeroepService.php` | 546 | Beroep service: filing, file-inspection requests, judgment, cascade. | procedure logic | C11 linked case with a role | dossiq Relation core |
| `Service/Bezwaar/BezwaarAuditTrail.php` | 404 | Writes bezwaar entries onto OpenRegister's audit trail of their record. | procedure logic | C14 legal hold and archival trigger | OpenRegister audit trail (L5) |
| `Service/Bezwaar/BezwaarCreationHook.php` | 343 | Establishes primair-besluit linking and the objection record for a newly created bezwaar case. | procedure logic | C11 linked case with a role | dossiq Relation core |
| `Service/Bezwaar/BezwaarEntryNotWrittenException.php` | 72 | A bezwaar entry could not be written to OpenRegister's audit trail. It carries the entry it could not write, so whoever catches it can... | procedure logic | C14 legal hold and archival trigger | OpenRegister audit trail (L5) |
| `Service/Bezwaar/CommitteeDelegationService.php` | 419 | Ask the decision app to hold a bezwaaradviescommissie as a GovernanceBody. A bezwaaradviescommissie IS a governance body, and governance... | procedure logic | C13 advisory body and advice request | decidiq governance body |
| `Service/Bezwaar/HearingMinutesRecorder.php` | 182 | Assembles the verslag patch and guards recording consent + late corrections. | procedure logic | C12 session with parties and minutes | decidiq meeting + OpenRegister calendar |
| `Service/Bezwaar/HearingSchedulePlanner.php` | 191 | Computes hearing dates, the inspection-of-file floor, and invitee stamps. | procedure logic | C12 session with parties and minutes | decidiq meeting + OpenRegister calendar |
| `Service/Bezwaar/HearingService.php` | 718 | Hearing service: scheduling, waiver, attendance and minutes capture. | procedure logic | C12 session with parties and minutes | decidiq meeting + OpenRegister calendar |
| `Service/Bezwaar/PanelIndependenceChecker.php` | 249 | Verifies that no BAC panel member authored the contested primair besluit. | procedure logic | C13 advisory body and advice request | decidiq governance body |
| `Service/DeelzaakService.php` | 460 | Service for parent-child (deelzaak) case relations. | procedure logic | C11 linked case with a role | dossiq Relation core |
| `Service/DwangsomBezwaarService.php` | 292 | Bezwaar lifecycle for a DwangsomBerekening. | procedure logic | C11 linked case with a role | dossiq Relation core |
| `Service/HearingCalendarService.php` | 407 | Writes the calendar event for a hearing. | procedure logic | C12 session with parties and minutes | decidiq meeting + OpenRegister calendar |
| `Service/HearingService.php` | 256 | Service for hearing (hoorgesprek) management within the complaint workflow. | procedure logic | C12 session with parties and minutes | decidiq meeting + OpenRegister calendar |

## seed

| Class | Lines | What it does | Kind | Capability | Target |
|---|---:|---|---|---|---|
| `Command/SeedBezwaarBeroepCommand.php` | 141 | Seed the Bezwaar & Beroep case types, status types and role types. | procedure seeder | C19 case definition package | dossiq CaseDefinition package + ShippedSets |
| `Controller/BesluitvormingController.php` | 125 | Controller exposing besluitvorming template-activation endpoints. | procedure seeder | C19 case definition package | dossiq CaseDefinition package + ShippedSets |
| `Controller/VTHTemplateController.php` | 113 | Controller for VTH zaaktype template management. Admin-only; activating a template creates case type configuration in OpenRegister.... | procedure seeder | C19 case definition package | dossiq CaseDefinition package + ShippedSets |
| `Repair/DefaultDsoIntakeSchema.php` | 182 | Fills the DSO intake schema key once, when it was never set. | procedure seeder | C19 case definition package | dossiq CaseDefinition package + ShippedSets |
| `Repair/MigrateSubsidieRegelingToCaseType.php` | 463 | Migrates subsidieRegeling objects onto caseType + propertyDefinition. | procedure seeder | C19 case definition package | dossiq CaseDefinition package + ShippedSets |
| `Repair/RealignLhsActorTypeVocabulary.php` | 203 | Repair step restoring the LHS actorType axis vocabulary. | procedure seeder | C19 case definition package | dossiq CaseDefinition package + ShippedSets |
| `Repair/SeedBesluitvormingTemplates.php` | 111 | Repair step that seeds besluitvorming zaaktype templates into OpenRegister. | procedure seeder | C19 case definition package | dossiq CaseDefinition package + ShippedSets |
| `Repair/SeedBezwaarBeroepData.php` | 115 | Repair step that seeds bezwaar and beroep case types into OpenRegister. | procedure seeder | C19 case definition package | dossiq CaseDefinition package + ShippedSets |
| `Repair/SeedBezwaarWorkflowDefinition.php` | 517 | Seed the canonical bezwaar workflow definition (published, version 1). | procedure seeder | C19 case definition package | dossiq CaseDefinition package + ShippedSets |
| `Repair/SeedLhsMatrix.php` | 197 | Repair step that seeds the default LHS matrix into OpenRegister. | procedure seeder | C19 case definition package | dossiq CaseDefinition package + ShippedSets |
| `Repair/SeedVthMatrixCells.php` | 197 | Repair step that seeds the default 16-cell LHS matrix into OpenRegister. | procedure seeder | C19 case definition package | dossiq CaseDefinition package + ShippedSets |
| `Repair/SeedVthWorkflowTemplates.php` | 662 | Repair step that seeds six canonical VTH workflow templates. | procedure seeder | C19 case definition package | dossiq CaseDefinition package + ShippedSets |
| `Repair/Vth/VthCaseTypeChildSeeder.php` | 306 | Seeds a VTH case type's child collections into their own schemas. | procedure seeder | C19 case definition package | dossiq CaseDefinition package + ShippedSets |
| `Repair/Vth/VthCatalogueFiles.php` | 178 | Reads the bundled VTH workflow-template catalogue off disk. | procedure seeder | C19 case definition package | dossiq CaseDefinition package + ShippedSets |
| `Repair/Vth/VthCatalogueReport.php` | 239 | What happened to each VTH catalogue entry, and how it is reported. | procedure seeder | C19 case definition package | dossiq CaseDefinition package + ShippedSets |
| `Repair/Vth/VthChecklistSeeder.php` | 311 | Writes the shipped inspection-checklist templates, once. | procedure seeder | C19 case definition package | dossiq CaseDefinition package + ShippedSets |
| `Repair/Vth/VthSeedLookup.php` | 263 | OpenRegister lookups for the VTH workflow-template seed. | procedure seeder | C19 case definition package | dossiq CaseDefinition package + ShippedSets |
| `Repair/Vth/VthSeedRowReader.php` | 163 | Coerces OpenRegister result rows into the shapes the VTH seed needs. | procedure seeder | C19 case definition package | dossiq CaseDefinition package + ShippedSets |
| `Repair/Vth/VthWorkflowGraphResolver.php` | 401 | Resolves a VTH catalog entry's steps/transitions against a statusType map. | procedure seeder | C19 case definition package | dossiq CaseDefinition package + ShippedSets |
| `Repair/VthSeedDataRepairStep.php` | 466 | Repair step that seeds VTH case types and inspection-checklist templates into OpenRegister. | procedure seeder | C19 case definition package | dossiq CaseDefinition package + ShippedSets |
| `Service/Besluitvorming/TemplateBundleSeeder.php` | 538 | Writes a decoded besluitvorming bundle into OpenRegister. | procedure seeder | C19 case definition package | dossiq CaseDefinition package + ShippedSets |
| `Service/Besluitvorming/WorkflowReferenceResolver.php` | 231 | Resolves workflow step/transition name references to created UUIDs. | procedure seeder | C19 case definition package | dossiq CaseDefinition package + ShippedSets |
| `Service/BesluitvormingTemplateService.php` | 266 | Seeds besluitvorming zaaktype templates into OpenRegister. | procedure seeder | C19 case definition package | dossiq CaseDefinition package + ShippedSets |
| `Service/VTHTemplateService.php` | 420 | Service for loading and activating VTH zaaktype templates. VTH templates live in lib/Settings/templates/vth-*.json. Each template... | procedure seeder | C19 case definition package | dossiq CaseDefinition package + ShippedSets |

## beschikking

| Class | Lines | What it does | Kind | Capability | Target |
|---|---:|---|---|---|---|
| `AppInfo/Registrar/BeschikkingAdapterRegistrar.php` | 118 | Registers the beschikking signing and archival adapters. | procedure logic | C03 decision document | dossiq decision-document core + decidiq |
| `Controller/BeschikkingController.php` | 428 | Controller for beschikking lifecycle endpoints. | procedure logic | C03 decision document | dossiq decision-document core + decidiq |
| `Controller/BezwaarDecisionController.php` | 167 | Draft a decision on an objection, and send it to be decided. | procedure logic | C03 decision document | dossiq decision-document core + decidiq |
| `Listener/BeschikkingImmutabilityListener.php` | 315 | Reject a content edit or a delete on a signed beschikking. | procedure logic | C04 frozen record | OpenRegister seal + dossiq core |
| `Listener/BezwaarDecisionListener.php` | 414 | Guards bezwaar transitions into "Decision on objection" by requiring a published bezwaarDecision to exist for the bezwaar. | procedure logic | C03 decision document | dossiq decision-document core + decidiq |
| `Service/Beschikking/ArchivalAdapterInterface.php` | 51 | Ingests a beschikking into durable archival storage (OpenRegister). | generic, procedure name | C14 legal hold and archival trigger | OpenRegister Archival |
| `Service/Beschikking/AuditPacketBuilder.php` | 249 | Builds the verifiable audit-pakket ZIP for a beschikking. | procedure logic | C04 frozen record | OpenRegister seal + dossiq core |
| `Service/Beschikking/BeschikkingRepository.php` | 180 | Reads and writes beschikking objects via OpenRegister. | procedure logic | C03 decision document | dossiq decision-document core + decidiq |
| `Service/Beschikking/CaseRemedy.php` | 163 | Answers the clause a case's decisions print and binds the clock they start. | generic, procedure name | C03 decision document | dossiq decision-document core (remedy clause is config) |
| `Service/Beschikking/FilinqTemplateEngineAdapter.php` | 441 | Renders a beschikking through filinq's document pipeline. | generic, procedure name | C03 decision document | filinq render/sign seam (rename) |
| `Service/Beschikking/LibresignApiClient.php` | 198 | Thin HTTP client for LibreSign's local OCS API. | standards adapter | - | filinq signing |
| `Service/Beschikking/LibresignResultAssembler.php` | 256 | Builds dossiq's signed-result and validatierapport contracts. | standards adapter | - | filinq signing |
| `Service/Beschikking/LibresignSigningAdapter.php` | 273 | LibreSign-backed implementation of the beschikking signing adapter. Owns the LibreSign conversation only; the shape of what dossiq hands... | standards adapter | - | filinq signing |
| `Service/Beschikking/LibresignStatusMapper.php` | 137 | Maps LibreSign status values onto dossiq's internal signing vocabulary. | standards adapter | - | filinq signing |
| `Service/Beschikking/MockSigningAdapter.php` | 81 | Mock implementation of the signing adapter. | generic, procedure name | C03 decision document | filinq render/sign seam (rename) |
| `Service/Beschikking/MockTemplateEngineAdapter.php` | 79 | Mock implementation of the template-engine adapter. | generic, procedure name | C03 decision document | filinq render/sign seam (rename) |
| `Service/Beschikking/OpenRegisterArchivalAdapter.php` | 181 | OpenRegister-backed implementation of the archival adapter. | generic, procedure name | C14 legal hold and archival trigger | OpenRegister Archival |
| `Service/Beschikking/RemedyClauseDeclaration.php` | 230 | Reads the remedy a case type declares, and writes the clause its decisions print. | generic, procedure name | C03 decision document | dossiq decision-document core (remedy clause is config) |
| `Service/Beschikking/SigningAdapterInterface.php` | 61 | Signs a beschikking PDF via an eIDAS-qualified TSP (OpenConnector). | generic, procedure name | C03 decision document | filinq render/sign seam (rename) |
| `Service/Beschikking/TemplateAdapterChoice.php` | 75 | ONE RULE FOR THE SEAM AND THE CARD. A named class is what the admin chose; an empty `beschikking_template_adapter` binds filinq's... | generic, procedure name | C03 decision document | filinq render/sign seam (rename) |
| `Service/Beschikking/TemplateEngineAdapterInterface.php` | 61 | Renders a beschikking template (Docudesk) to PDF/A-3. | generic, procedure name | C03 decision document | filinq render/sign seam (rename) |
| `Service/BeschikkingGenerationService.php` | 340 | Service that generates beschikking documents for DSO vergunningaanvragen. | procedure logic | C03 decision document | dossiq decision-document core + decidiq |
| `Service/BeschikkingService.php` | 557 | Beschikking lifecycle orchestrator. threshold, and the one that crossed it is `CoordinatorRequirement`: the seat a case type may insist... | procedure logic | C03 decision document | dossiq decision-document core + decidiq |
| `Service/BesluitMaterialisationService.php` | 186 | Materialises the ZGW Besluit from a decidesk Decision outcome. | procedure logic | C03 decision document | dossiq decision-document core + decidiq |
| `Service/Bezwaar/DecisionService.php` | 496 | Bezwaar decision service: draft, publish, and apply to the linked bezwaar via the status-transition-engine. | procedure logic | C03 decision document | dossiq decision-document core + decidiq |
| `Service/Bezwaar/DecisionValidator.php` | 302 | Validates a bezwaarDecision payload against the Awb validity matrix. | procedure logic | C03 decision document | dossiq decision-document core + decidiq |
| `Service/BezwaarDecisionDelegationService.php` | 93 | Raises and consumes the decidesk `bezwaar-decision` Decision. | procedure logic | C03 decision document | dossiq decision-document core + decidiq |
| `Service/WOODecisionService.php` | 361 | Service for assembling the formal WOO besluit. | procedure logic | C03 decision document | dossiq decision-document core + decidiq |

## stuf

| Class | Lines | What it does | Kind | Capability | Target |
|---|---:|---|---|---|---|
| `BackgroundJob/StufRetryJob.php` | 121 | On-demand background job that retries a single StufMessage. It runs as the background service account, because cron has no user and... | standards adapter | - | integriq (collapse onto StufZkn) |
| `Controller/StufController.php` | 498 | Controller for inbound + outbound StUF SOAP messages. | standards adapter | - | integriq (collapse onto StufZkn) |
| `Service/Stuf/CircuitBreakerService.php` | 276 | Per-endpoint circuit breaker. | standards adapter | - | integriq (collapse onto StufZkn) |
| `Service/Stuf/CircuitOpenException.php` | 36 | Short-circuited: circuit breaker is open for the endpoint. | standards adapter | - | integriq (collapse onto StufZkn) |
| `Service/Stuf/NeedsInputDispatcher.php` | 121 | Dispatches needs-input events for the StUF adapter. | standards adapter | - | integriq (collapse onto StufZkn) |
| `Service/Stuf/PayloadTooLargeException.php` | 36 | Pre-send domain error: payload too large for StUF envelope. | standards adapter | - | integriq (collapse onto StufZkn) |
| `Service/Stuf/StufAdapterService.php` | 457 | Orchestrates StUF operations against legacy zaaksystemen. | standards adapter | - | integriq (collapse onto StufZkn) |
| `Service/Stuf/StufCaseMappingStore.php` | 136 | Stores and looks up case → zaak mappings. | standards adapter | - | integriq (collapse onto StufZkn) |
| `Service/Stuf/StufEnvelopeInspector.php` | 215 | Reads endpoint identity, WSSE credentials and routing hints off a raw envelope. | standards adapter | - | integriq (collapse onto StufZkn) |
| `Service/Stuf/StufException.php` | 35 | Base StUF adapter exception. | standards adapter | - | integriq (collapse onto StufZkn) |
| `Service/Stuf/StufHttpClient.php` | 242 | Sends StUF SOAP envelopes over HTTPS with WSSE+mTLS auth. | standards adapter | - | integriq (collapse onto StufZkn) |
| `Service/Stuf/StufMessageHandler.php` | 269 | Persists and updates StufMessage audit rows. | standards adapter | - | integriq (collapse onto StufZkn) |
| `Service/Stuf/StufMessageParser.php` | 324 | Parses StUF response envelopes. | standards adapter | - | integriq (collapse onto StufZkn) |
| `Service/Stuf/StufOutboundTransport.php` | 330 | Sends outbound StUF envelopes and classifies what comes back. | standards adapter | - | integriq (collapse onto StufZkn) |
| `Service/Stuf/StufRegisterAccess.php` | 262 | Thin OpenRegister ObjectService wrapper for StUF schemas. | standards adapter | - | integriq (collapse onto StufZkn) |
| `Service/Stuf/StufResponseBuilder.php` | 294 | Builds the StUF SOAP responses dossiq returns as a receiver. | standards adapter | - | integriq (collapse onto StufZkn) |
| `Service/Stuf/StufServices.php` | 68 | Immutable bundle of the collaborators the StUF surface needs. | standards adapter | - | integriq (collapse onto StufZkn) |
| `Service/Stuf/StufSoapRequestDispatcher.php` | 288 | Parses an inbound StUF SOAP request and dispatches it to the responder. | standards adapter | - | integriq (collapse onto StufZkn) |
| `Service/Stuf/StufVaultService.php` | 131 | Resolves vault references to plaintext secrets at send time. | standards adapter | - | integriq (collapse onto StufZkn) |
| `Service/Stuf/StufZknMessageResponder.php` | 352 | Builds the SOAP response for one inbound StUF message element. | standards adapter | - | integriq (collapse onto StufZkn) |
| `Service/Stuf/TimeoutException.php` | 36 | Synchronous vraag/antwoord exceeded the configured timeout. | standards adapter | - | integriq (collapse onto StufZkn) |
| `Service/Stuf/VrijBerichtNotRegisteredException.php` | 36 | Pre-send domain error: vrijBericht template not registered. | standards adapter | - | integriq (collapse onto StufZkn) |
| `Service/Stuf/ZaaktypeNotMappedException.php` | 36 | Pre-send domain error: zaaktype not mapped. | standards adapter | - | integriq (collapse onto StufZkn) |
| `Service/StufFieldMappingService.php` | 394 | Service for StUF-to-OpenRegister field mapping. Provides bidirectional mapping between StUF XML field paths and OpenRegister object... | standards adapter | - | integriq (collapse onto StufZkn) |
| `Service/StufMessageBuilder.php` | 594 | Service for constructing the outbound StUF-ZKN request envelopes. | standards adapter | - | integriq (collapse onto StufZkn) |

## woo

| Class | Lines | What it does | Kind | Capability | Target |
|---|---:|---|---|---|---|
| `Controller/WOOAssessmentController.php` | 451 | Controller for WOO document assessment, deadline extension, besluit, and publication. sub-capability... | procedure logic | C06 review set and triage | dossiq review core |
| `Flow/DossiqTxBesluitvormingPublishNode.php` | 114 | Flow node for the live `besluitvormingPublish` transition action. A thin wrapper: BesluitvormingPublishHandler keeps the logic. This is... | procedure logic | C09 publication of an outcome | dossiq publisher over opencatalogi / DROP |
| `Listener/WooRefusalGroundDeleteGuard.php` | 215 | Refuses the delete of a cited Woo refusal ground. | procedure logic | C06 review set and triage | dossiq review core (grounds are seed data) |
| `Service/Transitions/BesluitvormingPublishHandler.php` | 97 | Auto-action handler that dispatches a besluit to DROP/LVBB. | procedure logic | C09 publication of an outcome | dossiq publisher over opencatalogi / DROP |
| `Service/WOOAnonymisationAssistService.php` | 442 | Service orchestrating LLM-assisted, human-reviewed redaction proposals. | procedure logic | C06 review set and triage | dossiq review core |
| `Service/WOODocumentAssessmentService.php` | 518 | Service for WOO per-document disclosure assessments. | procedure logic | C06 review set and triage | dossiq review core |
| `Service/WOORedactionService.php` | 256 | Service for WOO document redaction with Docudesk feature detection. | procedure logic | C06 review set and triage | filinq redaction |
| `Service/WooPublication/OpenCatalogiApiClient.php` | 538 | Writes OpenCatalogi's publication model through OpenRegister in process. | procedure logic | C09 publication of an outcome | dossiq publisher over opencatalogi / DROP |
| `Service/WooPublication/WooCategoryMapper.php` | 82 | Maps dossiq WOO decisions to a DIWOO informatiecategorie. | procedure logic | C09 publication of an outcome | dossiq publisher over opencatalogi / DROP |
| `Service/WooPublicationService.php` | 582 | Service for publishing WOO decisions through OpenCatalogi. | procedure logic | C09 publication of an outcome | dossiq publisher over opencatalogi / DROP |
| `Woo/WooCaseDocuments.php` | 202 | Lists a case's documents and loads one with its file content. | procedure logic | C08 document collection | dossiq collection core |
| `Woo/WooCaseLedger.php` | 150 | Reads the case's Woo decision and writes the case's publication state. | procedure logic | C09 publication of an outcome | dossiq publisher over opencatalogi / DROP |
| `Woo/WooDossierReturn.php` | 187 | Appends a published Woo decision to its source dossier, once. | procedure logic | C09 publication of an outcome | dossiq publisher over opencatalogi / DROP |
| `Woo/WooRefusalGrounds.php` | 242 | Reads the refusal grounds as the system, and never answers an empty list. | procedure logic | C06 review set and triage | dossiq review core (grounds are seed data) |
| `Woo/WooRefusalGroundsUnavailable.php` | 33 | Thrown instead of answering an empty list, which would read as "no grounds exist". | procedure logic | C06 review set and triage | dossiq review core (grounds are seed data) |

## vth

| Class | Lines | What it does | Kind | Capability | Target |
|---|---:|---|---|---|---|
| `Command/MigrateLhsToDecisionTablesCommand.php` | 159 | Project Dossiq workflow definitions onto OpenRegister flows. | procedure logic | C15 decision table recommendation | OpenRegister Dmn |
| `Controller/DwangsomController.php` | 327 | REST surface for DwangsomBerekening state + bezwaar. | procedure logic | C16 money calculation and payment | OpenRegister Calculation + shillinq |
| `Controller/InspectController.php` | 98 | Whether the reader may be offered the raw inspection surfaces. | procedure logic | C18 checklist on a task | OpenRegister task + checklist form (PRs #3610/#3611) |
| `Controller/InspectionChecklistController.php` | 380 | Controller for inspection checklist CRUD and inspection result submission. the rule asks for twelve. This was already failing before the... | procedure logic | C18 checklist on a task | OpenRegister task + checklist form (PRs #3610/#3611) |
| `Controller/LhsController.php` | 332 | Controller for LHS engine actions. | procedure logic | C15 decision table recommendation | OpenRegister Dmn |
| `Controller/NoticeOfDefaultController.php` | 170 | REST surface for ingebrekestelling registration. | procedure logic | C16 money calculation and payment | OpenRegister Calculation + shillinq |
| `Service/InspectionChecklistService.php` | 447 | Service for managing inspection checklists (admin CRUD + case completion). Distinct from the existing ChecklistService (which handles... | procedure logic | C18 checklist on a task | OpenRegister task + checklist form (PRs #3610/#3611) |
| `Service/LhsLookupService.php` | 186 | Service for LHS matrix lookups. Reads `lhsMatrixCell` records from OpenRegister to resolve the recommended intervention for a given... | procedure logic | C15 decision table recommendation | OpenRegister Dmn |
| `Service/NoticeOfDefaultService.php` | 300 | AWB 4:17 ingebrekestelling registration + DwangsomBerekening creation. | procedure logic | C16 money calculation and payment | OpenRegister Calculation + shillinq |
| `Service/Vth/LhsDecisionTableLookup.php` | 248 | Evaluates the projected LHS decision table. | procedure logic | C15 decision table recommendation | OpenRegister Dmn |
| `Service/Vth/LhsMatrixDecisionTableMigrator.php` | 496 | Project each LHS matrix onto a decision table. The Landelijke Handhavingsstrategie matrix is a three-axis lookup: severity by behaviour... | procedure logic | C15 decision table recommendation | OpenRegister Dmn |
| `Service/Vth/LhsRecommendationService.php` | 361 | LHS recommendation engine. | procedure logic | C15 decision table recommendation | OpenRegister Dmn |
| `Service/Vth/LhsRecommendationStore.php` | 266 | The OpenRegister reads and writes the LHS recommendation engine needs. Split out of {@see LhsRecommendationService} so that class holds... | procedure logic | C15 decision table recommendation | OpenRegister Dmn |

## mandaat

| Class | Lines | What it does | Kind | Capability | Target |
|---|---:|---|---|---|---|
| `Controller/MandaatController.php` | 137 | Controller for mandate validation endpoints. | generic, procedure name | C17 delegated authority check | decidiq mandate registry + dossiq guard |
| `Controller/MandaatMatrixController.php` | 469 | REST surface for the mandaat-matrix backend. | generic, procedure name | C17 delegated authority check | decidiq mandate registry + dossiq guard |
| `Controller/MandaatRegistryController.php` | 174 | Admin CRUD for the mandate decision registries. | generic, procedure name | C17 delegated authority check | decidiq mandate registry + dossiq guard |
| `Service/Beschikking/MandaatVerifier.php` | 237 | Resolves and verifies the mandaat covering a beschikking approval. | generic, procedure name | C17 delegated authority check | decidiq mandate registry + dossiq guard |
| `Service/Mandaat/MandaatCsvParser.php` | 146 | Parses the Decidesk mandaat CSV export and its cell dialects. | generic, procedure name | C17 delegated authority check | decidiq mandate registry + dossiq guard |
| `Service/Mandaat/MandaatRegistryService.php` | 266 | Administrator read/write surface for the mandate-matrix registries. | generic, procedure name | C17 delegated authority check | decidiq mandate registry + dossiq guard |
| `Service/Mandaat/MandaatRepository.php` | 277 | OpenRegister access for MandateringsBesluiten, Mandaten and OrganisatieRollen. | generic, procedure name | C17 delegated authority check | decidiq mandate registry + dossiq guard |
| `Service/MandaatCheckService.php` | 460 | Mandate authorization engine. | generic, procedure name | C17 delegated authority check | decidiq mandate registry + dossiq guard |
| `Service/MandaatEscalatieService.php` | 377 | Mandate escalation lifecycle. | generic, procedure name | C17 delegated authority check | decidiq mandate registry + dossiq guard |
| `Service/MandaatGebruikService.php` | 199 | Immutable audit log for mandate uses. | generic, procedure name | C17 delegated authority check | decidiq mandate registry + dossiq guard |
| `Service/MandaatImportService.php` | 375 | CSV import of a MandateringsBesluit from a Decidesk export. The wire format is parsed by {@see MandaatCsvParser} and every register read... | generic, procedure name | C17 delegated authority check | decidiq mandate registry + dossiq guard |
| `Service/MandaatValidationService.php` | 218 | Mandaatregister authority validator for mandaatbesluiten. | generic, procedure name | C17 delegated authority check | decidiq mandate registry + dossiq guard |
| `Service/Transitions/MandaatGuard.php` | 91 | Guard: verifies signing-official mandate against the mandaatregister. | generic, procedure name | C17 delegated authority check | decidiq mandate registry + dossiq guard |

## brp-kvk-bag

| Class | Lines | What it does | Kind | Capability | Target |
|---|---:|---|---|---|---|
| `AppInfo/Registrar/BagRegistrar.php` | 109 | Registers the BAG address / pand / verblijfsobject port. | standards adapter | - | integriq PropertySource (retire dossiq copy) |
| `AppInfo/Registrar/BrpRegistrar.php` | 88 | Registers the BRP / Haal Centraal personen port. | standards adapter | - | integriq PropertySource (retire dossiq copy) |
| `AppInfo/Registrar/KvkRegistrar.php` | 87 | Registers the KvK Handelsregister port. | standards adapter | - | integriq PropertySource (retire dossiq copy) |
| `Controller/BagController.php` | 238 | Controller for BAG address / pand / verblijfsobject lookups. | standards adapter | - | integriq PropertySource (retire dossiq copy) |
| `Listener/LocationBagValidationListener.php` | 276 | Reject `location` saves whose `source = bag` claim lacks a valid `nummeraanduidingId`. | standards adapter | - | integriq PropertySource (retire dossiq copy) |
| `Service/External/Bag/BagAdapterInterface.php` | 128 | BAG (Basisregistratie Adressen en Gebouwen) lookup port. Implementations MUST be side-effect-free when the dormant flag is set; a... | standards adapter | - | integriq PropertySource (retire dossiq copy) |
| `Service/External/Bag/BagApiAdapter.php` | 377 | Live Kadaster BAG API Individuele Bevragingen v2 adapter (test / live tiers). | standards adapter | - | integriq PropertySource (retire dossiq copy) |
| `Service/External/Bag/BagLookupResult.php` | 63 | Result of a BAG lookup attempt. `lookupStatus` is one of `FOUND`, `NOT_FOUND`, `INVALID_INPUT`, `LOOKUP_DEFERRED`, `LOOKUP_ERROR`. The... | standards adapter | - | integriq PropertySource (retire dossiq copy) |
| `Service/External/Bag/BagResponseMapper.php` | 207 | Normalizes Kadaster BAG API Individuele Bevragingen v2 address / pand / verblijfsobject fragments into the Dossiq-internal DTO shape. | standards adapter | - | integriq PropertySource (retire dossiq copy) |
| `Service/External/Bag/LogBagAdapter.php` | 154 | Dormant log-backed Dossiq BAG adapter. | standards adapter | - | integriq PropertySource (retire dossiq copy) |
| `Service/External/Bag/PdokBagAdapter.php` | 252 | The free PDOK BAG mirror, behind the BAG port. | standards adapter | - | integriq PropertySource (retire dossiq copy) |
| `Service/External/Brp/BrpHaalCentraalAdapterInterface.php` | 112 | BRP / Haal Centraal lookup port. Implementations MUST be side-effect-free when the dormant flag is set; a dormant adapter records the... | standards adapter | - | integriq PropertySource (retire dossiq copy) |
| `Service/External/Brp/BrpLookupResult.php` | 66 | Result of a BRP / Haal Centraal lookup attempt. `lookupStatus` is one of `FOUND`, `NOT_FOUND`, `LOOKUP_DEFERRED`, `LOOKUP_ERROR`. The... | standards adapter | - | integriq PropertySource (retire dossiq copy) |
| `Service/External/Brp/HaalCentraalBrpAdapter.php` | 205 | Live BRP Personen bevragen adapter (mock / proefomgeving tiers). | standards adapter | - | integriq PropertySource (retire dossiq copy) |
| `Service/External/Brp/LogBrpHaalCentraalAdapter.php` | 114 | Dormant log-backed Dossiq BRP / Haal Centraal adapter. | standards adapter | - | integriq PropertySource (retire dossiq copy) |
| `Service/External/Kvk/KvkApiAdapter.php` | 157 | Live KvK Zoeken adapter (test / live tiers). | standards adapter | - | integriq PropertySource (retire dossiq copy) |
| `Service/External/Kvk/KvkHandelsregisterAdapterInterface.php` | 101 | KvK Handelsregister lookup port. Implementations MUST be side-effect-free when the dormant flag is set; a dormant adapter records the... | standards adapter | - | integriq PropertySource (retire dossiq copy) |
| `Service/External/Kvk/KvkLookupResult.php` | 67 | Result of a KvK Handelsregister lookup attempt. `lookupStatus` is one of `FOUND`, `NOT_FOUND`, `LOOKUP_DEFERRED`, `LOOKUP_ERROR`. The... | standards adapter | - | integriq PropertySource (retire dossiq copy) |
| `Service/External/Kvk/LogKvkHandelsregisterAdapter.php` | 103 | Dormant log-backed Dossiq KvK Handelsregister adapter. | standards adapter | - | integriq PropertySource (retire dossiq copy) |
| `Service/Pdok/PdokBagService.php` | 480 | Single ingress for PDOK BAG WFS v2_0 lookups. | standards adapter | - | integriq PropertySource (retire dossiq copy) |

## intake

| Class | Lines | What it does | Kind | Capability | Target |
|---|---:|---|---|---|---|
| `Controller/DsoController.php` | 510 | Controller exposing DSO Omgevingsloket endpoints. | procedure logic | C10 request intake from a form | OpenRegister forms + dossiq Intake core |
| `Controller/PortalWooRequestController.php` | 171 | Starts a Woo request for the resident portaliq vouches for. | procedure logic | C10 request intake from a form | OpenRegister forms + dossiq Intake core |
| `Listener/VergunningaanvraagCreatedListener.php` | 251 | Listens for DSO intake records and creates a DSO zaak for each. Idempotency: repeated events for the same object ID within a single PHP... | procedure logic | C10 request intake from a form | OpenRegister forms + dossiq Intake core |
| `Service/Dso/DsoIntakeCasePayload.php` | 220 | An intake record, to the case payload. | procedure logic | C10 request intake from a form | OpenRegister forms + dossiq Intake core |
| `Service/Dso/DsoObjectRepository.php` | 225 | Loads DSO zaken and samenwerkverzoeken from OpenRegister. | procedure logic | C10 request intake from a form | OpenRegister forms + dossiq Intake core |
| `Service/DsoCaseService.php` | 476 | Service for DSO Omgevingsloket case management. Creates Dossiq zaken from DSO vergunningaanvragen, transitions statuses, and computes... | procedure logic | C10 request intake from a form | OpenRegister forms + dossiq Intake core |
| `SetupCheck/DsoIntakeCheck.php` | 115 | Warns while DSO intake is off. | procedure logic | C10 request intake from a form | OpenRegister forms + dossiq Intake core |
| `Woo/WooRequestForm.php` | 283 | Validates a Woo request and reduces it to what the case keeps. | procedure logic | C10 request intake from a form | OpenRegister forms + dossiq Intake core |
| `Woo/WooRequestIntake.php` | 493 | Opens a Woo request case for a resident, optionally from their dossier. | procedure logic | C10 request intake from a form | OpenRegister forms + dossiq Intake core |
| `Woo/WooRequestRefused.php` | 89 | A Woo request that was refused, with the reason as a code. | procedure logic | C10 request intake from a form | OpenRegister forms + dossiq Intake core |
| `Woo/WooRequesterProperties.php` | 174 | The requester details as entries of a case's `properties` bag. | procedure logic | C10 request intake from a form | OpenRegister forms + dossiq Intake core |
| `Woo/WooWrittenCase.php` | 128 | The id, number and deadline of a case that was just written. | procedure logic | C10 request intake from a form | OpenRegister forms + dossiq Intake core |

## migration

| Class | Lines | What it does | Kind | Capability | Target |
|---|---:|---|---|---|---|
| `Command/Backfill/AwbProceedingScanner.php` | 288 | Finds the cases that still carry an open Awb proceeding. | one-off migration | - | retire after it has run on every instance |
| `Command/MigrateBesluitenToDecidiqCommand.php` | 149 | Migrate this app's besluiten onto decidiq's `Decision` schema. | one-off migration | - | retire after it has run on every instance |
| `Repair/BackfillAdviceRequestObjection.php` | 202 | Copy the legacy objection key onto the schema-declared one. | one-off migration | - | retire after it has run on every instance |
| `Repair/CopyEmbeddedBezwaarAuditTrail.php` | 259 | Copies every embedded bezwaar audit entry onto its record's OpenRegister trail, once. | one-off migration | - | retire after it has run on every instance |
| `Repair/MapWooRefusalGroundCodes.php` | 277 | Maps stored grounds once per row, marked, idempotent and non-fatal. | one-off migration | - | retire after it has run on every instance |
| `Repair/MigrateCommitteesToDecidiq.php` | 266 | Raises a GovernanceBody per local committee and records the mapping. | one-off migration | - | retire after it has run on every instance |
| `Repair/RewriteWooPublicationSummaries.php` | 308 | Rewrites "WOO besluit voor zaak <uuid>" to the summary new decisions carry. | one-off migration | - | retire after it has run on every instance |
| `Service/BesluitMigrationService.php` | 569 | Migrates besluiten from this app's `decision` schema onto decidiq's. | one-off migration | - | retire after it has run on every instance |

## subsidie

| Class | Lines | What it does | Kind | Capability | Target |
|---|---:|---|---|---|---|
| `Controller/SubsidieController.php` | 389 | Controller exposing the subsidy lifecycle endpoints. | procedure logic | C26 case type + flow (no code) | case definition package |
| `Controller/SubsidieRegisterController.php` | 203 | Public subsidieregister feed controller. | procedure logic | C09 publication of an outcome | dossiq publisher over opencatalogi |
| `Service/Subsidie/BeschikkingService.php` | 319 | Grant-decision drafting, validation, signing and publication. | procedure logic | C03 decision document | dossiq decision-document core |
| `Service/Subsidie/BewijsstukService.php` | 251 | Evidence document upload, retention, hashing and immutability. | procedure logic | C04 frozen record | OpenRegister seal + dossiq core |
| `Service/Subsidie/StaatssteunClassifier.php` | 169 | Pure EU state-aid classification helpers. | procedure logic | C15 decision table recommendation | OpenRegister Dmn |
| `Service/Subsidie/SubsidieRegisterExporter.php` | 127 | Wet open overheid subsidieregister feed builder. | procedure logic | C09 publication of an outcome | dossiq publisher over opencatalogi |
| `Service/Subsidie/SubsidieService.php` | 473 | Core subsidy lifecycle service. | procedure logic | C26 case type + flow (no code) | case definition package |
| `Service/Subsidie/TussenrapportageService.php` | 288 | Interim-report cadence, termijn binding and approval. | procedure logic | C20 periodic obligation | dossiq Obligations core |

## money

| Class | Lines | What it does | Kind | Capability | Target |
|---|---:|---|---|---|---|
| `Controller/DwangsomPaymentCallbackController.php` | 202 | Public webhook endpoint for dwangsom payment confirmation callbacks. | procedure logic | C16 money calculation and payment | OpenRegister Calculation + shillinq |
| `Service/DwangsomCalculationService.php` | 472 | Daily-accruing dwangsom calculator. | procedure logic | C16 money calculation and payment | OpenRegister Calculation + shillinq |
| `Service/DwangsomUitbetalingService.php` | 381 | Payment-signal preparation + callback processing for dwangsom payouts. | procedure logic | C16 money calculation and payment | OpenRegister Calculation + shillinq |
| `Service/Subsidie/CofinancieringValidator.php` | 138 | Pure co-financing reconciliation and EU-detection helpers. | procedure logic | C16 money calculation and payment | OpenRegister Calculation + shillinq |
| `Service/Subsidie/TerugvorderingService.php` | 228 | Clawback lifecycle and invorderingsrente service. | procedure logic | C16 money calculation and payment | OpenRegister Calculation + shillinq |
| `Service/Subsidie/VaststellingService.php` | 232 | Settlement math and terugvordering trigger. | procedure logic | C16 money calculation and payment | OpenRegister Calculation + shillinq |

## events

| Class | Lines | What it does | Kind | Capability | Target |
|---|---:|---|---|---|---|
| `AppInfo/Registrar/BezwaarListenerRegistrar.php` | 93 | Registers the unnarrowed bezwaar event listeners. | procedure logic | C24 case event from a flow | OpenRegister flow events |
| `AppInfo/Registrar/BezwaarSubscriptionRegistrar.php` | 193 | Subscribes the narrowed bezwaar listeners once every app has registered. | procedure logic | C24 case event from a flow | OpenRegister flow events |
| `Event/VergunningStatusChangedEvent.php` | 122 | Event raised after each vergunningaanvraag status transition. | procedure logic | C24 case event from a flow | OpenRegister flow events |
| `Listener/BezwaarLifecycleListener.php` | 175 | Routes bezwaar/objection/hearing/advisory/decision events onto the status-transition-engine without owning any transition logic itself. | procedure logic | C24 case event from a flow | OpenRegister flow events |
| `Service/Dso/DsoDoorsturenNotifier.php` | 96 | Emits the VergunningDoorgestuurd event for downstream listeners. | procedure logic | C24 case event from a flow | OpenRegister flow events |
| `Service/Dso/DsoStatusChangeNotifier.php` | 88 | Emits the VergunningStatusChanged event for downstream listeners. | procedure logic | C24 case event from a flow | OpenRegister flow events |

## digid-eherkenning

| Class | Lines | What it does | Kind | Capability | Target |
|---|---:|---|---|---|---|
| `Service/Auth/DigidSamlAdapterInterface.php` | 80 | Contract for the DigiD broker SAML adapter. Activation requirements (documented for the operator): 1. openconnector DigiD broker entry... | standards adapter | - | integriq or portaliq auth |
| `Service/Auth/EHerkenningSamlAdapterInterface.php` | 81 | Contract for the eHerkenning broker SAML adapter. Activation requirements (documented for the operator): 1. openconnector eHerkenning... | standards adapter | - | integriq or portaliq auth |
| `Service/Auth/LogDigidSamlAdapter.php` | 112 | Default DigiD adapter , logs + refuses. | standards adapter | - | integriq or portaliq auth |
| `Service/Auth/LogEHerkenningSamlAdapter.php` | 113 | Default eHerkenning adapter , logs + refuses. | standards adapter | - | integriq or portaliq auth |
| `Service/Auth/SimulatorDigidSamlAdapter.php` | 98 | Local DigiD login simulator , no real SAML (capped at beta). | standards adapter | - | integriq or portaliq auth |
| `Service/Auth/SimulatorEHerkenningSamlAdapter.php` | 94 | Local eHerkenning login simulator , no real SAML (capped at beta). | standards adapter | - | integriq or portaliq auth |

## Procedure logic in generic-named classes

PHP classes outside the table above with 10 or more procedure terms in their body. Each lane that moves the procedure also clears its terms from these classes.

| Class | Terms |
|---|---:|
| `Portal/CitizenManifest.php` | 65 |
| `Listener/DecisionConcludedListener.php` | 44 |
| `Repair/RenameDutchColumns.php` | 39 |
| `Service/DeadlineExtensionService.php` | 33 |
| `Service/DeadlinePauseService.php` | 32 |
| `Service/Settings/ConfigKeys.php` | 28 |
| `Service/StateMachineService.php` | 27 |
| `Notification/Notifier.php` | 27 |
| `Listener/DeadlineCaseCreatedListener.php` | 27 |
| `Service/SettingsService.php` | 25 |
| `Service/Settings/SchemaSlugMap.php` | 25 |
| `Service/CaseTermsService.php` | 23 |
| `Service/Pause/PauseChaseService.php` | 21 |
| `Service/DeadlineEscalationService.php` | 20 |
| `AppInfo/Registrar/WorkflowListenerRegistrar.php` | 19 |
| `Service/Term/TermStatusClock.php` | 17 |
| `Service/DeadlineReportingService.php` | 17 |
| `Service/DeadlineMonitoringSeedDataService.php` | 17 |
| `Repair/SeedDeadlineMonitoringData.php` | 17 |
| `Portal/PortalContributionProvider.php` | 16 |
| `Service/Pause/ChaseSchedule.php` | 15 |
| `Repair/LinkInFlightContractDecisionsRepair.php` | 15 |
| `Service/Timeline/TermEventEntry.php` | 14 |
| `Service/DossierCompiler.php` | 14 |
| `Repair/RenameDutchValueDecisions.php` | 14 |
| `Repair/LinkInFlightRemainingDecisionsRepair.php` | 14 |
| `Controller/CaseTermsController.php` | 14 |
| `AppInfo/Registrar/DeadlineTimerRegistrar.php` | 14 |
| `Service/WorkQueueService.php` | 12 |
| `Service/ServiceAccount/ServiceAccount.php` | 12 |
| `Service/Notification/ApplicantMessage.php` | 12 |
| `Service/InformationRequestService.php` | 12 |
| `Service/FilinqRedactionClient.php` | 12 |
| `AppInfo/Registrar/SubstitutableAdapterRegistrar.php` | 12 |
| `Service/PublicationService.php` | 11 |
| `Service/AcknowledgementService.php` | 11 |
| `BackgroundJob/DeadlineNotificationDispatchJob.php` | 11 |
| `AppInfo/Registrar/IntakeListenerRegistrar.php` | 11 |
| `Service/TermKind.php` | 10 |
| `Service/Term/DependentTermOffer.php` | 10 |
| `Service/SamenwerkverzoekService.php` | 10 |
| `Service/CaseLifecycleService.php` | 10 |
| `Service/AanvullingsverzoekService.php` | 10 |
| `Service/AanvullingsverzoekResolutionService.php` | 10 |
| `Repair/RenameDutchDeadlineColumns.php` | 10 |
| `Controller/DeadlineReportingController.php` | 10 |

## Procedure-named files in src/

- `src/components/InspectionChecklistEditor.vue`
- `src/components/besluitvorming/BesluitPublicatiePanel.vue`
- `src/components/tabs/BesluitvormingLeafTab.vue`
- `src/dialogs/BeschikkingComposerDialog.vue`
- `src/dialogs/StufEnvelopeDialog.vue`
- `src/dialogs/ZgwMappingDialog.vue`
- `src/manifest.d/50-subsidie.json`
- `src/modals/MandaatEditor.vue`
- `src/modals/TermijnDefinitieEditor.vue`
- `src/services/bagApi.js`
- `src/services/beschikkingApi.js`
- `src/services/besluitvormingApi.js`
- `src/services/stufApi.js`
- `src/store/modules/bezwaar.js`
- `src/store/modules/inspection.js`
- `src/store/modules/termijnDashboard.js`
- `src/store/modules/zgwMapping.js`
- `src/views/cases/components/InspectionChecklistPanel.vue`
- `src/views/cases/components/InspectionPanel.vue`
- `src/views/dashboard/WooDeadlinePanel.vue`
- `src/views/doorlooptijd/widgets/DtWooWidget.vue`
- `src/views/settings/StufAuditLog.vue`
- `src/views/settings/StufEndpoints.vue`
- `src/views/settings/ZgwMappingSettings.vue`
- `src/views/settings/components/MandaatImportPanel.vue`
- `src/views/settings/components/MandaatMatrixTable.vue`
- `src/views/settings/components/MandaatToewijzingenTable.vue`
- `src/views/settings/tabs/MandaatMatrixSettingsTab.vue`
- `src/views/settings/tabs/MandaatMatrixTab.vue`
- `src/views/settings/tabs/TermijnDefinitiesTab.vue`
- `src/views/termijn/TdAnnualWidget.vue`
- `src/views/termijn/TdCaseTypeFilter.vue`
- `src/views/termijn/TdKpiWidget.vue`
- `src/views/termijn/TdQuarterlyWidget.vue`
- `src/views/termijn/tdWidgetMixin.js`
