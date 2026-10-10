## Why

A handler can only ask an applicant for missing information through the API. `requestInformation()` in `src/services/caseTermsApi.js` has no caller in `src/`, and the manifest declares no action on `POST /api/cases/{caseId}/information-request`. The backend is complete: `AanvullingsverzoekService::ask()` sends the letter, suspends the term and writes the `aanvullingsverzoek` record (REQ-TERM-067, REQ-AVR-01). The case page has no way to start it.

`site-resident-portal-design` task 1.2 wanted the ask form to tell the handler that the summary and the missing items go to the applicant. That form did not exist, and no Dq board drew one. Ruben decided on 10 Oct (decision 170, Q-dossiq-L2-2): drop 1.2 there, file this change, and put its board on the design backlog.

## What Changes

- A case-page action "Aanvulling vragen" opens a form that collects the missing items (one per line), the recipient, the term the applicant gets (within the case type's declared limits, REQ-TERM-066), the pause reason and the rationale, and posts them to the existing endpoint.
- The form tells the handler, before they send, that the summary and the list of missing items go to the applicant, and that the term is suspended once the letter has gone out.
- A refusal from the act (no running term, a request already open, a send that failed) is shown as the server's sentence. The form shows no success state of its own.
- The board `DqAanvullingVragen` is drawn first (design backlog, decision 157). Until Ruben approves it, the UI tasks wait (decision 162).

## Out of scope

- The resident's side: the question shows under "Vragen aan u" (site-resident-portal-design 1.3), and as a portal task (decision 169, change `an-aanvullingsverzoek-is-a-portal-task`).
- Any backend change. The endpoint, the act and the record already exist and are tested.

## Impact

- `src/` gets a modal under `src/modals/` and a header action on the case page. No PHP, no register change.
