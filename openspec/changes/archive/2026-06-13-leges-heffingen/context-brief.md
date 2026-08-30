---
status: proposed
app: procest
spec: leges-heffingen
depends_on:
  - procest base (zaaktype + zaak)
  - shillinq accounts-receivable
  - openregister abac-policy-engine
related:
  - hydra/openspec/specs/i18n-nl-en/spec.md
target_users:
  - Burgerzaken medewerker
  - Vergunningverlener (Wabo, APV, Drank & Horeca)
  - Financieel medewerker
  - Belastingadviseur gemeente
  - Burger / aanvrager
---

# Leges-heffingen voor zaaktype-gestuurde aanvragen

## Purpose

Nederlandse gemeenten heffen leges voor een breed scala aan publieke dienstverlening: paspoorten, rijbewijzen, uittreksels BRP, omgevingsvergunningen, APV-vergunningen, evenementenvergunningen, drank-en-horecavergunningen, ontheffingen, en duizenden andere gemeentelijke producten. De legestarieven worden jaarlijks (en soms vaker) vastgesteld in de gemeentelijke legesverordening, een raadsbesluit dat als bijlage een complete tarieventabel bevat met vaak honderden of duizenden regels. Deze tarieventabel is hiërarchisch opgebouwd (Titel I Algemene dienstverlening, Titel II Dienstverlening vallend onder fysieke leefomgeving / omgevingsvergunning, Titel III Dienstverlening vallend onder Europese dienstenrichtlijn) en kent vele uitzonderingen, vrijstellingen en kortingen.

In de huidige werkpraktijk worden leges vaak handmatig berekend door medewerkers met behulp van Excel-tabellen of via maatwerk-koppelingen in legacy zaaksystemen. Dit leidt tot fouten (verkeerd tarief, vergeten kortingen), inconsistentie tussen medewerkers, vertraging in de factureringscyclus, en problemen bij restitutie wanneer een aanvraag wordt ingetrokken. Bovendien is de koppeling met het financiële systeem (debiteurenadministratie, grootboek) zelden geautomatiseerd, wat dubbel werk en reconciliatie-problemen oplevert.

De `leges-heffingen` capability brengt een eersteklas leges-berekeningsengine in procest die: (1) per zaaktype een gekoppelde tarief-tabel laadt met alle varianten en uitzonderingen; (2) bij zaak-aanmaak automatisch het juiste tarief berekent op basis van zaak-attributen (oppervlakte bouwwerk, leeftijd aanvrager, type vergunning, spoedaanvraag, etc.); (3) kortingen en vrijstellingen toepast op basis van regels (ouderen-vrijstelling, minima-regeling, herhaalaanvraag-korting); (4) een factuur creëert in shillinq AR met juiste grootboekrekening, BTW-behandeling, en kostendrager; (5) restitutie verwerkt wanneer een zaak voortijdig wordt afgesloten; (6) jaarverordening-imports ondersteunt zodat tariefwijzigingen niet per regel ingevoerd hoeven worden; en (7) historisch correcte tarieven gebruikt voor zaken die over jaargrens heen lopen.

## Data Model

**LegesTariefTabel** — versionable container per gemeente per fiscaal jaar. Velden: `naam`, `geldigVanaf`, `geldigTotEnMet`, `vastgesteldDoor` (raadsbesluit-ref naar decidesk), `vastgesteldOp`, `gepubliceerdIn` (gemeenteblad-ref), `status` (`concept`, `vastgesteld`, `vervallen`), `titels` (array van Titel-objecten).

**LegesTarief** — individuele tariefregel binnen een tabel. Velden: `tariefNummer` (hiërarchisch: "1.1.1.2"), `omschrijving`, `bedrag` (decimal, in eurocenten), `grondslag` (`vast`, `oppervlakte`, `bouwsom`, `staffel`, `formule`), `eenheid` (`per_stuk`, `per_m2`, `per_uur`, `percentage`), `btwTarief` (0/9/21), `grootboekrekening`, `kostendrager`, `productCode` (gemeentelijke productcatalogus / IMG), `wijzigingsdatum`, `voorgaandTariefId` (chain naar vorige versie).

