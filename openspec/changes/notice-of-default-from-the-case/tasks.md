# Tasks: notice-of-default-from-the-case

- [ ] 1 `src/dialogs/NoticeOfDefaultDialog.vue`: received date, sender (the case's parties as options), posts to `/api/notices-of-default` with the case id; shows the server's refusal text.
- [ ] 2 `src/manifest.json` CaseDetail `headerActions`: `notice-of-default-register` (`open-modal`), `visibleWhen` the case type's penalty-on-late-decision setting.
- [ ] 3 Notices card on the deadlines page (`notices-of-default-by-case`) and a notices list on CaseDetail: row action `payout-prepare` calling `POST /api/accruing-amounts/{id}/payout`, enabled only when the amount is definitive; payout state column reads the stored payout status.
- [ ] 4 `lib/Controller/AccrualNoticeController.php` refuses a notice while the decision term is running (409 with the reason) if it does not already; `tests/Unit/Controller/AccrualNoticeControllerTest.php` covers it.
- [ ] 5 `tests/e2e/notice-of-default.spec.ts`: the three scenarios of REQ-ING-CASE-01 and REQ-ING-CASE-02.
- [ ] 6 Tick task 6 of `payout-goes-through-finance` with a pointer to this change.
- [ ] 7 Live pass against board DqTermijnen.
- [ ] 8 `openspec/parity/capabilities.json` row 8.32: built, evidence the header action, dialog and row action.
