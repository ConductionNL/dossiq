# Tabs per case type

## Why

The domain boards draw their own tab sets: DqBezwaar Overzicht, Hoorzitting,
Advies, Beslissing, Bestanden, Historie; DqSociaalDomein Overzicht, Onderzoek,
Indicatie, Overleg, Bestanden, Historie; DqAanbesteding Overzicht,
Inschrijvingen, Publicaties, Bestanden, Historie; DqSubsidie Overzicht,
Beschikkingen, Voorschotten, Vaststelling, Kosten, Historie. The case page
draws one set for every case.

## What changes

- The caseType schema (1.26.0) gains `tabs`: the case page's tabs a case of
  this type shows, in order, by tab id. Empty keeps the declared tabs.
- The Overview, Contact and Tasks tabs get ids; the case page reads the list
  through `tabsFrom` (nextcloud-vue item 62).
- bezwaar-beroep 1.5.0, jeugdwet 1.4.0, aanbesteding 1.4.0 and subsidie 1.3.0
  list their boards' tabs; the register-seeded Bezwaar case type too.

## Not drawn

Tabs with nothing behind them are left out of the lists rather than faked:
Onderzoek (social domain), Publicaties (tenders), Vaststelling and Kosten
(subsidies).
