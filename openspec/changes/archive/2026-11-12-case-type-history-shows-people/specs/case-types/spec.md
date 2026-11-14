## ADDED Requirements

### Requirement: A case type's side history shows what people changed

The side History card of a case type SHALL leave out the entries System wrote (refreshes, upgrades, example-set upkeep). The case type's All history tab SHALL keep every entry.

#### Scenario: DqZaaktype after a window refresh
- GIVEN Lars de Koning published version 4 and changed the variants, and the daily refresh saved the case type as System since
- WHEN the case type opens on Fasen en taken
- THEN its History card lists "Versie 4 gepubliceerd" and "Varianten gewijzigd" by Lars de Koning, and no System edit
