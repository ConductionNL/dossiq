# case-type-translations-summary

## Why

Board dossiq/DqZaaktypeOverzicht draws a Vertalingen card on the Overview tab: one row per language, with how much of it
is there and a pill, Compleet or the number missing, and Bewerken to the editor. The Overview tab showed the whole
translations editor instead.

## What changes

- The translations widget takes `content.mode: "summary"`: one row per language the register declares, "{translated}
  of {total} labels", and a pill that reads "Fully translated" (Compleet) or "{missing} missing". The stale rule of the
  editor's chips applies.
- The Overview tab shows the summary; its Edit link opens the Translations tab, which keeps the editor.
- Dutch strings.
