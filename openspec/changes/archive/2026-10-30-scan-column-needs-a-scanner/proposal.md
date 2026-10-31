# The Scan column needs a virus scanner

## Why

The Files tab's Scan column reads the verdict files_antivirus records. Without
a scanner every row reads "Niet gescand", which tells nobody anything
(coordinator decision, 25 Oct).

## What changes

- The `case-files` Scan result column declares `requiredApp:
  "files_antivirus"` (nextcloud-vue feat/files-column-required-app): without
  the scanner the column is left out, also from Columns.
- Before that library release the key is ignored and the column shows as now.
