---
kind: code
depends_on: [widget-roles-declared, rebind-dialog-translated-labels, case-search-declares-its-fields]
---

# Proposal: beta-quality-report-green

The development to beta pull request (#2581) failed its required Quality
Report. This change fixes the findings that the pipelinq review programme
caused, and the small inherited ones next to them.

## Why

- **PHPUnit, all 8 cells.** `WidgetDeclaresRolesTest` said five allowlisted
  widgets were gone. They were not gone: #3323 moved them into the views of
  the landing page (`config.views[].widgets`), and `WidgetRoles` only read
  `config.widgets` and `widgets`. So the role check stopped seeing them, and
  a role declared on a widget inside a view would never have been enforced.
  The cells were also red on 77 risky tests: `phpunit.xml` fails on a test
  that runs a class it does not list in `@covers` or `@uses`.
- **phpmd.** #3332 added static calls to `StatusPublicLabels::textOf()` in
  `CaseTypeResolver` and `TranslatedText`, a method named `of()`, and a tenth
  constructor argument to `CaseRebindService` (coupling 13).
- **vitest.** `deadlineOverdue` (a boolean) declared no input control.
- **check:schema-l10n.** 21 register strings had no catalogue entry.
- **format and CodeQL.** One test file was not prettier-formatted, and one
  test stripped HTML comments in a single pass.

## What changes

- `WidgetRoles::definitionsOn()` also reads the widgets inside a page's
  views. `your-teams-queue`, new in the team view, joins the allowlist with
  its reason.
- `LanguageMapText` holds the language map reading as an instance.
  `CaseTypeResolver` and `TranslatedText` receive it by injection.
  `StatusPublicLabels::textOf()` delegates to it, so there is one reading.
- `TranslatedText::of()` is renamed `forReader()`.
- `CaseRebindTerms` joins the slug lookup and the term re-arm, which were
  two constructor arguments of `CaseRebindService`. It is one step of the
  rebind, so it is one collaborator.
- The risky tests list the classes they run.
- `deadlineOverdue` declares `inputControl: boolean`.
- The 21 register strings get en and nl entries.
- `capabilityComparison.spec.js` is formatted; `caseActionsMenu.spec.js`
  strips comments until nothing changes.

## Not in this change

The debt ratchet (11 new phpmd suppressions from other work), and hydra
gates 23, 26, 46 and 64 are inherited. The pull request lists them.