**LegesVariant** — sub-tarief binnen een hoofdtarief (bv. "Tarief A versus Tarief B"). Velden: `parentTariefId`, `variantNaam`, `condities` (JSON-rules: leeftijd, oppervlakte-range, spoedfactor), `bedragOpslag` of `bedragOverride`.

**LegesKorting** — kortings- of vrijstellingsregel. Velden: `naam` (bv. "65-plus vrijstelling rijbewijs"), `tariefIds` (welke tarieven van toepassing), `kortingsType` (`percentage`, `vast_bedrag`, `volledige_vrijstelling`), `kortingsWaarde`, `condities` (leeftijd, inkomen, herhaalaanvraag binnen X maanden), `wettelijkeGrondslag`, `geldigVanaf`, `geldigTotEnMet`.

**LegesBerekening** — concrete berekening per zaak. Velden: `zaakId`, `tariefTabelId`, `tariefId`, `variantId`, `appliedKortingen` (array met korting-id + bedrag), `bedragExclBtw`, `btwBedrag`, `bedragInclBtw`, `berekendeOp`, `berekendDoor` (system of user), `berekeningsToelichting` (audit-string), `factuurId` (shillinq-ref), `status` (`berekend`, `gefactureerd`, `betaald`, `gerestitueerd`, `kwijtgescholden`).

**LegesRestitutie** — restitutiebesluit. Velden: `berekeningId`, `restitutieReden` (`aanvraag_ingetrokken`, `dubbel_betaald`, `coulance`, `bezwaar_gegrond`), `restitutiePercentage` (vaak gestaffeld: 100% binnen 14 dgn, 50% bij start behandeling, 0% bij beschikking), `restitutieBedrag`, `creditfactuurId`, `besluitNemerId`, `besluitDatum`.

## Requirements

### REQ-LEGES-001: Tariefverordening importeren uit raadsbesluit

**GIVEN** een vastgesteld raadsbesluit "Legesverordening 2026" in decidesk met bijlage tarieventabel.xlsx of .csv
**WHEN** een belastingadviseur de import-functie in procest start en het besluit-id selecteert
**THEN** systeem creëert een nieuwe LegesTariefTabel-versie met status `concept`, parseert de tarieventabel inclusief hiërarchische nummering, valideert dat alle tarieven een grondslag en BTW-percentage hebben, toont een diff t.o.v. de vorige versie (welke tarieven veranderden, nieuw, vervallen), en plaatst de tabel in review-status met een notification naar de financiële afdeling.

### REQ-LEGES-002: Automatische tariefberekening op zaak-aanmaak

**GIVEN** een zaaktype "Omgevingsvergunning bouwactiviteit" met gekoppeld leges-tarief 2.3.1.1, een geldige LegesTariefTabel voor het huidige jaar, en een nieuwe zaak met `bouwsom: 250000`
**WHEN** de zaak wordt aangemaakt
**THEN** de leges-engine bepaalt het juiste tarief op basis van de bouwsom-staffel (bv. "3% van bouwsom met minimum €350"), berekent €7500, slaat een LegesBerekening op gekoppeld aan de zaak, en toont het bedrag op de zaak-detailpagina met een uitklapbare berekenings-uitleg ("Bouwsom €250.000 × 3% = €7.500").

### REQ-LEGES-003: Variant-selectie op basis van zaakattributen

**GIVEN** een zaaktype "Aanvraag rijbewijs" met twee varianten: Tarief A (regulier €48,75) en Tarief B (spoed €67,50)
**WHEN** een burger een aanvraag indient met `spoedAanvraag: true`
**THEN** systeem selecteert automatisch Variant B, berekent €67,50, registreert in de berekenings-toelichting "Variant B toegepast: spoedaanvraag", en logt deze keuze in de zaak-historie zodat een controller achteraf kan reconstrueren waarom dit bedrag is gebruikt.

