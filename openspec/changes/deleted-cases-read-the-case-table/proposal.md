# Proposal: the deleted cases lens reads the case table

## Why

The Deleted cases page (`/cases/deleted`) span or failed on :8080 (23 Oct). Its endpoint, `GET /api/cases/deleted`, asked OpenRegister for every soft-deleted row in EVERY magic table (`findDeletedAcrossAllMagicTables`), which took 20 to 32 seconds as admin on the example set, and it cut that list to one page (50 rows) BEFORE keeping the cases. On an instance where other apps delete objects, deleted cases past the first page of everybody's deletions never showed at all, so the page could say "Nothing was deleted" while cases waited in the trash.

## What changes

- `CaseRecycleService::deletedCases()` reads the soft-deleted rows of the case table only (`searchObjectsInRegisterSchemaTable` with `_includeDeleted` and `@self.deleted IS NOT NULL`), keeps what the caller may see, sorts newest first, and pages AFTER that, so the total and the pages agree.
- The endpoint takes a `search` term (case number, title, deleter), so the page's search box narrows the list like it does on All cases.
- The page turns on its search box, the count line in the sidebar and saved views, in the look of the All cases page. Restore and Destroy stay as they are.

## Not changed

Who sees a row (admin, the assignee, the deleter), the recovery window, restore and destroy.
