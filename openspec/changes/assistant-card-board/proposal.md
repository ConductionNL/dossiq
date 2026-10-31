# The assistant beside the AI oversight tab reads as the board's card

## Why

Board DqZaakAssistent draws the assistant as a card titled "Assistent" with a "Hermiq" pill, a line "De assistent ziet alleen wat u op deze zaak ziet. Is Hermiq er niet, dan staat dit paneel uit." and an input "Vraag iets over deze zaak" with a "Vragen" button. Live showed the companion's own agent titlebar ("Hydra Triage") without the card (COMPDIFF DqZaakAssistent, 23 Oct).

## What changes

- `case-assistant-chat` content: `pill` "Hermiq", `note`, `placeholder`, `sendLabel` (en + nl).
- Needs nextcloud-vue feat/ai-chat-widget-board-card (ai-chat in a titled card, the content keys). On an older library the keys are ignored and the chat looks as before.

## Impact

`src/menu-layout.simple.json`, l10n. No schema or route change.
