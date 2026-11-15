# Design: case-claim-action

## D-1. One endpoint, because only the server can refuse

`claimCase` and `releaseCase` were designed as two browser-side writes through
the object store. They are one endpoint each instead, over the stored case:
`POST /api/case/{caseId}/claim` and `POST /api/case/{caseId}/release`, with
`GET /api/case/{caseId}/assignment` beside them for a surface that wants to ask
before it offers.

The reason is the one thing a browser cannot do. Two handlers looking at the
same unclaimed case both see Claim, and a store write of `{assignee: me}` from
the second one succeeds: it is a legal write on a case they may edit, and it
takes the case out of the first handler's hands with nothing on either screen
saying so. Read-compare-write against the stored case is the whole feature, and
it has to happen where the write happens.

Nothing else moves to the server. The read and the write both run as the
signed-in user, so OpenRegister's RBAC answers "may this person touch this
case" exactly as before (ADR-022, ADR-023), and the audit trail on the case is
the platform's. What the endpoint adds is the single rule OpenRegister has no
way to know: a case with a handler is not free to take.

## D-2. Visibility is declared, but it cannot be declared on the assignee

The change said Claim carries `visibleIf: assignee empty` and Release
`visibleIf: assignee == @me`. Neither is expressible, and each way of trying
fails silently rather than loudly:

- a LOCAL `visibleWhen` compares a dot-path against a LITERAL with
  `eq | neq | gt | gte | lt | lte`. There is no empty operator, and an absent
  property reads as `undefined`, which equals neither `""` nor `null`;
- `value` is not token-resolved, so `@me` is compared as the four characters
  `@me` and never matches a uid;
- the `endpoint` mode fetches its url VERBATIM, with no token resolution, so it
  cannot name this case;
- the `source` mode queries OpenRegister's object list, which ignores an `id`
  filter and answers with the whole table.

The last two are already written up in this page's own manifest note, which is
where the same three traps were found for the lifecycle actions.

So the gate is the one thing the loaded record says for certain, which is that
the case is open (`isFinalStatus neq true`), and the SERVER refuses the gesture
that does not apply, with a 409 and a sentence. That is this page's convention
rather than a new one: `case-resume` is offered on every open case and refused
in words when the case is not suspended, for the same reason.

What this costs, said plainly: Claim is visible on a case somebody else holds,
and Release is visible to someone who is not the handler. Both refuse. The
alternative that would hide them is a materialised boolean on the case record,
and a flag written only on the next save would read false on every case that
already exists, which hides Claim on exactly the cases the queue is full of.

## D-3. The refusal is the server's, and so is its sentence

A declarative `api-call` shows its own `errorMessage` when the action carries
one and the response's `error` when it does not. So neither action carries one,
and the endpoint answers `{error: <sentence>, code: <rule>}`: the sentence is
what a handler reads, the code is what a surface acts on. It is the shape
`CaseLifecycleController` already answers with. The sibling change
`refusals-carry-a-status` names `{message, error}` with the rule in `error`;
reconciling the two spellings belongs to that change, and this one did not
depend on its code.

No optimistic update: the page refreshes on the endpoint's answer, so a refused
claim never shows as a claim that landed.

## D-4. Three surfaces, three dispatch vocabularies

The case page, the queue and the case list do not speak the same action
grammar, and each silent failure below was found by reading the library rather
than by trying it:

- a `handler` HEADER action resolves `action.handler` against
  `effectiveManifest.actions`, which is a JSON map in `src/manifest.json` and
  so cannot hold a function. The design named that type; it would warn once to
  the console and do nothing when clicked. Hence `api-call`;
- an index ROW action knows only `navigate`, `open-page` and a handler NAME
  resolved against `customComponents`. `api-call` is not in its vocabulary at
  all, and `object-op` merges `action.values` into the row VERBATIM, so a
  declared `{assignee: "@me"}` would store that literal string on the case.
  Hence a function handler;
- a row action's `visible` predicate is a function, and a manifest is JSON, so
  Claim cannot be shown only on rows without a handler. It is offered on every
  row and refused where it does not apply.

The function handler lives in `src/utils/caseClaim.js` and is registered in
`src/customComponents.js`, which imports every surviving page and tab: a unit
test that imported the registry to reach one function would mount the
component tree behind it.
