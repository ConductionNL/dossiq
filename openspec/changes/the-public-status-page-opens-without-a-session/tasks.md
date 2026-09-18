# Tasks: the-public-status-page-opens-without-a-session

Tier: V1. Kind: code.

## 0. Do not rebuild what is not broken

- [x] 0.1 Measured 2026-09-18: the page's data path is already anonymous
  (`GET /apps/openregister/api/public/case-tokens/{token}`), the dossiq
  public-share controller and its token routes are gone on purpose, the block
  is the login-only catch-all, and nothing in dossiq mints a token. The
  proposal carries the evidence. Anybody starting here reads that first.

## 1. The page opens

- [ ] 1.1 `config.mode: "public"` on the three `/public/...` pages in
  `src/manifest.json`.
- [ ] 1.2 `dashboard#publicPage` on `/public/{path}` in `appinfo/routes.php`,
  before the catch-all, with the `publicPage()` method on the dashboard
  controller.
- [ ] 1.3 Boot the page alone on initial state `public_page`: no navigation, no
  authenticated stores.
- [ ] 1.4 `tests/e2e/public-status-page.spec.ts`: an anonymous context reaches
  the page, and is refused on a page outside `/public/`.

## 2. Somebody can hand out the link

- [ ] 2.1 A mint surface on the case, recorded, with revoke.
- [ ] 2.2 Unit tests for the mint guard, probed with a principal that has no
  rights on the case.

## 3. Blocked on

- [ ] 3.1 openregister #3964 (`public-pages-open-without-a-session`) on
  `parity/round2`. Section 1 is dark until it lands.
