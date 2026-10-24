# Proposal: report-card-handler-name

## Why

Board DqSociaalDomein's De melding card names the handler ("Fatma Kaya"). The card read `assignee` as text, so it
showed the Nextcloud user id ("fatma.kaya").

## What changes

The `case-summary` facts card's Handler row gets `format: "user"`, the user's display name. Config only
(`src/menu-layout.simple.json`).
