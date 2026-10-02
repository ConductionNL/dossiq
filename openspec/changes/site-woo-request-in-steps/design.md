# Design: site-woo-request-in-steps

Read at dossiq `development` `59217bc9a` and portaliq `development` `b150def5` on 2026-10-02.

## D1. The steps, as the action declares them

```php
'steps' => [
  ['id' => 'vraag', 'title' => 'Uw vraag',
   'hint' => 'Vertel ons waar uw vraag over gaat.',
   'fields' => ['onderwerp', 'omschrijving']],
  ['id' => 'periode', 'title' => 'Periode en documenten',
   'hint' => 'Hoe preciezer uw vraag, hoe sneller u antwoord krijgt.',
   'fields' => ['periodeVan', 'periodeTot', 'documentSoorten', 'toelichting']],
  ['id' => 'gegevens', 'title' => 'Uw gegevens',
   'hint' => 'Wij gebruiken deze gegevens alleen voor uw verzoek.',
   'fields' => ['verzoekerNaam', 'verzoekerEmail', 'verzoekerType']],
  ['id' => 'controleren', 'title' => 'Controleren en versturen', 'review' => true],
],
```

Field configs, from the mockup:

| Field | Label | Required in the form | Server |
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

The form may be stricter than the server. The server keeps its rules because pipelinq calls
`start()` too, and an employee converting a phone call may not have every answer. The form's
"required" is a presentation key the action already supports (`fieldConfigs.required`).

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

`draft: {retentionDays: 30}` on both actions. Portaliq owns the store: per signed-in subject,
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
