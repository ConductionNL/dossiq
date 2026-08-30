---
kind: code
---

## Why

Three tests in `tests/e2e/pages.spec.ts` are excluded on this reason:

```
DEPLOY-MISMATCH: the bespoke "B&W Voorstellen" view ... is a v0.2.8 feature.
The build deployed to this environment is v0.2.0, whose /voorstellen route
renders the generic index shell instead.
```

**The premise is false, and the conclusion is right for a different
reason.** `development` is `0.3.1-unstable` — well past the v0.2.8 the
comment waits for — and the `/voorstellen` route IS declared in
`src/manifest.json`, with `src/views/voorstellen/VoorstelDetail.vue`
present. So nothing is waiting on a deployment.

What is actually missing is the view itself. Every string the three tests
assert appears **zero** times anywhere in `src/`:

| asserted | occurrences in `src/` |
|---|---|
| `B&W Voorstellen` | 0 |
| `Nieuw voorstel` | 0 |
| `Geen actieve voorstellen` | 0 |

The route resolves to the generic index shell because that is all that was
ever built for it. "Deploy mismatch" was standing in for "this was never
implemented" — which is precisely the substitution the hydra
skip-discipline gate's V2 rule exists to catch, since the app under test
IS the head commit.

This change records the work, so the exclusion points at something real
instead of at a deployment that already happened.

## What Changes

A bespoke index for B&W voorstellen, replacing the generic shell on
`/voorstellen`:

1. A titled view with a create control.
2. Lifecycle filter tabs over the list.
3. A Dutch empty state, rather than the generic "no results" of the
   shared index.

The detail side already exists (`VoorstelDetail.vue`); this is the list
side only.

## Impact

- Affected specs: `case-management`
- Affected code: a new view under `src/views/voorstellen/`, and the
  `/voorstellen` page entry in `src/manifest.json` which currently falls
  through to the generic index
- Affected tests: the three `pages.spec.ts` tests named in tasks 4.1–4.3
