# Proposal: case-type-handler-label

## Why

Board DqSociaalDomein names the handler of a Jeugdwet case "Regisseur". The same report card serves objection
cases, where the word is "Behandelaar". The coordinator decided on 24 Oct: no board amend, and the handler label is
configuration of the case type.

## What changes

- The caseType schema (1.42.0, fragment `99-case-type-handler-label.json`) declares `handlerLabel`, an optional
  string.
- The jeugdwet package (1.11.0) sets `handlerLabel: "Regisseur"`.
- The report card's handler row (`case-summary`) reads `labelPath: "@context.caseType.handlerLabel"`, falling back to
  "Handler" (nextcloud-vue feat/facts-label-from-path, 24t1m).

No procedure is named in code.

## Scope

Stacked on r26/family-plan-by-case (the same card). Needs a reinstall of the jeugdwet package for the existing case
type.
