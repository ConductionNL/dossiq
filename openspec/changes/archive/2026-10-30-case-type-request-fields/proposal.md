# A case type lists the fields its request card shows

## Why
The request card ("Het verzoek") drew only the case description. A Woo request is about its own scope, period and kinds of document (DqZaak draws them as text and pills). Another case type has other fields, so the list is case type configuration.

## What changes
- caseType gains `requestFields` (1.43.0): the facts entries the request card shows. Empty keeps the description.
- The request card reads it through nextcloud-vue's facts `fieldsFrom` (`@context.caseType.requestFields`).
- The Zuiddrecht Woo type (version 2) lists `wooRequest.omschrijving`, a period pill in years and a pill of the kinds of document.