### REQ-LEGES-004: Kortingen en vrijstellingen automatisch toepassen

**GIVEN** een burger van 67 jaar die een rijbewijs aanvraagt, en een geldige korting "65-plus vrijstelling rijbewijs verlenging" met `kortingsType: volledige_vrijstelling`
**WHEN** de leges-berekening loopt
**THEN** systeem detecteert dat de leeftijd-conditie voldaan wordt (geboortedatum uit BRP-koppeling), past de vrijstelling toe, berekent €0, slaat de toegepaste korting op in `appliedKortingen` met referentie naar de korting-id en wettelijke grondslag, en toont op de factuur de regel "Korting: 65-plus vrijstelling rijbewijs verlenging (-€48,75)".

### REQ-LEGES-005: Factuur creëren in shillinq accounts-receivable

**GIVEN** een LegesBerekening met status `berekend`, een gekoppelde shillinq-installatie, en zaakgegevens met burger-NAW en BSN
**WHEN** de zaakbehandelaar de actie "Factureren" uitvoert (of automatisch na configureerbare wachttijd)
**THEN** systeem stuurt een factuur-creatie request naar shillinq AR met: debiteur (auto-creëer of match op BSN), factuurregels (tariefomschrijving + bedrag + BTW), grootboekrekening, kostendrager, betalingstermijn (default 14 dgn), referentie naar zaak-id, en ontvangt een factuurId terug die wordt opgeslagen op de LegesBerekening; de zaak krijgt een notification "Factuur F2026-00547 verzonden" en de berekening krijgt status `gefactureerd`.

### REQ-LEGES-006: Restitutie bij ingetrokken aanvraag

**GIVEN** een gefactureerde en betaalde leges-berekening van €350 voor een omgevingsvergunning, een ingetrokken aanvraag (zaak-status `ingetrokken`), en een restitutie-staffel (100% binnen 14 dgn, 75% tot start beoordeling, 0% na beschikking)
**WHEN** de zaakbehandelaar een restitutie-besluit aanmaakt en de huidige fase is "start beoordeling"
**THEN** systeem berekent 75% restitutie = €262,50, creëert een LegesRestitutie-record, stuurt een creditfactuur-request naar shillinq AR (gekoppeld aan de originele factuur), legt het besluit vast met motivering, en stuurt een notification naar de burger met de hoogte en grondslag van de restitutie.

### REQ-LEGES-007: Historisch correcte tariefkeuze bij jaargrens-zaken

**GIVEN** een zaak aangemaakt op 20 december 2026 (tariefverordening 2026 geldig) waarvan de daadwerkelijke beschikking pas op 15 januari 2027 wordt afgegeven, en een nieuwe tariefverordening 2027 geldig vanaf 1 januari 2027
**WHEN** de leges-berekening wordt uitgevoerd en bevroren
**THEN** systeem gebruikt de tariefverordening die geldig was op de aanvraag-indieningsdatum (peildatum-regel), bewaart dat in `tariefTabelId` op de berekening, en weigert later herberekening op basis van een nieuwere verordening tenzij een belastingadviseur expliciet een herziening initieert met motivering.

### REQ-LEGES-008: Meerdere tariefwijzigingen per jaar ondersteunen

**GIVEN** een gemeente die de legesverordening twee keer per jaar wijzigt (bv. 1 januari + 1 juli) bv. vanwege wijziging van rijksregelgeving omgevingsvergunningen
**WHEN** een belastingadviseur een nieuwe verordening importeert met `geldigVanaf: 2026-07-01`
**THEN** systeem creëert een nieuwe LegesTariefTabel-versie die de vorige opvolgt, sluit de vorige automatisch af met `geldigTotEnMet: 2026-06-30`, en zorgt dat zaken die voor 1 juli zijn ingediend nog steeds de oude tarieven gebruiken terwijl zaken vanaf 1 juli automatisch op de nieuwe tarieven worden berekend.

### REQ-LEGES-009: Audit-trail per berekening

