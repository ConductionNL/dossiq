# Suspend asks the return day as a date

## Why
DqVolgendeStap draws "De zaak komt terug op (niet verplicht)" in the Opschorten form as a date the handler can change,
prefilled from the days the applicant is given. Live showed it as read-only text. The coordinator ruled on 24 Oct to
build it, with the suspend API unchanged.

## What changes
The suspend form's days input declares nextcloud-vue's `dateField` instead of a `preview`. The date starts at today plus
the days, and a chosen date sets the days (whole days from today, at least 1), which is what the act posts. Needs
nextcloud-vue feat/r25b-days-input-as-date.
