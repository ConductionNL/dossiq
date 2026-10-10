---
kind: code
depends_on: [nextcloud-vue-2-73-runtime-version]
---

# Proposal: nextcloud-vue-2-76-object-lock

## Summary

dossiq moves from @conduction/nextcloud-vue ^2.74.0 to ^2.76.0.

On 2.73.1 and 2.74.0 a case detail page never took its lock. CnDetailPage
passes getter functions for the register, schema and id, and `useObjectLock`
read them with `unref`, which hands a function back as it is. The lock
request went to `/api/objects/() => .../() => .../() => .../lock`, and
OpenRegister answered 404. 2.76.0 reads them with `toValue` and sends no
request until all three are known.

The same release also:

- loads CnRelatedObjectsWidget sections one by one, so one slow section no
  longer holds back the rest;
- translates the `addLabel` of CnObjectListWidget;
- writes the Credentials text in the settings dialog without em-dashes, in
  English and Dutch.

## Why

Two people could edit one case at the same time, and the lock banner never
showed, because the lock was never taken. The Credentials copy broke the
Conduction voice in the settings dialog.

## Dependencies

@conduction/nextcloud-vue 2.76.0 (on npm). Its dependencies are the same as
2.73.1 and 2.74.0, so the lockfile changes in one entry. The manifest schema
went from 2.72.0 to 2.73.0 (adds the optional `placement` of the brand block),
so `tests/schemas/app-manifest-v2.schema.json` is copied from the package
again.
