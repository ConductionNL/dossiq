# The case type page follows its boards

## Why

Boards DqZaaktype, DqZaaktypeOverzicht and DqZaaktypeVelden draw the case type
page differently from what it shows now (COMPDIFF r20: 46, 30 and 28 items).
The boards are the canon, and Dq wins a clash.

- **Tabs.** The board strip is Overzicht, Velden, Statussen, Fasen en taken,
  Regels, Rechten, Privacy, Vertalingen, Versies and Historie. The page has no
  Regels tab and shows a Portaal tab that the board does not place.
- **Fasen en taken.** The board draws four cards: the phase board, with
  "Bekijken als flow" and "Taak toevoegen"; Varianten; Termijnen in words; and
  the messages to the applicant. The page puts the workflow canvas inline under
  the phase board, shows the terms as raw ISO durations (P56D) and adds
  document and decision type tables that the board does not draw.
- **Side column.** The board's Gegevens card names the domain, the origin
  (Meegeleverd VTH-sjabloon), the form and the handling teams. The page shows
  raw codes: `bpmn`, `openbaar`, the identifier.

## What changes

- The tab strip follows the board. A new **Rules** tab holds the case rules.
  The **Portal** tab moves under the strip's Actions menu, which the board
  already draws, so it stays one click away.
- The **Phases and tasks** tab holds the phase board, Variants, Terms and
  Messages to the applicant.
  - The phase board's header link "View as flow" opens the workflow canvas on
    a page of its own, `/settings/case-types/:id/flow`.
  - Each phase has an "Add a task" button that opens that same page, where
    the tasks (the workflow steps) are added.
    Each phase in `GET /api/case-types/{id}/phases` now carries
    `caseTypeId`, so that button can address the page.
- The workflow canvas is unchanged and is reached through those two links.
  The document types, decision types and the blueprint move to the
  **Statuses** tab, beside the results they belong with.
- A case type can declare **variants**: named routes through it, one marked as
  the default. The Variants card lists them.
- `GET /api/case-types/{id}/terms` answers the terms in words, for example
  "8 weeks, extendable once by 6 weeks" and "Allowed, at most 28 days". The
  Terms card shows that answer.
- The side column's facts read as labels: the domain, the origin (shipped or
  own case type), the intake form when one is bound, and the handling teams.
  The bound form's title is not shown: `intakeFormRef` holds a Nextcloud
  Forms hash, and reading the title needs a lookup in Forms that this change
  does not add.

## Not changing

Every editor stays reachable: the workflow canvas, the messages editor, the
rules, the portal settings and the document and decision types. Nothing is
removed from the full structure profile.
