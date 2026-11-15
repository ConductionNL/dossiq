---
kind: code
---

# Proposal: refusal-grounds-check-test-needs-no-writable-tree

## Summary

`RefusalGroundsSnapshotTest::testTheCheckNamesAGroundThatDiffers` tampers with a disposable copy of
the files `tools/refusal-grounds-snapshot.php` reads, instead of overwriting the tracked
`lib/Settings/woo-refusal-grounds.snapshot.json` and restoring it afterwards.

No shipped behaviour changes. No assertion is removed or relaxed: the test still requires exit 1 and
still requires the failing ground's code in the output. It gains a control run and a guard that the
shipped snapshot was not written to.

## Why

The test needed write access to a file the app ships, and that had two costs.

It fails wherever the suite runs as a user other than the checkout's owner. Measured 2026-10-15 in
the dossiq dev container, where phpunit runs as `www-data` against a host-owned bind mount:
`file_put_contents()` was refused, the check script then read an untampered snapshot and exited 0,
and the whole verdict the suite gave was `Failed asserting that 0 is identical to 1`. That reads as
snapshot drift, so it sends a reader to audit a snapshot that is correct. It was one of four reds
that held up the dossiq release sweep, and the only one with a cause inside this repo.

It also puts the shipped snapshot at risk. The restore sits in a `finally`, and a `finally` does not
survive SIGKILL, so a run killed in the two milliseconds between the write and the restore leaves
`woo-refusal-grounds.snapshot.json` carrying a label nobody wrote. filinq and opencatalogi vendor
that file as their redaction fallback (REQ-WRG-008), so a corrupt one travels.

## What changes

- The tool resolves `$root` from its own location, so the test copies the three files it reads into a
  temporary directory and tampers there. `tearDown()` removes the copy.
- A control run of the untampered copy asserts exit 0 first. Without it a copy that cannot be read
  produces the same exit 1 as a ground that differs, and the test's own failure would read as its
  subject's.
- The test asserts the shipped snapshot is byte-identical after the run.

## Not in scope

The other three reds in the same sweep,
`StubApiDriftTest::testTheStubAgreesWithTheRealClass` for `Db\ObjectEntity`,
`Db\TaskInboxCriteria` and `Mcp\Attribute\McpTool`, are not stub drift and need no change here. They
were measured against a sibling openregister checkout 254 commits behind that app's `development`.
Against a fresh `development`, the ref CI clones, all 76 cases pass. The test says so itself in its
verdict, which names the checkout and its revision for exactly this reason.
