# Proposal: task-case-card-handler-name

## Why

Board DqTaak's Zaak card reads "Behandelaar Pieter Jansen · termijn van de zaak 9 okt". Live (r25b, 24 Oct) reads
"Behandelaar esra.demir": the case's assignee as a user id.

## What changes

The task page's Case card formats the case assignee as a user (`format: "user"`, the display name). Config only.
