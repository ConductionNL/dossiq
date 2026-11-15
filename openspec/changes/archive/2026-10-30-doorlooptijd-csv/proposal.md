# Proposal: doorlooptijd-csv

## Why

The DqDoorlooptijd board draws "Downloaden" in the header. The page had no way to take its figures along.

## What changes

`GET /api/doorlooptijd/metrics?format=csv` answers the processing-time figures per case type and month for the
requested period and case type: closed cases, the average days they took, and the share closed within their
deadline. It is the same endpoint, audience check and filter the page reads, so a reader outside the reporting
audience is refused before anything is read. Cells are quoted and a cell that starts like a formula is prefixed
with `'`, by a shared `CsvWriter` the Woo throughput report now uses too. The Doorlooptijd page gets a "Download"
header action that GETs it with the page's period and case type (nextcloud-vue api-call-get-download).
