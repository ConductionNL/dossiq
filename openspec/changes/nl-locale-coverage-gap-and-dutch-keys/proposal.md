# Proposal: nl-locale-coverage-gap-and-dutch-keys

kind: code — i18n hygiene. Not covered by any active or archived change; `test:l10n`
(`tests/l10n/check-l10n.js`) only checks that every key *used in code* exists in `l10n/en.json` —
it does not check `en.json` vs `nl.json` coverage, so this gap is currently invisible to CI.

## Why

Comparing `l10n/en.json` (2,631 keys) against `l10n/nl.json` (2,309 keys) directly:

```
$ node -e "const en=require('./l10n/en.json').translations, nl=require('./l10n/nl.json').translations;
  console.log(Object.keys(en).filter(k => !(k in nl)).length)"
322
```

**322 of 2,631 keys (~12%) used somewhere in the app have no `nl.json` entry at all.** These
322 keys split into two distinct, both-real problems:

**1. Dutch text used directly as the i18n key (~40+ confirmed), violating the project's own
   "i18n keys = ENGLISH source" rule:**

- `src/views/leverancier/TenderList.vue:16`: `{{ t('dossiq', 'Aanbestedingen') }}` — and the
  same literal Dutch key appears again in `LeverancierDashboard.vue:53`, `ContractList.vue:48`
  (`'Actie'`), `ConsultationDashboard.vue:154/260` (`'Afgesloten'`), `ConsultationDashboard.vue:35`
  (`'Alle statussen'`).
- Because the key IS Dutch, `l10n/en.json` maps it to itself: `"Aanbestedingen": "Aanbestedingen"`,
  `"Afgesloten": "Afgesloten"`, `"Actie": "Actie"` (verified directly in `en.json`) — **an
  English-locale Nextcloud user sees raw Dutch words** ("Aanbestedingen", "Actie", "Afgesloten",
  "Adres bijgewerkt", "Contactpersoon bijgewerkt", "Geen aanbestedingen gevonden.") in what is
  supposed to be an English UI.
- This is the exact anti-pattern the project's own working-style memory flags: *"i18n keys =
  ENGLISH source — never Dutch as i18n key"*.

**2. Genuine untranslated English-source strings — the classic silent-fallback gap:**

- The remaining ~280 missing keys are English source strings (`'Activity timeline'`,
  `'Add decision'`, `'Back to parent case'`, `'({completed}/{total} completed)'`, …) that were
  never added to `nl.json`. A Dutch-locale user hitting any of these sees the raw English key
  literal instead of a Dutch translation — silent English-as-Dutch fallback, for a government
  case-handling app whose primary user base is Dutch-speaking municipal staff.

Neither gap is caught today: `npm run test:l10n` (`tests/l10n/check-l10n.js`) only verifies
"every key used in code exists in `en.json`" — it reported **OK** (`scanned 312 files, 2249
distinct literal keys used, ... OK — every used translation key is present in l10n/en.json`)
while both of the above problems were present, because it never compares `en.json` against
`nl.json`.

## What Changes

- **REQ-I18N-01**: Replace the ~40+ confirmed Dutch-literal i18n keys (`'Aanbestedingen'`,
  `'Actie'`, `'Afgesloten'`, `'Afgewezen'`, `'Alle statussen'`, `'Adres bijgewerkt'`,
  `'Contactpersoon bijgewerkt'`, and the rest of the dutch-like subset — see tasks.md for the
  full list procedure) with English source keys (e.g. `'Actie'` → `'Action'`,
  `'Afgesloten'` → `'Closed'`) across every `.vue`/`.js` call site, add the correct English value
  to `en.json`, and add the original Dutch text as the `nl.json` translation for the new key.
- **REQ-I18N-02**: Backfill Dutch translations for the remaining genuinely-untranslated
  English-source keys currently missing from `nl.json` (~280 keys) so no user-visible string
  silently falls back to raw English key text in the Dutch locale.
- **REQ-I18N-03**: Extend `tests/l10n/check-l10n.js` (or add a companion script) to fail when
  `en.json` has keys absent from `nl.json` (or vice versa), so this regression class is caught by
  `npm run test:l10n` going forward instead of requiring a manual diff.

## Impact

- Affected: `l10n/en.json`, `l10n/nl.json`, `tests/l10n/check-l10n.js`, and every `.vue`/`.js` file
  using a Dutch-literal key (see tasks.md task 1 for the discovery procedure).
- Not BREAKING: user-facing text changes (Dutch → correct language per locale) but no API/route
  changes. Existing e2e Playwright specs that assert on literal Dutch button/label text (a real
  risk given `bezwaar-family.spec.ts` and others assert Dutch nav labels) MUST be checked/updated
  if any assertion happens to target one of the renamed keys' rendered text.
