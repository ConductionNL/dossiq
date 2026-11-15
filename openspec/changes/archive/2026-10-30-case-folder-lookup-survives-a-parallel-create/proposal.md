---
kind: code
---

# Proposal: case-folder-lookup-survives-a-parallel-create

## Why

On 13 October a Woo coordinator opened the Documents tab of a new Woo request and got a 500 on `GET /apps/dossiq/api/cases/<id>/dossier/linked`. The server log names the cause:

```
OCP\Lock\LockedException: "/openregister/files/Open Registers/Dossiq Register/9addbef9-..." is locked
  OC\Files\Node\Folder::newFolder
  OpenRegister FolderManagementHandler::createObjectFolderInRegister
  OpenRegister FileService::getObjectFolder
  dossiq DocumentProjectionService::folderOf            (lib/Service/Zaakdossier/DocumentProjectionService.php:258)
  dossiq LinkedDocumentsReader::linkedDocuments         (lib/Service/Zaakdossier/LinkedDocumentsReader.php:67)
  dossiq LinkedDocumentsController::index
```

The tab sends OpenRegister's folder read and dossiq's linked documents at once. On a case with no folder yet, both create it. The loser meets the lock, and the exception went out as a 500.

## What changes

1. `DocumentProjectionService::folderOf()` asks again when the folder is locked: three asks, waiting 150 ms and then 300 ms. The other request is done by then and the folder exists.
2. A folder that stays locked is a `RuntimeException('object_folder_busy')`, carrying the lock as its previous exception. The linked documents endpoint already answers that as 503.
3. A locked folder never reads as "this case has no folder".

## Out of scope

The 404 on OpenRegister's own folder read and the 403 on the audit trail for a staff user are not dossiq code. They are reported to the coordinator, not fixed here.

## Rollback

Revert the commit.
