# flow-nodes-to-their-owners Specification

## Purpose
dossiq contributes flow steps only for what a case alone can do: set a status, create a subcase or task, ask a person, publish a decision. Mail, notifications, field writes, decision tables, documents, decisions and outbound calls belong to OpenRegister, Filinq, Decidiq and Integriq. A stored flow or declared action that names a retired dossiq step is rewritten to, or runs as, the owner's step, and what the owner did for a case is filed on that case.

## Requirements

### Requirement: REQ-FNO-001 dossiq offers flow steps only for what no other app owns

dossiq SHALL NOT contribute flow nodes for sending mail, sending
notifications, writing object fields, evaluating decision tables, generating
documents, raising decisions or calling webhooks. It SHALL contribute
`dossiq.setStatus`, `dossiq.createTask`, `dossiq.createSubCase`,
`dossiq.askPerson`, `dossiq.ensureCommittee` and
`dossiq.besluitvormingPublish`.

#### Scenario: The catalogue carries no copy of another app's step
@e2e exclude The node catalogue is backend registration; DossiqFlowNodeListenerTest asserts it against the real listener.

- **WHEN** OpenRegister collects the flow node catalogue
- **THEN** dossiq SHALL register its six case steps
- **AND** none of `dossiq.sendEmail`, `dossiq.webhook`, `dossiq.notify`, `dossiq.setField`, `dossiq.evaluateDecision`, `dossiq.requestDecision` or any `dossiq.action.*` type SHALL be registered

### Requirement: REQ-FNO-002 A stored flow naming a retired step is rewritten to its replacement

On upgrade, every dossiq flow that names a retired step SHALL be rewritten:
the step SHALL become the replacement step or steps with its configuration
translated, a published flow SHALL be rewritten through a new version, and
every change SHALL be logged naming the flow and the step. A step whose
configuration has no faithful equivalent SHALL stay in place, unchanged, and
be logged as a warning naming the flow, the step and why.

#### Scenario: A mail step becomes OpenRegister's
@e2e exclude Repair-step rewrite runs under occ upgrade; RetiredNodeTranslatorTest and RewriteRetiredFlowNodesTest cover it.

- **GIVEN** a published flow with a `dossiq.action.sendEmail` step, recipient `indiener`, body `Beste {{case.title}}`
- **WHEN** the upgrade runs
- **THEN** the step SHALL be `openregister.send-email` with recipients `{{ indiener }}`, body `Beste {{ title }}` and `externalRecipients: object`
- **AND** the flow SHALL be published again

#### Scenario: A field write becomes a compute and an object write
@e2e exclude Repair-step rewrite runs under occ upgrade; RetiredNodeTranslatorTest covers the chain.

- **GIVEN** a flow with a `dossiq.setField` step writing `closedAt` as `__now__`
- **WHEN** the upgrade runs
- **THEN** the step SHALL become an `openregister.set-fields` step computing `closedAt` as `now`, followed by an `openregister.object-write` update of `closedAt` on the case
- **AND** the step's outgoing edges SHALL leave from the second step

#### Scenario: A webhook step Integriq gives no source for is reported and left
@e2e exclude Repair-step rewrite runs under occ upgrade; RewriteRetiredFlowNodesTest covers it.

- **GIVEN** a flow with a `dossiq.webhook` step calling `https://hooks.example.org/x`
- **AND** Integriq does not answer with a source for `https://hooks.example.org` (the mapping itself is change `webhook-steps-through-integriq`)
- **WHEN** the upgrade runs
- **THEN** the step SHALL be unchanged
- **AND** a warning SHALL name the flow, the step and `hooks.example.org`

#### Scenario: A decision request is renamed
@e2e exclude Repair-step rewrite runs under occ upgrade; RetiredNodeTranslatorTest covers the row.

- **GIVEN** a flow with a `dossiq.requestDecision` step
- **WHEN** the upgrade runs
- **THEN** the step SHALL be `decidiq.request-decision` with the same configuration

### Requirement: REQ-FNO-003 A declared action of a retired type keeps running as its replacement

A transition's `automaticActions` entry or a task effect of type `sendEmail`,
`notify`, `setField` or `evaluateDecision` SHALL run as its translated
replacement steps, with the acting user of the transition as the run's
identity. A declared `webhook` SHALL run as Integriq's source call (change
`webhook-steps-through-integriq`), and SHALL be reported as unable to run, with
the reason, when it cannot be mapped. A failure SHALL be a failed result row and SHALL NOT roll back the
transition.

#### Scenario: A declared notification runs as OpenRegister's
@e2e exclude The transition side-effect path is backend; SideEffectDispatcherTest covers it with the real runner and translator.

- **GIVEN** a transition "Afronden" declaring `{type: notify, message: "Uw bezwaar is afgehandeld"}`
- **WHEN** a user moves a case through it
- **THEN** OpenRegister's send-notification step SHALL run for the case's assignee, titled with the transition's label, as that user

### Requirement: REQ-FNO-004 What the owners did for a case is filed on the case

A mail an OpenRegister flow sent about a dossiq case SHALL be stored on the
case and shown on its timeline. A document Filinq generated for a dossiq case
whose request names a document type SHALL be filed in the case dossier as an
informatieobject with that type and the case's addressees. A decision Decidiq's
flow step concluded about a dossiq case SHALL become the case's ZGW besluit.

#### Scenario: A flow mail shows on the case
@e2e exclude Needs a live OpenRegister flow sending mail; FlowEmailSentListenerTest covers the listener with the real event.

- **GIVEN** a flow that mails `indiener@example.org` about case C
- **WHEN** the mail is sent
- **THEN** case C SHALL hold the sent message and a mail line on its timeline naming the recipient

#### Scenario: A generated letter lands in the dossier
@e2e exclude Needs Filinq installed; DocumentGeneratedListenerTest and GeneratedDocumentFilerTest cover it with the real event.

- **GIVEN** a flow step generating a document for case C with document type T
- **WHEN** Filinq stores the file
- **THEN** case C's dossier SHALL hold an informatieobject of type T for that file

#### Scenario: A document for another app's object is left alone
@e2e exclude Covered by DocumentGeneratedListenerTest.

- **GIVEN** Filinq generates a document for an object that is not a dossiq case
- **THEN** dossiq SHALL file nothing

#### Scenario: A flow decision becomes the besluit
@e2e exclude Needs Decidiq installed; DecisionConcludedListenerTest covers it with the real event.

- **GIVEN** Decidiq concludes a decision raised with source `decidiq-flow` whose subject is case C
- **THEN** case C's ZGW besluit SHALL be materialised from it
- **AND** dossiq SHALL NOT signal the waiting run, which Decidiq does

### Requirement: REQ-FNO-005 The Generate document button asks Filinq

The Generate document button SHALL ask Filinq for the document by dispatching
DocumentGenerationRequestedEvent with requestingApp `dossiq`, and SHALL file
the result on the case. It SHALL refuse, creating nothing, when the template
names a case field the case does not hold. When nobody handles the request it
SHALL refuse with a message saying Filinq is required.

#### Scenario: Without Filinq the button says so
@e2e exclude The e2e environment runs with Filinq; CaseDocumentGenerationServiceTest covers the refusal.

- **GIVEN** Filinq is not installed
- **WHEN** a user generates a document on a case
- **THEN** the request SHALL fail with a message that generating a document needs Filinq
- **AND** nothing SHALL be filed
