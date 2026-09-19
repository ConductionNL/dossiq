---
kind: config
depends_on: []
---

# Proposal: the-close-form-keeps-its-template

Opened from the surface sweep of 2026-09-19, which retired five unreachable
dialogs and refused to retire a sixth.

## This is a change, not a repair

`CaseTransitionConfirmDialog` is unreachable. No manifest action names it and
nothing in `src/` imports it, since the Documents-era surfaces were replaced.
The sweep retired every other dialog in that state. This one it kept, because
retiring it would delete a feature a spec still asks for, and the fix is to
build the feature somewhere reachable rather than to delete it quietly.

Building it is new work on `CaseLifecycleMenuDialog`. Nothing here is a
cleanup, and nothing here is owed by the change that found the problem.

**The dialog stands until this lands.** Deleting it first would remove the
only implementation of REQ-TPL-02's second scenario while that scenario is
still written down.

## Why

A result template exists so a handler does not retype a
niet-ontvankelijkverklaring. `template-library` REQ-TPL-02 says so: given a
result template, when a handler closes a case with that result, the outcome
text shall be preset from the template.

The only component that implements it is `CaseTransitionConfirmDialog`, which
mounts `TemplatePicker` on its close form. It is the only component in `src/`
that mounts `TemplatePicker` at all. Nobody can open it, so nobody has had a
result template preset since the dialog lost its host.

The surface that closes a case today is `CaseLifecycleMenuDialog`, reached
from the `case-lifecycle-menu` header action on CaseDetail. It collects a
result **type** and a reason. It presets no text and mounts no picker.

## What the evidence is, and is not

**REQ-TPL-02's second scenario has never run.** Its `@e2e` tag names
`tests/e2e/starter-content-and-templates.spec.ts`. That file exists, is
collected by the `chromium` project, and executes. It contains no test for
this scenario: no reference to `TemplatePicker`, to the outcome text, or to
the niet-ontvankelijkverklaring fixture the scenario names. No other e2e file
covers it either.

The tag reads as satisfied because gate-19 asks whether a changed scenario is
referenced by a Playwright **file**, not whether a test in it exercises the
scenario.

So the feature's only evidence is `tests/vitest/resultTemplateOnClose.spec.js`,
which mounts the dialog directly and passes. It is green about a component no
user can open, which is a weaker claim than it looks, and it is the whole
basis for keeping the dialog. This proposal does not pretend otherwise.

That weakens the case for the feature, and it does not decide it. A
requirement nobody tested is still a requirement somebody wrote, and the
behaviour it names, a handler not retyping a standard refusal, is the kind a
municipality notices the absence of.

## What changes

- `CaseLifecycleMenuDialog` mounts `TemplatePicker` on the acts that carry a
  result, scoped to the case type, the same way the retired close form did.
- Choosing a template presets the outcome text and never overwrites text the
  handler already typed. That is the assertion the unit test calls the one
  that matters, and it moves with the feature.
- The scenario gets a real e2e test, in the file its tag already names.
- `CaseTransitionConfirmDialog` is retired once the above is green, with
  `canConfirmTransition()` and `isClosingTransition()`, which have no other
  production caller, and with its three unit-test files.

## Ownership

dossiq. `TemplatePicker` and the menu are both dossiq's, and the template
records are dossiq objects in OpenRegister.

## ADRs

- Company ADR-036: the act stays a declared header action; only the dialog's
  own body changes.

## Capabilities

- Modified: `template-library`: the close form the result template presets is
  the one a handler can reach.

## Impact

`src/dialogs/CaseLifecycleMenuDialog.vue`,
`tests/e2e/starter-content-and-templates.spec.ts`, one vitest. On completion:
`src/dialogs/CaseTransitionConfirmDialog.vue`, two helpers in
`src/utils/caseLifecycleHelpers.js`, three vitest files and the
`KNOWN_UNIMPORTED` entry in `tests/vitest/registryOrphans.spec.js`.
