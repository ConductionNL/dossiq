# Proposal: case-type-flow-direct-load

## Why

Opened directly (a bookmark, a reload, a link from elsewhere), the case type flow page at
`/settings/case-types/:id/flow` said "No workflow defined for this case type yet" for every case type. The page asked for
the case type's workflow versions before the app had registered the workflow template type in its object store, the
request was refused with "Object type workflowTemplate is not registered", and the refusal read as an empty list.
Reached through "View as flow" on the case type page it worked, because by then the stores were up. Board DqZaaktypeFlow
draws the flow of a case type with its statuses.

## What changes

- The case type flow page starts the stores itself (`initializeStores()`, as the start-case widget does) and draws the
  workflow only once they are up, with a loading state meanwhile.

## Reach

dossiq only. No nc-vue dependency.
