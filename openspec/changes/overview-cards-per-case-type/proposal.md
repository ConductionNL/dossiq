# Overview cards per case type

## Why

The domain boards (DqBezwaar, DqSociaalDomein) draw only their own cards on
the Overview tab; the case page added the generic request, case data,
priority, locations, plan, term, flows and hours cards under them. A domain
page is case type configuration (Ruben, 18 October 2026), like the side cards.

## What changes

- The caseType schema (1.25.0) gains `overviewCards`: the Overview cards a
  case of this type shows, by id. While it is empty the generic cards show,
  so nothing changes for an existing case type.
- The generic Overview cards carry `visibleWhen: @context.caseType.overviewCards
  empty`; the domain cards keep their own conditions.
- A summary card (`case-summary`, "De melding"): the case's description with
  its case type, legal basis, reporter, deadline and handler, for the social
  domain.
- jeugdwet and bezwaar-beroep (1.3.0) list their Overview cards; the
  register-seeded Bezwaar case type carries the same list.

## Not yet

A listed card cannot yet set its order or switch on a generic card: that needs
a `visibleWhen` operator for "the list contains this id" in nextcloud-vue.
