# Proposal: leges-heffingen voor zaaktype-gestuurde aanvragen

## Why

Nederlandse gemeenten heffen leges voor een breed scala aan publieke dienstverlening: paspoorten, rijbewijzen, uittreksels BRP, omgevingsvergunningen, APV-vergunningen, evenementenvergunningen, drank-en-horecavergunningen, ontheffingen, en duizenden andere gemeentelijke producten. De legestarieven worden jaarlijks (en soms vaker) vastgesteld in een gemeentelijke legesverordening — een raadsbesluit met een bijlage tarieventabel die vaak honderden of duizenden regels bevat.

In de huidige werkpraktijk worden leges **handmatig** berekend door medewerkers met Excel-tabellen of maatwerk-koppelingen in legacy zaaksystemen. Dit leidt tot:

- **Fouten**: verkeerd tarief, vergeten kortingen, inconsistentie tussen medewerkers
- **Vertraging**: geen automatische facturering, dubbel werk (zaaksysteem → Excel → boekhouden)
- **Compliance-problemen**: geen volledig audit-trail, lastige restitutie bij ingetrokken aanvragen
- **Financiële slecht zicht**: openstaande facturen, schakelingen met debiteurenadministratie gebeuren handmatig

De `leges-heffingen` capability brengt een eersteklas, transparante, audit-proof leges-berekeningsengine in procest die automatisch juiste tarieven toepast op basis van zaak-attributen, kortingen verwerkt, facturen creëert in shillinq accounts-receivable, en historisch correcte tarieven gebruikt voor zaken die over jaargrens heen lopen.

## What

Een geïntegreerde **leges-beheer + berekening + facturering** oplossing voor procest, gebouwd op:

1. **Tarief-management**: importeer jaarlijkse tariefverordeningen als gestructureerde LegesTariefTabel-objecten (niet Excel-files) met volledige versiehistorie, automatische diff-detailing, en ondersteuning voor jaarlijkse updates.

2. **Automatische tariefberekening**: bij zaak-aanmaak bepaalt de leges-engine automatisch het juiste tarief op basis van zaak-attributen (bouwsom, oppervlakte, leeftijd aanvrager, spoedaanvraag, etc.) en selecteert correcte variants.

3. **Kortingen & vrijstellingen**: systeem past automatisch kortingen toe (65-plus vrijstelling, minima-regeling, herhaalaanvraag-korting) op basis van vooraf-gedefinieerde regels en externe verifikatie (BRP-leeftijd, inkomensregister).

4. **Facturering in shillinq**: LegesBerekening → shillinq AR met juiste grootboekrekening, BTW-behandeling, en kostendrager. Restitutie verwerkt als creditfactuur bij zaak-afsluiting.

5. **Audit & transparantie**: elke berekening is traceerbaar (welke tabel-versie, welk tarief, welke kortingen, waarom), voor controllers, accountants, en burgers.

## Capabilities

### New Capabilities

- `leges-tariefbeheer`: import, versioning, en diff-tracking van jaarlijkse legesverordeningen
- `leges-automatische-berekening`: op zaak-aanmaak, bereken correct tarief o.b.v. zaak-attributen en tariefvarianten
- `leges-kortingen-toepassing`: automatisch herkennen en toepassen van kortings- en vrijstellingsregels
- `leges-facturering-shillinq`: LegesBerekening → shillinq AR factuur met juiste boeking
- `leges-restitutie`: creditfactuur bij ingetrokken zaak of bezwaar gegrond met gestaffelde terugbetaling
- `leges-jaargrens-tarieven`: historisch correcte tarief voor zaken aangemaakt voor jaarwisseling, ook als beschikking later wordt gegeven
- `leges-audittrail`: volledige audit-log per berekening met context, variant-keuze, kortingen, en berekeningsToelichting

### Modified Capabilities

- `zaak-create`: zaak-aanmaak-flow triggert nu automatisch LegesBerekening (indien zaaktype gekoppeld aan leges-tarief)

## Affected Projects

- [x] **Project: procest** — alle implementatie-werkzaamheden; schema-wijzigingen, berekeningslogica, UI voor tarief-beheer
- **Reference: shillinq-accounts-receivable** — procest stuurt factuur-creatie requests naar shillinq AR
- **Reference: decidesk** — gemeentelijke raadsbesluiten met legesverordeningen; procest importeert vanuit decidesk
- **Reference: openregister-abac-policy-engine** — autorisatie wie tariefverordeningen mag importeren, wie restituties mag goedkeuren
- **Reference: pipelinq / openconnector** — voor inkomensverifikatie (minima-vrijstelling) via BRP en gemeentelijke minima-registratie
- **Reference: mydash** — leges-dashboards: opbrengsten per tariefnummer, restitutiepercentages, openstaande facturen

## Scope

### In Scope

- 6 nieuwe entities: LegesTariefTabel, LegesTarief, LegesVariant, LegesKorting, LegesBerekening, LegesRestitutie (ADR-000 update)
- Tarief-management UI: upload/import legesverordening, versiehistorie, diff-viewer, status management
- Automatische tariefberekening engine met variant-selectie en conditie-evaluatie
- Kortings- en vrijstellingsregels (percentage, vast bedrag, volledige vrijstelling) met externe verificatie (BRP, inkomensregister)
- Integration met shillinq AR: factuur-creatie, BTW-codering, kostendrager, betalingstermijn
- Restitutie-flow: creditfactuur, staffeling (% over tijd), motiveringplicht
- Jaargrens-tarieven: peildatum-rule, historische tariefversie-selectie
- Audit-trail per berekening (berekeningsToelichting, appliedKortingen, wie, wanneer, waarom)
- 10 requirements (REQ-LEGES-001 tot REQ-LEGES-010)

### Out of Scope

- Bouwsom-intake formulier (onderdeel zaak-create flow; leges-heffingen assumed bouwsom is al in zaak-attributen)
- Multi-valuta ondersteuning (alle bedragen in EUR)
- Korting-goedkeuringswerkflow met parafering (automatische toepassing o.b.v. regels; handmatige overschrijving is toekomstige feature)
- Self-service burger-portal voor restitutie-aanvragen (burgers zien berekening; restitutie wordt initieert door behandelaar)
- Integratie met externe belastingdienst-koppeling voor jaarlijkse tarief-import (verordening-import is handmatig van decidesk)

## Success Criteria

- `openspec validate --strict leges-heffingen` exits 0.
- Alle 6 entiteiten (LegesTariefTabel, LegesTarief, LegesVariant, LegesKorting, LegesBerekening, LegesRestitutie) aanwezig in procest_register.json schema.
- Procest kan LegesTariefTabel-versie importeren, versiehistorie bijhouden, diff tonen.
- Bij zaak-aanmaak met gekoppeld leges-tarief wordt automatisch LegesBerekening aangemaakt.
- Kortings-toepassing werkt correct (conditie-evaluatie, externe verificatie, bedragberekening).
- Factuur wordt aangemaakt in shillinq AR met juiste details (grootboekrekening, BTW, kostendrager).
- Restitutie-creditfactuur wordt aangemaakt bij zaak-afsluiting met restitutie-staffeling.
- Jaargrens-zaken gebruiken historisch correcte tarief (peildatum-regel).
- Audit-trail per LegesBerekening is volledig en traceerbaar.
- Admin kan alle tariefparameters configureren (tariefnummers, bedragen, varianten, kortingen, BTW, großboekrekeningen).
- QA testen alle 10 requirements per persona (burgerzaken-medewerker, vergunningverlener, financieel medewerker, belastingadviseur).
