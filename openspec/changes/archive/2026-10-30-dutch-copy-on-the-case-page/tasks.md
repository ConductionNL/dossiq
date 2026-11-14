# Tasks: dutch-copy-on-the-case-page

- [x] 1.1 `translateBannerCopy()` translates `tabs[].label`, `sections[].label`, `moreLabel` and `ariaLabel`.
  - `tests/vitest/dutchCopyOnTheCasePage.spec.js` (the case page labels its tabs in Dutch, heads the panels in Dutch)
- [x] 1.2 en/nl entries for the two dashboard captions and "Case details"; `npm run l10n:build`.
  - `tests/vitest/dutchCopyOnTheCasePage.spec.js` (the dashboard captions)
- [x] 1.3 `refusalMessage()` maps `change_failed`.
  - `tests/vitest/dutchCopyOnTheCasePage.spec.js` (a lifecycle gesture that could not be saved)
