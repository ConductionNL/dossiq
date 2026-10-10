## Why

An aanvullingsverzoek reaches the resident as a question under "Vragen aan u" on the case and on the overview (`vragenAanU`, site-resident-portal-design). It did not count as a task in the resident's portal, so a resident who works from portaliq's task list never saw it there. Ruben decided on 10 Oct (decision 169, Q-dossiq-L2-1), against the recommendation, that every aanvullingsverzoek also becomes a portaliq portal task. dossiq writes the task when it asks and closes it when the resident answers.

## What Changes

- When a handler asks, and the case has a portal subject, dossiq raises an OpenRegister external task for the resident: performer type `external`, assignee `party:<portal subject>`, the case as its subject, due at the end of the hersteltermijn day, with the missing items in its description. Portaliq's task surface (`/portal/api/tasks`, openregister flow-portal-task) lists exactly that shape for the resident, so neither portaliq nor openregister changes.
- The request remembers the task in a new `portalTask` property (aanvullingsverzoek schema 1.3.0).
- When the request leaves `open` (answered, expired, withdrawn), dossiq closes the task as moot, with the reason in the task's audit.
- A task that cannot be raised or closed is logged and never turns the ask into a refusal: the letter has gone out and the term is suspended before the task is raised.

## Out of scope

- The handler's ask form (`handler-asks-the-applicant-for-missing-information`).
- Reading a resident's completion of the task as an answer. Uploads from a completed task land on the case as files (openregister flow-portal-task D-5), and the handler records the answer item by item as today (REQ-AVR-02). See design D-3.
