# Tasks: beta-quality-report-green

Kind: code. Fixes `widget-roles-declared`, `rebind-dialog-translated-labels`
and `case-search-declares-its-fields`.

- [x] 1.1 `WidgetRoles::definitionsOn()` reads `config.views[].widgets`.
- [x] 1.2 `your-teams-queue` is allowlisted with its reason.
- [x] 1.3 Unit test: a widget inside a view is read and its role is enforced. Fails on the old code.
- [x] 2.1 `lib/Service/Support/LanguageMapText.php` holds the language map reading.
- [x] 2.2 `CaseTypeResolver` and `TranslatedText` receive it by injection; `StatusPublicLabels::textOf()` delegates.
- [x] 2.3 `TranslatedText::of()` is renamed `forReader()`.
- [x] 2.4 `lib/Service/Cases/CaseRebindTerms.php` re-arms terms under the target's slug; `CaseRebindService` takes it in place of `TermRearm` and `CaseTypeSlugResolver`.
- [x] 2.5 Unit test for `CaseRebindTerms`. phpmd on the touched files exits 0.
- [x] 3.1 The risky tests list every class they run (`@uses`).
- [x] 4.1 `deadlineOverdue` declares `inputControl: boolean`.
- [x] 4.2 The 21 register strings get en and nl entries; `npm run l10n:build`.
- [x] 4.3 prettier on `capabilityComparison.spec.js`; the comment stripper in `caseActionsMenu.spec.js` repeats until stable.
