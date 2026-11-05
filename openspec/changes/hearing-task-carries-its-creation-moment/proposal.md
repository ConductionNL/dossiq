# Proposal: hearing-task-carries-its-creation-moment

## Why

Board DqTaak draws the hearing task "Hoorzitting inplannen" as created on 5 okt 16:20, the moment its bezwaar run
started (the board's today is 7 okt). The example set could not say when a task was created, so every install
stamped the install moment. OpenRegister now reads `createdAt` on an example-set task (openregister #5585, with the
`{{datetime}}` fix in #5586).

## What changes

- The Zuiddrecht set's task `hoorzitting-inplannen` carries `"createdAt": "{{datetime:-2 16:20}}"`, the same moment
  as its run `flow-bezwaar.runs.hoorzitting-havenstraat` was created, and before its `claimedAt`.

## Scope

Data only. A refresh adopts the existing task and keeps its `created`, so on an instance that already has the task
it must be removed as a flow task before `occ openregister:example-set:install zuiddrecht --refresh`.
