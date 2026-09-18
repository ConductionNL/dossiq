# Tasks: digital-post-reaches-integriq

Tier: V1. Kind: code. Row 12.9.

- [ ] 1.1 `lib/Service/BerichtenboxAdapter/IntegriqAdapter.php` implementing
  `BerichtenboxAdapterInterface`: dispatch integriq's digital post send event
  and return the reference it answers with.
  - `@spec openspec/changes/digital-post-reaches-integriq/specs/berichtenbox-integration/spec.md`
- [ ] 1.2 Resolve integriq through `OCA\Dossiq\Support\FleetAppId`
  (`isInstalled`, `resolveClass`), never through a literal app id or a literal
  class name. Confirm with a test that a renamed integriq still resolves.
- [ ] 2.1 `SubstitutableAdapterRegistrar`: `berichtenbox_adapter: 'integriq'`
  selects the new adapter. The key's other values are unchanged.
- [ ] 2.2 The adapter refuses with a named error when integriq is absent or
  its digital post seam reports itself unavailable. It never returns the mock.
- [ ] 3.1 A listener under `lib/Listener/` for integriq's delivered event,
  updating the stored message's status on the case and writing a timeline
  entry.
- [ ] 3.2 Confirm on a running instance which event integriq actually
  dispatches on delivery, and name it in the listener. A listener bound to an
  event nobody fires is indistinguishable from one that works.
- [ ] 4.1 Unit tests: refusal when integriq is absent, refusal when the seam
  is unavailable, a reference stored on success, the mock still selectable.
