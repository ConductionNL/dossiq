# Design: site-woo-request-in-steps

Read at dossiq `development` `59217bc9a` and portaliq `development` `b150def5` on 2026-10-02.

## D1. The steps, as the action declares them

The shape is portaliq's (`site-multi-step-forms` REQ-SMF-020, portaliq#1110):
`steps: [{id, title, description?, fields[], review?}]`. A step is kept only when every field it
names is one of the action's fields. The review step carries `review: true` and no fields.
REQ-SMF-020 covers a create action and an endpoint action with `fields`, which both Woo
actions are; its scenario "The Woo endpoint action runs in steps" names `startWooVerzoek`.
REQ-SMF-021 covers the draft and REQ-SMF-022 the confirmation, for both kinds of action.

```php
'steps' => [
  ['id' => 'vraag', 'title' => 'Uw vraag',
   'description' => 'Vertel ons waar uw vraag over gaat.',
   'fields' => ['onderwerp', 'omschrijving']],
  ['id' => 'periode', 'title' => 'Periode en documenten',
   'description' => 'Hoe preciezer uw vraag, hoe sneller u antwoord krijgt.',
   'fields' => ['periodeVan', 'periodeTot', 'documentSoorten', 'toelichting']],
  ['id' => 'gegevens', 'title' => 'Uw gegevens',
   'description' => 'Wij gebruiken deze gegevens alleen voor uw verzoek.',
   'fields' => ['verzoekerNaam', 'verzoekerEmail', 'verzoekerType']],
  ['id' => 'controleren', 'title' => 'Controleren en versturen', 'review' => true],
],
```

Field configs, from the mockup. The column "Mockup wants" is what `DossiqWoo.dc.html` shows;
see "Required fields: an open decision" below for what the form can actually enforce.

| Field | Label | Mockup wants | Server |
| --- | --- | --- | --- |
| `onderwerp` | Waar gaat uw verzoek over? | yes | required (unchanged) |
| `omschrijving` | Welke informatie wilt u hebben? | yes | optional (unchanged) |
| `periodeVan` | Vanaf welke datum zoekt u informatie? | yes | optional (unchanged) |
| `periodeTot` | Tot en met welke datum? (niet verplicht) | no | optional; not before `periodeVan` (unchanged) |
| `documentSoorten` | Welke documenten zoekt u? | yes, one or more | optional; each value in the enum |
| `toelichting` | Wilt u nog iets toelichten? (niet verplicht) | no | optional |
| `verzoekerNaam` | Uw naam | yes, prefilled from the profile | optional |
| `verzoekerEmail` | Uw e-mailadres | yes, prefilled from the profile | optional; a valid address |
| `verzoekerType` | U vraagt dit als | no | optional; burger, journalist or organisatie |

### Required fields: an open decision

The mockup makes `omschrijving`, `periodeVan`, `documentSoorten`, `verzoekerNaam` and
`verzoekerEmail` required. The server keeps them optional, because pipelinq calls `start()` too
and an employee converting a phone call may not have every answer.

The fact, from portaliq REQ-SMF-023: `required` is NOT honoured on an action without a
schema. Both Woo actions name no schema, so portaliq drops every
`fieldConfigs.<field>.required: true` on them and the site marks every field "(niet
verplicht)", `onderwerp` included. Portaliq keeps its data-minimisation rule (`supplier-portal`,
"Form data minimisation") and leaves lifting it for a route that checks its own input to Ruben.

So this change declares no `required` at all. A `required` key here would be dropped and would
read as a promise the form does not keep. The server still refuses a request without
`onderwerp`; the resident then sees that refusal in the error summary after sending.

The choice for Ruben (tasks 0.1):

1. Accept it: every field reads "(niet verplicht)" and the server checks only `onderwerp`.
2. Make the five fields required on dossiq's portal route (`PortalWooRequestController`
   refuses a portal request without them, `start()` stays lenient for pipelinq), and lift
   portaliq's rule for an action whose route checks its own input, so the form can mark them.
   Doing only the first half would make the form say "(niet verplicht)" about fields the
   server then refuses.

`documentSoorten` values and their labels: `besluiten` "Besluiten en vergunningen",
`rapporten` "Rapporten en adviezen", `correspondentie` "E-mails en brieven", `alles` "Alles wat
de gemeente hierover heeft".

## D2. Two doors, one route

| Action | Shown | `collectionId` |
| --- | --- | --- |
| `startWooVerzoek` | on the dossier page (`attachTo` opencatalogi `collection`) | the dossier, set by portaliq |
| `startWooVerzoekAlgemeen` | the home tile, the overview, a site page | none |

Both post to `/index.php/apps/dossiq/api/portal/woo-verzoek`, both declare the same steps.
The route's audience check and assertion check are unchanged. `start()` already opens a case
without case objects when `collectionId` is empty (REQ-WRI-002, scenario "A request without a
dossier").

Alternative considered: drop `attachTo` from the one action so it shows everywhere. Rejected:
on the dossier page portaliq proves the dossier is the resident's before forwarding, and that
proof is what `attachTo` buys.

## D3. Draft and resume

`draft: {retentionDays: 30}` on both actions (portaliq allows 1 to 90, REQ-SMF-021). Portaliq owns the store: per signed-in subject,
per action, the visible answers and the step reached, removed after the retention date or when
the request is sent. The step form shows "Wij bewaren uw antwoorden 30 dagen. U kunt later
verdergaan." from the declared number. Dossiq stores nothing until the request is sent, so a
draft never becomes a case, never starts a term and never appears in a handler's queue.

## D4. The answer and the confirmation

`start()` returns `{caseId, caseUrl, identifier, deadline}`. `identifier` is the case number;
`deadline` is the case's deadline, both read back after the write. When the deadline is not yet
computed (the open finding of dossiq#3245: a portal write under `runAsSystem` resolves no
`@ref`, so `deadline` stays empty until a later save), the answer leaves `deadline` out and the
confirmation drops that sentence.

```php
'confirmation' => [
  'title' => 'Wij hebben uw Woo-verzoek ontvangen',
  'body' => 'Uw zaaknummer is {identifier}. U krijgt uiterlijk {deadline} antwoord.',
  'next' => 'U vindt uw verzoek onder Zaken. U krijgt ook een ontvangstbevestiging in uw berichten.',
],
```

The ontvangstbevestiging itself is shipped (`ontvangstbevestiging`); this change only names it.
`successMessage` stays for a portal that does not render a confirmation page.

## Risks

- **The form promises a deadline the case does not carry yet.** Covered by D4: no deadline, no
  sentence. The root cause is in openregister and is tracked in dossiq#3245.
- **Requester details twice.** The resident's profile and the request both hold a name and an
  address. The request keeps what was given at the time, which is what a Woo file needs; the
  profile can change later without rewriting history.
