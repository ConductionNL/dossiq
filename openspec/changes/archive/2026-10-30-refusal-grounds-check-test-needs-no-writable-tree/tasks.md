# Tasks: refusal-grounds-check-test-needs-no-writable-tree

- [x] Copy `tools/refusal-grounds-snapshot.php` and the two JSON files it reads into a temporary
      directory, and tamper with the copy.
- [x] Assert the untampered copy checks out (exit 0) before tampering, so a broken copy cannot read
      as drift.
- [x] Assert `lib/Settings/woo-refusal-grounds.snapshot.json` is unchanged after the run.
- [x] Remove the copy in `tearDown()`.
- [x] Show the old test failing and the new one passing with the snapshot file read-only, which is
      the container's condition.
