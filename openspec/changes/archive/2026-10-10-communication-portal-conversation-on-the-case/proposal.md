---
kind: code
depends_on: []
---

# Proposal: communication-portal-conversation-on-the-case

OpenSpec pass of 2026-09-27. Four rows, three in the portaliq matrix
(`ConductionNL/portaliq`, `openspec/parity/capabilities.json`) with `built.owner`
`ConductionNL/dossiq`, and dossiq's own row 6.7. They share one service,
dossiq's citizen contribution in `lib/Portal/PortalContributionProvider.php`,
and one conversation: the messages between a resident and the handler of their
case.

| matrix | row | capability | own rating | built.state |
| --- | --- | --- | --- | --- |
| portaliq | `cmp-act-ask-question-case` | Ask a question about one of your cases from the case page and find the answer there later. | partial | built |
| portaliq | `dem-tnd-portal-talk-on-case` | Have the messages you and the organisation exchange in the portal filed on the case automatically. | partial | built |
| portaliq | `sib-dossiq-6-7` | See dossiq case messages (berichtenbox) in the shared portal inbox. | partial | built |
| dossiq | 6.7 | Messaging with citizens through a portal | partial | built |

## Why

The messages exist on dossiq's schema, `portaalBericht`
(`lib/Settings/register.d/50-zaakportaal.json`), and each carries its case. The
conversation still does not work, from either end.

- **The resident sees only a subject.** portaliq's inbox reads `body`,
  `receivedAt` and `read`; dossiq writes `content`, `sentAt` and
  `readByRecipientAt`. The portaliq row's note: "dossiq writes content, sentAt
  and readByRecipientAt, portaliq's inbox reads body, receivedAt and read, so
  only the subject shows (portaliq#702)". Attachments ride in the row and never
  render.
- **The resident types a uuid to reply.** The note on
  `dem-tnd-portal-talk-on-case`: "the reply form shows caseId as a free text
  field (no optionsProvider declared), so the resident must type the case uuid,
  and there is no reply-from-this-message or reply-from-the-case-page control."
- **The resident cannot ask from the case.** The note on
  `cmp-act-ask-question-case`: "A resident can send a message tied to one of
  their cases, but from the messages page with the case uuid typed by hand, not
  from the case page, and the answer shows in the inbox, not on the case."
- **The handler never sees it.** Nothing under `src/` reads or writes
  `portaalBericht`. A resident's message is stored with its case and shown to
  nobody, and a handler has no way to answer one from the case.

Demand: a tender row on `dem-tnd-portal-talk-on-case`,
https://www.tenderned.nl/aankondigingen/overzicht/397282 (Gemeente Molenlanden:
"Via het portaal gevoerde communicatie wordt door de Oplossing automatisch
toegevoegd aan de zaak").

Competitors rated yes, quoted from the matrices:

- On `cmp-act-ask-question-case`: Open Inwoner,
  `src/open_inwoner/cms/cases/views/status.py:897 CaseContactFormView; questions
  shown at src/open_inwoner/cms/cases/views/status.py:277` (origin
  https://github.com/maykinmedia/open-inwoner/blob/v2.4.3/src/open_inwoner/cms/cases/tests/test_contactform.py#L421);
  xxllnc PIP, `backend/perl-api/root/tpl/zaak_v1/nl_NL/zaak/elements/messages.tt:7
  'Nieuw bericht' with caseUuid; messages listed on the case page :14`.
- On `sib-dossiq-6-7`: nl-portal,
  `backend/zgw/berichten/src/main/kotlin/nl/nlportal/berichten/service/BerichtenService.kt:132
  berichten linked by referentie`; xxllnc PIP,
  `backend/perl-api/root/tpl/zaak_v1/nl_NL/plugins/pip/case/view.tt:69 case messages`.
- On `dem-tnd-portal-talk-on-case`: xxllnc PIP,
  `frontend-mono/packages/communication-module/src/components/MessageForm/Pip/Pip.formDefinition.ts:52
  case is required for a PIP message`.
- On 6.7: zaaksysteem, `frontend-mono/apps/my-pip/src/modules/communication/index.tsx:8,14
  citizen PIP mounts the communication module`.

## What changes

- `portaalBericht` speaks the portal inbox's vocabulary: `body`, `receivedAt`
  and `read`, projected on the `berichten` inbox collection.
- Attachments are files in the message's own folder, downloadable from the
  inbox and attachable to a reply.
- The inbox declares its reply, carrying the case from the message, and the
  reply form offers the resident's own cases as a choice, never a uuid.
- The case detail in the portal offers "Ask a question about this case" and
  lists the messages about that case.
- A resident's message lands on the case timeline for the handler, with a
  follow-up, and the handler answers from the case. The answer reaches the
  resident's inbox and the case detail.

## What this change does not do

- It does not touch Berichtenbox (MijnOverheid) digital post. That is integriq's
  channel and `BerichtenboxService`'s, and stays separate.
- It does not add the other dossiq halves portaliq's lane named for its own rows:
  `kind: cases` and `closedField` on `mijnZaken`, a `citizenWrite` update action,
  a `caseDocuments` method, a `PortalClientWriteEvent` listener. Those belong to
  portaliq-owned rows and are recorded in the OpenSpec pass hand-back.

## Sibling halves

- **ConductionNL/portaliq**, change `inbox-reply-with-attachments` (open): the
  `reply` declaration on an inbox collection, the reply route that carries fields
  from the message, and `_files` on inbox rows. It names this dossiq half: "the
  `reply` declaration on its `berichten` inbox collection, naming
  `replyToMessage` and carrying `caseId`; a `type: file` entry for `attachments`
  in `replyToMessage`'s `fieldConfigs`; `filesDownload: true` on `berichten`".
- **ConductionNL/portaliq**, to be specified: a detail action that starts a
  create action with a field taken from the detail row, and a messages block on
  a collection detail. Nearest: `rowActions` on detail blocks
  (`guardian-self-service-profile`) and the case detail timeline.
- **ConductionNL/openregister**: timeline entries as records
  (`timeline-entries-are-records`, merged), consumed through dossiq's open
  `one-timeline-on-the-case`, which already declares the `portaalbericht` kind.

## Capabilities

- Modified: `portal-contribution`: four requirements added.

## Impact

`lib/Portal/PortalContributionProvider.php` (`berichten`, `replyToMessage`, a
new `askAboutCase` action, a `caseMessages` provider on `mijnZaken`),
`lib/Settings/register.d/50-zaakportaal.json` (`portaalBericht`), one listener
under `lib/Listener` for the timeline entry, one dialog and one header action on
`#CaseDetail`, a repair step for existing attachments.
