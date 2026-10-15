---
kind: code
depends_on: []
---

# Proposal: a-file-written-without-a-user-is-projected-as-the-system

## Why

Installing the zuiddrecht example set on a fresh instance (NC 32.0.13, 15 October) logged the same error 120 times:

> Dossiq documents: the projection of a file event failed: User 'Anonymous' does not have permission to 'create' objects in schema 'Informatieobject'

The example set writes files into case folders from the command line, where nobody is signed in. `CaseFolderNodeListener` hands each file to `DocumentProjectionService`, whose OpenRegister writes then run as the anonymous user and are refused. The files stay, but none of them gets its `informatieobject` record or its join to the case, so the case's document list and everything that counts documents (the assessment of a Woo request among them) see nothing.

## What changes

When a file event arrives with no signed-in user, the listener runs the projection inside OpenRegister's `runAsSystem()`. A file a signed-in user writes is projected as that user, unchanged. A file outside the `Open Registers` tree is still dropped before anything is asked.

## Security

The elevation is scoped to the projection of one file that is already in a case folder, and only when there is no user whose rights could be checked. Its inputs are the file node and the case folder it sits in, not request data.

## Rollback

Revert the commit. Files written without a user are then left without a record again.
