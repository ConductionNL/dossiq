# Proposal: procesanalyse-csv

## Why

The DqProcesanalyse board draws "Downloaden" in the header. The page had no way to take its figures along.

## What changes

`GET /api/reports/process-mining?format=csv` answers the days per phase per case type the page's matrix shows, as
the mean or, with `statistic=median`, the median, for the requested case type. It is the same endpoint, role check
and filter the page reads, so a reader outside the controller, beheerder or admin groups is refused before anything
is read. Cells go through the shared `CsvWriter` (doorlooptijd-csv), and a phase a case type never passes reads
"none" in the reader's language. The Procesanalyse page gets a "Download" header action that GETs it with the
page's statistic and case type (nextcloud-vue api-call-get-download).
