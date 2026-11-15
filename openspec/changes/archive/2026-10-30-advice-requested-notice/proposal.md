# Proposal: advice-requested-notice

## Why

DqZaak draws a notice at the top of a case's side column while advice is still asked on the case: "Advies gevraagd aan
Mobiliteit en verkeer. Antwoord uiterlijk 10 oktober. Afronden kan pas daarna." Live drew nothing: no part of the case
page said that open advice holds up closing.

## What changes

The simple profile's case page opens its side column with a banner (configuration only) that shows while an open advice
request (`adviesAanvraag`, status `requested`) points at this case, names the soonest due one's advisor and date, and
says closing waits until then. It uses nextcloud-vue's banner scoped to the page's record (notice-scoped-to-the-record).
