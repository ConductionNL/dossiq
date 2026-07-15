# Realtime Updates UI (leaf adoption)

## ADDED Requirements

### Requirement: Store-rendered views MUST subscribe to live updates for their scope

Views that render from Procest's `createObjectStore`-based object store MUST subscribe to
live updates for the data they display: collection-scoped views subscribe to
`or-collection-{register-slug}-{schema-slug}` per rendered object type, object-scoped views
subscribe to `or-object-{uuid}`. Subscriptions MUST be re-scoped when the viewed scope
changes and released when the view is destroyed. Events are refetch HINTS only: views MUST
refetch through their existing fetch paths and MUST NOT patch rendered state from an event
payload.

@e2e exclude Requires a second concurrent authenticated session plus a notify_push (or poll-tick) round-trip; covered by the shared library's transport tests and manual two-browser verification.

#### Scenario: Workflow board refreshes when a case changes elsewhere

- **GIVEN** the workflow board is open
- **WHEN** another user creates, updates or transitions a case
- **THEN** the board receives the `or-collection-{register}-{schema}` hint and re-runs its
  existing `fetchData()` path (debounced, non-blanking background mode), so the card
  moves/updates without a manual refresh

#### Scenario: Board refetch deferred during drag or bulk transition

- **GIVEN** the workflow board is open and the user is mid-drag (or the bulk-transition
  dialog is open)
- **WHEN** a live event hint arrives
- **THEN** the refetch is skipped for that hint — the post-save server event re-hints once
  the interaction completes, so no state is lost

#### Scenario: Sub-case detail refreshes when the viewed object changes elsewhere

- **GIVEN** the deelzaak detail view is open for sub-case `{uuid}`
- **WHEN** another user updates that sub-case
- **THEN** the `or-object-{uuid}` hint triggers a debounced `reload({ background: true })`
  through the existing fetch path, and the view re-renders the fresh data

#### Scenario: Subscription released on scope change and destroy

- **GIVEN** a live subscription is active for the current scope
- **WHEN** the user opens another sub-case (or navigates away)
- **THEN** the previous subscription is released — including one still in flight, which is
  invalidated via an epoch counter and unsubscribes itself on resolution
