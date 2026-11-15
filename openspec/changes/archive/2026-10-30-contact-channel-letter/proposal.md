# The paper letter is a contact channel

## Why

A municipality still receives and sends letters. DqZaakContact draws a
"Brief" chip beside Mijn Zuiddrecht, E-mail and Telefoon, and the coordinator
decided (24 Oct) to add it. The contact moment's channel had no value for it,
so a letter could only be logged as something it was not.

## What changes

- contactmoment schema 1.6.0 (minor): `notificationChannel` gains `brief`,
  and the enum carries its labels (`x-enum-labels`, "Letter", Dutch "Brief").
- ContactMomentService accepts it, the timeline kind lists it, pipelinq's
  bridge passes it on as `brief` (pipelinq's own letter channel).
- The Log contact dialog offers it; the case's conversation chips, the
  contact-moments table (label and icon) and the contact page's feed icons
  know it.
