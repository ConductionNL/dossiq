# A review case dates its files in full

## Why

DqZaakDocumenten draws each document as "PDF · 1,2 MB · 14 januari 2026": the long date with the year. The short date reads "14 jan".

## What changes

- The review variant of the `case-files` secondLine uses `modified|longdate:year`. The plain case keeps the short date (DqDocumentenBeheer "4 okt").
- Needs nextcloud-vue fix/files-second-line-modified-date, which makes the date show at all.
