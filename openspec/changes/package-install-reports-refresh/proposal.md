# A package install reports what its refresh did

## Why

`occ dossiq:package:install jeugdwet --over-local-changes` printed "0 written, 0 adopted, 33 already present" while its refresh had updated case e0b306d2. The refresh counts only went to the log, so the run read as a no-op (27 Oct, 8g).

## What changes

- The command prints a second line: "N row(s) refreshed, M kept as this instance holds them: <ids>".
