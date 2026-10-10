# Design: an aanvullingsverzoek is a portal task

## D-0 A generic capability, configured by the aanvullingsverzoek (decision 182)

Procedures are configuration, code is generic. The code that raises and closes the task is `ResidentQuestionTask`: "a question to the resident becomes a portal task". It knows no aanvullingsverzoek. `AanvullingsverzoekService` is its first caller and hands it the question's id, case, portal subject, title ("Vul uw aanvraag aan"), the missing items, the hersteltermijn as due date and its source. Any other question to a resident can use the same capability.

## D-1 The task is an OpenRegister external task, not a portaliq object

Portaliq lists a resident's tasks from openregister's portal task seam: open tasks whose performer type is `external` and whose assignee is the subject's party reference (`party:` + subject reference). The case's `portalSubject` is that subject reference, the same value `vragenAanU` is scoped by. So dossiq writes the task through openregister's trusted in-process intake (`Task\TaskService::import`), resolved through the container like the other optional OpenRegister services. Nothing new is needed in portaliq or openregister.

## D-2 One seam for closing

Every change of a request's state is written through `AanvullingsverzoekService::write()` with an id: the answer (`AanvullingsverzoekResolutionService`), the expiry, a withdrawal. Closing the task there catches all of them, including any writer added later. The task is closed with `terminateAsMoot`: the work it asked for is no longer the resident's to do, and the reason is kept in the task's audit.

## D-3 A completed task is not read as an answer (assumption, made unattended)

A resident can complete the task in the portal, with uploads. Those files land on the case (flow-portal-task D-5). Dossiq does not mark the request answered from that completion, because the answer is recorded item by item (REQ-AVR-02) and a task completion says nothing about which items arrived. The handler sees the files on the case and records the answer, which then closes the task (already closed tasks stay as they ended). If Ruben wants a completion to count as an answer, that is a follow-up change.

## D-4 Never a refusal

The task is raised after the letter went out and the term was suspended. A task that cannot be written (OpenRegister absent, a refused create) is logged and the request stands without `portalTask`; the question still shows under "Vragen aan u".
