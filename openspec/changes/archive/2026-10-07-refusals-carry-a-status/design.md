# Design: refusals-carry-a-status

## D-1. Count first, then triage

The structural test is the instrument. It tokenises each file under
`lib/Service/`, finds `catch (\Throwable` and looks three statements ahead
for `return null;` or `return [];`. Every hit must appear in the allowlist
with `{file, method, reason, class}` where class is `refusal`,
`degradation` or `read-miss`. The test fails on a hit without an entry, on
an entry without a hit, and when the total exceeds the recorded ceiling.
The ceiling only goes down.

## D-2. Three classes, three fates

- `refusal`: a rule said no. Throw a typed exception (an existing tracked
  class where one fits, else a dossiq `RefusedException` carrying the rule
  slug) and let the controller translate (ADR-105). The entry is removed
  when the site is converted.
- `degradation`: a sibling app or the engine is absent. Keep the catch, log
  at warning naming what was absent, return the documented empty value. The
  entry stays, with the reason.
- `read-miss`: an object was not found on a read. Return null is right;
  the entry stays.

## D-3. The status assertion

Every controller test for a guarded method asserts `getStatus()` for both
branches: the pass and the refusal. Each new assertion is proven by one
mutation (swap the status, watch the test go red) recorded in the PR body.

## D-4. What this does not do

It does not touch the 229 other catches. A catch that rethrows, logs and
continues a loop, or returns a non-empty value is another shape and another
count.