**GIVEN** elke LegesBerekening met `berekeningsToelichting` en `appliedKortingen`
**WHEN** een controller of accountant achteraf een berekening review't via de zaak-detailpagina of een audit-export
**THEN** systeem toont voor elke berekening: welke tariefTabel-versie is gebruikt, welk tariefnummer, welke variant en waarom, welke kortingen met grondslag, het BTW-percentage, de gehanteerde grondslag-waarde (bouwsom/oppervlakte/eenheden), wie de berekening initieerde (system/user), en eventuele handmatige correcties met motivering.

### REQ-LEGES-010: Inkomensafhankelijke minima-vrijstelling met BRP/inkomensregister-check

**GIVEN** een korting "Minima-vrijstelling uittreksel BRP" met conditie `huishoudinkomen <= bijstandsnorm` en koppeling naar een inkomens-bron (handmatige verklaring, inkomensverklaring belastingdienst, of gemeentelijke minima-registratie)
**WHEN** een aanvrager een uittreksel BRP aanvraagt en aangeeft minima te zijn
**THEN** systeem checkt eerst of er een geldige minima-registratie is bij de gemeente, anders toont een aanvraag-formulier voor inkomensverklaring, houdt de leges-berekening op `pending_minima_check` totdat verificatie compleet is, en past bij goedkeuring volledige vrijstelling toe (zonder factuur in shillinq, alleen administratieve registratie).

## Standards

- **Legesverordening Gemeentewet artikel 229** — wettelijke grondslag voor heffing van leges door gemeenten
- **Wet inkomstenbelasting / BTW-richtlijn** — BTW-behandeling per dienst (vrijgesteld, 9%, 21%) afhankelijk van aard van de prestatie
- **VNG Modelverordening leges** — landelijke template waarop gemeenten hun verordening baseren
- **GEMMA productcatalogus** — gestandaardiseerde productcodes per gemeentelijk product
- **NEN 7510 + AVG** — beveiliging en privacy bij verwerken van inkomensgegevens
- **eHerkenning + DigiD** — authenticatie voor aanvragen
- **iWlz / iJw / SUWI berichten** — voor inkomensverificatie bij minima-regelingen (optioneel)

## Cross-app

- **shillinq accounts-receivable** — alle leges-facturen worden gecreëerd in shillinq; restituties als creditfacturen; betalingen sync'en terug naar procest om zaak-status te updaten
- **decidesk** — legesverordeningen worden vastgesteld als raadsbesluit en geïmporteerd vanuit decidesk; mandateringsbesluit voor wie een restitutie mag toekennen via mandaat-matrix
- **openregister abac-policy-engine** — autorisatie wie tariefverordeningen mag importeren, wie restituties mag goedkeuren, wie minima-vrijstellingen mag toepassen
- **pipelinq** — voor inkomensverificatie via gemeentelijke minima-registratie of BRP/inkomensverklaring
- **openconnector** — koppelingen naar Belastingdienst (inkomensverklaring), BRP (leeftijd-check), GBA-V
- **launchpad** — leges-dashboards met opbrengsten per tariefnummer, restitutiepercentages, openstaande facturen
- **docudesk** — automatisch genereren van factuur-PDF en restitutiebesluit-brief als output van de berekening

## Target users

Burgerzaken-medewerkers behandelen dagelijks tientallen aanvragen waarvoor leges worden geheven en willen niet meer met handmatige tariefoverzichten werken. Vergunningverleners van afdeling VTH (Vergunning, Toezicht, Handhaving) berekenen leges voor complexe omgevingsvergunningen waar bouwsom-percentages, advieskosten en aanvullende onderzoeken meespelen. Financieel medewerkers willen automatische sync naar het grootboek met juiste kostendragers en BTW-codering. Belastingadviseurs van de gemeente onderhouden de tariefverordening en willen jaarwijzigingen efficient kunnen doorvoeren. Burgers krijgen vooraf inzicht in de te verwachten leges en een transparante factuur met uitleg.
