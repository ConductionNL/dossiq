## 1. Schema Definitions

- [x] 1.1 Add `objection` schema to `procest_register.json` with all properties from bezwaar-lifecycle spec (case, contestedDecision, grounds, requestedRelief, receivedDate, receivedChannel, isTimely, timelinessAssessment, proVoorziening, attachments)
- [x] 1.2 Add `hearingSession` schema to `procest_register.json` with all properties from bezwaar-hearing spec (case, scheduledDate, location, videoCallUrl, chairperson, members, invitees, minutesSummary, minutesDocument, status, hearingWaived, waiverReason)
- [x] 1.3 Add `advisoryReport` schema to `procest_register.json` with all properties from bezwaar-advisory-committee spec (case, hearingSession, committeeChair, committeeMembers, adviceDate, adviceType, summary, grounds, recommendation, deviationFromPrimaryDecision, reportDocument)
- [x] 1.4 Add `appealDecision` schema to `procest_register.json` with all properties from bezwaar-decision spec (case, contestedDecision, advisoryReport, dispositionType, dispositionDetails, followsAdvice, deviationReason, remedialAction, replacementDecision, decisionDate, effectiveDate, appealInformation, decisionMaker, decisionDocument)

## 2. Pre-Seeded Case Types and Status Types

- [x] 2.1 Add Bezwaar case type definition to `procest_register.json` with AWB-compliant properties (P6W deadline, extension P6W, suspension allowed, external origin)
- [x] 2.2 Add 10 Bezwaar status types to `procest_register.json` (Ontvangen, Ontvankelijkheidstoets, In behandeling, Hoorzitting gepland, Hoorzitting afgerond, Advies uitgebracht, Beslissing op bezwaar, Afgehandeld, Niet-ontvankelijk, Ingetrokken)
- [x] 2.3 Add 7 Bezwaar role types to `procest_register.json` (Bezwaarmaker, Behandelaar bezwaar, Voorzitter commissie, Lid commissie, Secretaris commissie, Vertegenwoordiger, Primair beslisser)
- [x] 2.4 Add Beroep case type definition to `procest_register.json` with properties (P26W deadline, no extension, suspension allowed, external origin)
- [x] 2.5 Add 9 Beroep status types to `procest_register.json` (Beroep ontvangen, Verweerschrift in voorbereiding, Verweerschrift ingediend, Zitting gepland, Zitting afgerond, Uitspraak ontvangen, Afgehandeld, Ingetrokken, Schikking)

## 3. Pre-Seeded Workflow Templates

- [x] 3.1 Add Bezwaar workflow template to `procest_register.json` with all transitions from workflow-definition-model spec (11 transitions with guards)
- [x] 3.2 Add Bezwaar workflow steps per status phase to the workflow template (7 phases, ~20 steps total)
- [x] 3.3 Add Beroep workflow template to `procest_register.json` with all transitions from workflow-definition-model spec (8 transitions)
- [x] 3.4 Add automatic actions to bezwaar workflow transitions (deadline warning notifications, hearing invitations, decision notifications)

## 4. Frontend Store

- [x] 4.1 Create `src/store/modules/bezwaar.js` Pinia store with CRUD operations for objection objects (list, get, create, update, delete via OpenRegister API)
- [x] 4.2 Add hearing session CRUD operations to bezwaar store (create, update, list by case)
- [x] 4.3 Add advisory report CRUD operations to bezwaar store (create, update, get by case)
- [x] 4.4 Add appeal decision CRUD operations to bezwaar store (create, update, get by case)
- [x] 4.5 Add deadline calculation logic to bezwaar store (compute afhandelDeadline, ontvangstbevestigingDeadline, handle extension/suspension)
- [x] 4.6 Add escalation action to bezwaar store (create beroep case from bezwaar with pre-filled data)
- [x] 4.7 Register bezwaar store module in `src/store/store.js`

## 5. Frontend Components — Bezwaar

- [x] 5.1 Create `src/components/bezwaar/BezwaarIntakeForm.vue` — objection intake form with contested decision selector, grounds, received date/channel, timeliness check
- [x] 5.2 Create `src/components/bezwaar/HearingPanel.vue` — hearing session management with scheduling, invitation trigger, minutes recording, waiver option
- [x] 5.3 Create `src/components/bezwaar/AdvisoryReportPanel.vue` — committee advisory report form with advice type selector, grounds, recommendation, committee composition
- [x] 5.4 Create `src/components/bezwaar/BezwaarDecisionForm.vue` — decision on objection form with disposition type, motivation, follows-advice toggle, rechtsmiddelenclausule, reformatio in peius warning
- [x] 5.5 Create `src/components/bezwaar/BezwaarTimeline.vue` — bezwaar-specific timeline showing legal deadlines, hearing dates, advisory dates, decision date alongside case status transitions
- [x] 5.6 Create `src/components/bezwaar/DeadlineIndicator.vue` — visual deadline indicator showing days remaining, at-risk/overdue state, suspension status

## 6. Frontend Components — Beroep

- [x] 6.1 Create `src/components/beroep/BeroepEscalationPanel.vue` — panel to create beroep case from bezwaar with pre-filled data, voorlopige voorziening flag
- [x] 6.2 Create `src/components/beroep/CourtProceedingsPanel.vue` — verweerschrift upload, zitting tracking, uitspraak recording with outcome type

## 7. Case Detail Integration

- [x] 7.1 Add conditional rendering in case detail view to show bezwaar-specific sections when case type is "Bezwaar" (intake form, hearing panel, advisory report, decision form, timeline)
- [x] 7.2 Add conditional rendering in case detail view to show beroep-specific sections when case type is "Beroep" (court proceedings panel)
- [x] 7.3 Add bezwaar-to-beroep escalation link in bezwaar case detail when status is "Beslissing op bezwaar" or "Afgehandeld"
- [x] 7.4 Add parent case link display in beroep case detail showing the originating bezwaar case

## 8. Validation and Guards

- [x] 8.1 Add timeliness validation in BezwaarIntakeForm — auto-calculate whether bezwaar was filed within 6-week term, display warning when late
- [x] 8.2 Add hearing waiver validation — prevent waiver after hearing is completed
- [x] 8.3 Add advisory report committee composition recommendation — display warning when fewer than 3 members
- [x] 8.4 Add committee conflict-of-interest warning — detect when a committee member was the original decision maker
- [x] 8.5 Add decision form validation — require deviationReason when followsAdvice is false, require rechtsmiddelenclausule, display reformatio in peius warning
- [x] 8.6 Add deadline warning logic — trigger notification when bezwaar case is within 5 working days of afhandelDeadline

## 9. Testing and Verification

- [x] 9.1 Verify all 4 new schemas are imported correctly via repair step (create objects, validate required fields)
- [x] 9.2 Verify Bezwaar and Beroep case types are seeded with correct status types, role types
- [x] 9.3 Verify workflow templates are seeded and functional (transitions, guards, steps)
- [x] 9.4 Test complete bezwaar happy path: intake -> ontvankelijkheidstoets -> hoorzitting -> advies -> beslissing -> afgehandeld
- [x] 9.5 Test bezwaar path with hearing waiver: intake -> ontvankelijkheidstoets -> direct to advies/beslissing
- [x] 9.6 Test bezwaar-to-beroep escalation: create beroep from completed bezwaar, verify data pre-fill
- [x] 9.7 Test deadline calculation: verify 6-week deadline, extension, suspension/resume
- [x] 9.8 Verify pre-seeded data is not duplicated on repair step re-run
