# Proposal: case-type-declares-its-header-actions

## Why

Boards DqKlachten and DqSubsidie draw the ordinary case page (DqZaak) for two case types, each with its own header row:
- a complaint: Schedule hearing, Edit, the app action, and Record finding as the primary button;
- a grant: Pay advance, Amend decision, Edit, the app action, and Adopt as the primary button.

On the demo stack (24 Nov 2026) every case shows the same row (Message, Document, Record contact, Edit, More). The acts the boards lead with exist, or are being built (changes `complaints-are-a-case-type`, `grant-cases-from-application-to-settlement`), but only as items under More. Which acts lead the header is a choice of the case type. Writing it into the page per case type, or into code named after a procedure, is the pattern decision 182 rules out: procedures are case types, flows and configuration, and code is generic.

## What changes

- A case type can declare its header row in its own configuration: `headerActions`, an ordered list of ids of header actions the case page already declares, and `primaryAction`, the one drawn as the primary button. Generic code reads `@context.caseType.headerActions`. No action is named after a procedure in code.
- A case type that declares nothing keeps today's row.
- An action the case type names but the page does not declare, or that the reader may not use (its own `visibleWhen`), is left out. The case type cannot widen who may act.
- The complaint and grant case types in the shipped register declare the rows the boards draw. Those rows are data in the register seed, not code.

## Out of scope

- The acts themselves. They belong to `complaints-are-a-case-type` and `grant-cases-from-application-to-settlement`.
- An editor for the row in the case type page. The declaration can be edited as case type data first.
