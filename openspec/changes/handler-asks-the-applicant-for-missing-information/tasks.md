# Tasks: the handler asks the applicant for missing information from the case

Filed 10 Oct 2026 by lane L2 (decision 170, Q-dossiq-L2-2). Replaces `site-resident-portal-design` task 1.2.

## 1. Board

- [ ] 1.1 Draw `DqAanvullingVragen` (design backlog row in `dossiq/design-backlog.md`): the case page's "Aanvulling vragen" action and its form, with the sentence that tells the handler what the applicant receives. (UI waits for board DqAanvullingVragen, decision 162)

## 2. The form

- [ ] 2.1 `src/modals/AanvullingVragenModal.vue`: missing items (one per line, at least one), recipient, term in days bounded by the case type's declared suspension limit, pause reason (the administered list), rationale. Posts through `requestInformation()` in `src/services/caseTermsApi.js`. (UI waits for board DqAanvullingVragen, decision 162)
- [ ] 2.2 The form says, before sending, that the summary and the missing items go to the applicant and that the term is suspended once the letter has gone out. (UI waits for board DqAanvullingVragen, decision 162)
- [ ] 2.3 A refusal shows the server's sentence (`aanvullingsverzoek-already-open`, `aanvullingsverzoek-not-sent`, no running term). (UI waits for board DqAanvullingVragen, decision 162)
- [ ] 2.4 The case page header offers the action while the case has a running term and no open request. (UI waits for board DqAanvullingVragen, decision 162)

## 3. Tests

- [ ] 3.1 Unit test of the modal: the payload it posts, the sentence, a refusal shown verbatim. (UI waits for board DqAanvullingVragen, decision 162)
- [ ] 3.2 e2e spec for the ask from the case page. (live pass, decision 139)
