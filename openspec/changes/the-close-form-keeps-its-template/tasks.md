# Tasks: the-close-form-keeps-its-template

Tier: V1. Kind: config, plus the e2e the requirement never got.

**The finding this rests on, in one sentence: the move is duplicated, the
close form is not, and `CaseTransitionConfirmDialog` is the only component in
`src/` that mounts `TemplatePicker`.**

The second half of that sentence is why the sweep of 2026-09-19 retired five
unreachable dialogs and kept this one. The first half is why the dialog has no
other reason to exist: `CaseLifecycleMenuDialog` and the stages widget both
post the same `/api/case/{id}/transition` with the same payload builder.

## 1. The picker moves to the surface a handler reaches

- [ ] 1.1 `CaseLifecycleMenuDialog` mounts `TemplatePicker` on the acts that
  carry a result (`inputs.result`), scoped to the case type, offering result
  templates only.
  - `@spec openspec/changes/the-close-form-keeps-its-template/specs/template-library/spec.md`
- [ ] 1.2 Choosing a template presets the outcome text.
- [ ] 1.3 A template chosen after the handler has typed does NOT overwrite
  what they typed. Carry the reasoning across with the behaviour: losing two
  typed paragraphs to a convenience is worse than having no templates, because
  the text cannot be got back and the gesture that destroyed it looked helpful.
- [ ] 1.4 A template scoped to other case types is not offered.

## 2. The requirement gets a test that runs

- [ ] 2.1 `tests/e2e/starter-content-and-templates.spec.ts` gains a test for
  REQ-TPL-02's second scenario: a result template for a
  niet-ontvankelijkverklaring, a handler closing a case with that result, the
  outcome text preset.
  - The file is already collected by the `chromium` project and already
    carries the scenario's `@e2e` tag. It has never carried a test for it, and
    the tag read as satisfied because gate-19 asks for a reference to a FILE.
- [ ] 2.2 A vitest for the no-overwrite rule on the menu, replacing the one
  that asserts it against the unreachable dialog.

## 3. Only then, the retirement

Nothing in this section runs until section 1 and section 2 are green. The
dialog is the only implementation of the requirement while they are not.

- [ ] 3.1 Retire `src/dialogs/CaseTransitionConfirmDialog.vue`.
- [ ] 3.2 Retire `canConfirmTransition()` and `isClosingTransition()` from
  `src/utils/caseLifecycleHelpers.js`. Check the callers again at the time
  rather than trusting this line: on 2026-09-19 the first had only this dialog
  and the second had none, and `buildTransitionPayload()` must stay, because
  the menu and the workflow board both use it.
- [ ] 3.3 Retire `caseTransitionOutcome`, `workflowBoardMove` and
  `resultTemplateOnClose`. `workflowBoardMove` is not a plain deletion: it uses
  the dialog as an ORACLE, asserting the board posts the same transition the
  case page posts. Re-point that oracle at `CaseLifecycleMenuDialog`, which
  posts the same endpoint with the same payload builder, so the assertion
  becomes true again instead of disappearing.
- [ ] 3.4 Drop the `CaseTransitionConfirmDialog` entry from `KNOWN_UNIMPORTED`
  in `tests/vitest/registryOrphans.spec.js`. Its own third test fails if the
  entry outlives the file, so this is not optional.
