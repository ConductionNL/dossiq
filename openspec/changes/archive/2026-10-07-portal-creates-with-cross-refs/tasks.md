# Tasks: portal-creates-with-cross-refs

Kind: code. Closes `move-portals-to-portaliq` T7. Row 6.7.

- [x] 1.1 `lib/Portal/PortalContributionProvider.php`: `createBezwaar` and
  `replyToMessage` on the citizen audience, each with its guarded reference
  and its server-stamped `defaults` (D-1, D-2).
  - `@spec openspec/changes/portal-creates-with-cross-refs/specs/portal-contribution/spec.md`
- [x] 1.2 The same file: `submitChecklistRun` on the inspector audience, as an
  update with a server-enforced `set` and no client case or template (D-4).
- [x] 2.1 Provider unit tests: every create that names a case guards it; the
  kind and direction are stamped; the submit accepts no case or template.
- [x] 3.1 `openspec validate portal-creates-with-cross-refs --strict`.
