# Proposal: cases-filter-labels-for-views

## Why

DqFilterBewerken and DqFilterInMenu on :8080 (24 Oct, r25b): a saved view's criteria read "Wacht op inwoner ja" and the
Active line named the handler "Toegewezen aan", where the boards say "Status: wacht op inwoner" and "Behandelaar".

## What changes

Cases `filterLabels` gain `waitingOnApplicant` (label Status, true = "waiting on the applicant") and `assignee` (label
Handler). Config and catalogue only.
