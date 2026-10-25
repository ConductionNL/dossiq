# The AI oversight card shows the records and downloads them

## Why

On the AI oversight tab, "Wat de assistent op deze zaak deed" reads hermiq's records about the case through hermiq's subject route (`endpointSource`). The library list only read OpenRegister, so the card always showed its empty state; its card also offered an Actions menu with an Add for records the list does not own. The board draws "Downloaden" with the line "Downloaden mag alleen wie de rol AI-toezicht heeft." (COMPDIFF DqZaakAssistent, 23 Oct).

## What changes

- `allowCreate: false`: no Add on this list.
- `export` over hermiq's subject export (`/apps/hermiq/api/ai-oversight/subject/export`), shown to the `ai-oversight` group only; hermiq checks the role and the case access again.
- `footnote` "Only those with the AI oversight role may download." (nl as the board).

## Impact

`src/menu-layout.simple.json`, l10n. Needs nextcloud-vue feat/object-list-endpoint-rows (rows and the endpoint download) and hermiq feat/ai-oversight-subject-export (PR #1178). On an older library the card stays empty and offers no Download; without the hermiq route the Download answers 404.
