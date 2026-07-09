## 1. Discover the full scope

- [ ] 1.1 Run the diff procedure from proposal.md (`en.json` keys not in `nl.json`) and save the
      full 322-key list.
- [ ] 1.2 Classify each key: (a) Dutch-literal-as-key (the key text itself reads as Dutch —
      candidates already confirmed: `Aanbestedingen`, `Actie`, `Afgesloten`, `Afgewezen`,
      `Afwijzing`, `Alle`, `Alle statussen`, `Adres bijgewerkt`, `Adres bijwerken`,
      `Adreswijzigingen worden direct verwerkt.`, `Aangezochte bevoegd gezag`, `Aanmaken`,
      `Contactpersoon bijgewerkt`, `Auto-verleng`, `Automatisch`, `Bedrag`, and the rest of the
      `dutchWords`-matching subset from the proposal's diff script) vs (b) genuinely untranslated
      English source (`Activity timeline`, `Add decision`, `Back to parent`, `Back to parent
      case`, `({completed}/{total} completed)`, and the remainder).

## 2. Fix Dutch-as-key violations (category a)

- [ ] 2.1 For each Dutch-literal key, `grep -rn "'<key>'" src/` to find every call site
      (confirmed multi-site duplicates: `'Aanbestedingen'` in `TenderList.vue` +
      `LeverancierDashboard.vue`; `'Actie'` in `TenderList.vue` + `ContractList.vue`;
      `'Afgesloten'` in `ConsultationDashboard.vue` (2 sites)).
- [ ] 2.2 Replace the literal Dutch string in every `t('procest', '<dutch>')` call with a new
      English source key (e.g. `'Actie'` → `'Action'`, `'Afgesloten'` → `'Closed'`,
      `'Aanbestedingen'` → `'Tenders'`, `'Alle statussen'` → `'All statuses'`).
- [ ] 2.3 Add the new English key to `l10n/en.json` with itself as the value.
- [ ] 2.4 Add the new English key to `l10n/nl.json` with the original Dutch text as the value
      (e.g. `"Action": "Actie"`), so the Dutch UI is unchanged in wording — only the underlying
      key becomes an English source string per project convention.
- [ ] 2.5 Remove the now-unused Dutch-literal key from `en.json` (it should have zero remaining
      call sites after step 2.2).
- [ ] 2.6 Grep `tests/e2e/**/*.spec.ts` for any assertion on the literal Dutch text that changed
      key (rendered text is unchanged by this task since `nl.json` still resolves to the same
      Dutch string — but nav-label lookups like `navTo(page, 'Beroepen')` in
      `tests/e2e/spec-coverage/bezwaar-family.spec.ts` should be spot-checked to confirm they
      still resolve, since the app under Playwright typically renders in one fixed locale).

## 3. Backfill missing Dutch translations (category b)

- [ ] 3.1 For each genuinely-untranslated English-source key, add the correct Dutch translation to
      `l10n/nl.json` (do not machine-translate blindly — check the surrounding UI context / other
      procest strings for house terminology, e.g. existing `bezwaar`/`beroep` vocabulary).
- [ ] 3.2 Re-run the en/nl diff script from proposal.md and confirm 0 remaining gaps.

## 4. Prevent regression

- [ ] 4.1 Extend `tests/l10n/check-l10n.js` to also fail (or warn, per project preference) when
      `en.json` has a key absent from `nl.json`, alongside its existing "used-in-code but missing
      from en.json" check.
- [ ] 4.2 Add a lightweight lint/grep-based check (or extend the same script) that flags any new
      `t('procest', '<literal>')` call whose literal contains common Dutch stopwords/diacritics, to
      catch future Dutch-as-key regressions before merge.

## 5. Spec + traceability

- [ ] 5.1 Add the new `frontend-locale-completeness` capability spec (this change) and run
      `openspec validate nl-locale-coverage-gap-and-dutch-keys --strict`.
- [ ] 5.2 Fix any pre-existing ESLint warnings encountered in the touched `.vue`/`.js` files while
      implementing this change (project convention — do not defer).

## 6. Verification

- [ ] 6.1 Live-verify: switch the Nextcloud UI language to English and confirm the tender list
      ("Tenders" not "Aanbestedingen"), consultation status filter, and other fixed sites from
      task 2.1 render in English.
- [ ] 6.2 Live-verify: switch to Dutch and confirm a sample of the backfilled category-b strings
      (e.g. wherever "Activity timeline" / "Add decision" appear) now render in Dutch.
- [ ] 6.3 Run `npm run test:l10n` and confirm it now also reports 0 en/nl coverage gaps.
