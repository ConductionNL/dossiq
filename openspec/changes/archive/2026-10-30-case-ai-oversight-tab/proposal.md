# Proposal: case-ai-oversight-tab

## Why

DqZaakAssistent draws the AI-toezicht tab with three things: a table of what the assistant did on the case, a
"Macro's" card, and the assistant chat. The tab (case-assistant-tab) held only the chat.

## What changes

The AI oversight tab becomes a stack:

- `case-ai-activity`: a plain `object-list` over hermiq's `approval` schema, filtered on `sourceType: advisory`,
  `advisoryContext.originApp: dossiq` and `advisoryContext.subjectId` = this case, newest decision first. Columns:
  moment, user, question or suggestion, outcome (approved = used, overridden = adapted, denied = rejected), model.
  Requires hermiq.
- `case-macros`: the library `macros` widget over the case schema's declared macros.
- `case-assistant-chat` (the embedded chat) leaves the panel for the side column, shown only while this tab is open
  (`tabs: ["ai-oversight"]`, the side cards per tab nextcloud-vue already has). It follows the four generic side
  cards; the board draws it alone in the column, which would need `tabs` on every other side card.

## Backing sources and their gaps

- Hermiq records every human decision on an AI suggestion as an advisory Approval, through `AiOversightRecordedEvent`.
  **dossiq dispatches that event nowhere yet**, so the table stays empty until its AI features report the handler's
  decision.
- The board's outcome "Niet gebruikt" (not used) has no record: hermiq hears only about suggestions a human acted on.
- The case schema declares **no macros** yet; the card says so.
- The board's card subtitle and its export ("Downloaden", AI-toezicht role only) have no widget support.

## Scope

Manifest and catalogues only. Needs nextcloud-vue with `ai-chat` and `macros`.
