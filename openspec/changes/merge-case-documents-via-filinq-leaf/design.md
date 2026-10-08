# Design: merge-case-documents-via-filinq-leaf

Read at dossiq development `64c66dcf3` and filinq `feat/merge-to-pdf-leaf`
`5aa24ca5` (ConductionNL/filinq#1251, open).

## Context

- filinq `lib/EventListener/RegisterMergeToPdfLeafListener.php`: leaf id
  `filinq-merge-to-pdf`, kind `render-surface`, render mode `mount`, surfaces
  `detail-page` and `single-entity`, group `documents`, `requiredApp` filinq.
  Props: the host's `register`, `schema` and `objectId`.
- `CaseDetail` forwards `register: dossiq`, `schema: case` and the case uuid
  to every leaf it mounts.

## D1. A grid panel beside Projects

Same shape as the other sibling leaves on the page (`humaniq-hours`,
`shillinq-*`, `planninq-projects`): a `type: integration` widget with a layout
cell, no `requiredApp`. Until filinq#1251 lands, or on an instance without
filinq, the leaf is not registered and the panel is missing rather than empty.
