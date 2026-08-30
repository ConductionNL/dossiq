# Mandaat-matrix — beheerdersgids

De mandaat-matrix automatiseert het beheer van gemeentelijke mandaten conform Awb art. 10:3. Deze gids beschrijft het beheerproces voor functioneel beheerders en juridische zaken: importeren vanuit Decidesk, rolhiërarchie configureren, waarnemers toewijzen, troubleshooten en veelgestelde vragen.

> **Specs:** `openspec/changes/mandaat-matrix-01-schema-foundation/specs/mandaat-matrix/spec.md` t/m `mandaat-matrix-09-tests-and-docs`
> **Entiteiten:** `MandateringsBesluit`, `Mandaat`, `OrganisatieRol`, `MedewerkerRolToewijzing`, `MandaatGebruik`, `MandaatEscalatie`
> **Status:** in opbouw — admin-UI komt mee met `mandaat-matrix-07-admin-ui`.

## Wat doet de mandaat-matrix?

Bestuursorganen delegeren bevoegdheden via **mandateringsbesluiten** aan organisatierollen (geen personen). Een medewerker oefent een bevoegdheid uit doordat hij of zij toegewezen is aan die rol. Bij overschrijding van het plafond of bij subdelegatie zonder toestemming, **escaleert** de matrix de beslissing automatisch naar de daarvoor bevoegde rol.

De matrix bestaat uit zes met elkaar verbonden entiteiten:

| Entiteit | Doel |
|----------|------|
| `MandateringsBesluit` | Het juridisch besluit dat een set mandaten vaststelt (versie, datum, status). |
| `Mandaat` | Eén bevoegdheid binnen een besluit (welke handeling, plafond, voorwaarden). |
| `OrganisatieRol` | Een functie binnen de organisatie (Vergunningverlener, Hoofd VTH, …). |
| `MedewerkerRolToewijzing` | Wie heeft welke rol vanaf wanneer (incl. waarnemer). |
| `MandaatGebruik` | Audit-snapshot van elke daadwerkelijk uitgevoerde bevoegdheid. |
| `MandaatEscalatie` | Een geblokkeerde of geëscaleerde besluitvorming. |

## Importworkflow vanuit Decidesk

Juridische Zaken onderhoudt het mandaatregister doorgaans in Decidesk. Dossiq haalt de actuele versie op, leest de bijgevoegde Excel/CSV, vergelijkt met de huidige situatie, en presenteert een diff voor goedkeuring.

### Stap 1 — Klaarzetten in Decidesk

1. Stel het mandateringsbesluit vast in Decidesk (status `vastgesteld`).
2. Voeg de mandaattabel toe als bijlage. Verplichte kolommen:
    - `mandaatNummer` (bv. `MAN-2026-005`)
    - `omschrijving`
    - `bevoegdheidsgrondslag` (artikel + wet)
    - `gemandateerdeRol` (exacte naam, moet bestaan in `OrganisatieRol`)
    - `plafond` (bedrag in EUR, leeg = geen plafond)
    - `voorwaarden` (vrije tekst, semicolon-gescheiden)
    - `subdelegatieToegestaan` (`ja` / `nee`)
    - `geldigVanaf`, `geldigTot` (ISO-datum, leeg = onbepaald)
3. Onthoud het `besluitId` van het Decidesk-besluit.

### Stap 2 — Import starten in Dossiq

1. Open **Beheer → Dossiq → Mandaat-matrix → Import**.
2. Plak het Decidesk-besluitId of kies het uit de lijst (de koppeling met Decidesk wordt via `openconnector` opgehaald).
3. Klik **Voorbeeld genereren**. Dossiq:
    - Haalt het besluit + bijlage op.
    - Parseert de tabel (PhpSpreadsheet).
    - Valideert dat elke `gemandateerdeRol` overeenkomt met een bestaande `OrganisatieRol`.
    - Bouwt een diff `NIEUW / GEWIJZIGD / VERVALLEN` ten opzichte van het huidige besluit.

### Stap 3 — Diff beoordelen

In het diff-overzicht zie je per rij:

- **NIEUW** (groen) — een mandaat dat niet bestond in de vorige versie.
- **GEWIJZIGD** (geel) — wijzigingen in plafond, voorwaarden of subdelegatie. Het oude/nieuwe veld wordt naast elkaar getoond.
- **VERVALLEN** (rood) — een mandaat dat in deze versie verdwijnt.

Klik op een rij om de details te zien. Eventuele **rolvalidatiefouten** (verwijzing naar onbekende `OrganisatieRol`) blokkeren de goedkeuring. Maak eerst de ontbrekende rol aan (zie [Rolhiërarchie](#rolhiërarchie-beheren)) en regenereer de voorbeeld-diff.

### Stap 4 — Goedkeuren

Wanneer de diff klopt:

1. Klik **Goedkeuren en activeren**.
2. Vul de juridische datum van inwerkingtreding (`vanaf`) in.
3. Dossiq:
    - Markeert het vorige `MandateringsBesluit` als `vervallen` (op `vanaf - 1 dag`).
    - Zet het nieuwe besluit op `vastgesteld` met geldigheid vanaf de gekozen datum.
    - Plaatst de Mandaat-records in de juiste relatie tot het nieuwe besluit.

Vanaf dit moment hanteert de authorisatie-engine automatisch de nieuwe matrix.

## Rolhiërarchie beheren

De rolhiërarchie bepaalt hoe escalaties verlopen wanneer een plafond wordt overschreden.

1. Open **Beheer → Dossiq → Mandaat-matrix → Rollen**.
2. Bekijk de boomweergave: bovenaan staat (typisch) **College van B&W**; daaronder afdelingshoofden, daaronder senior- en operationele rollen.
3. Voor elke rol:
    - **Naam** (verplicht, uniek).
    - **Bovenliggende rol** (verplicht behalve voor de top) — bepaalt de escalatieroute.
    - **Beschrijving** — wordt getoond in de UI.
    - **Subdelegatie toegestaan** — bepaalt of houders van deze rol bevoegdheden mogen doorgeven aan onderliggende rollen.

Sleep rollen in de boom om de hiërarchie aan te passen. Wijzigingen krijgen direct effect op nieuwe besluiten; bestaande `MandaatGebruik`-snapshots blijven onveranderd (immutabel audit).

### Bulkimport van rollen

Voor grootschalige initialisatie staat een Excel-template klaar: **Rollen → Sjabloon downloaden**. Zie [Bijlage A](#bijlage-a--rollen-sjabloon-excel) voor de kolommen.

## Waarnemer toewijzen

Tijdens vakantie of ziekte kan een waarnemer een rol tijdelijk vervullen. Een waarnemer-toewijzing is een `MedewerkerRolToewijzing` met `toewijzingType = waarnemer`.

1. Open **Beheer → Dossiq → Mandaat-matrix → Toewijzingen**.
2. Klik **Waarnemer toevoegen**.
3. Selecteer:
    - **Te vervangen medewerker** (de hoofdhouder van de rol).
    - **Waarnemer** (de Nextcloud-gebruiker die tijdelijk de rol overneemt).
    - **Vanaf** en **t/m** (ISO-datum; verplicht om audit-mismatches te voorkomen).
    - **Reden** (vrije tekst, verschijnt in audit).
4. **Opslaan**. De authorisatie-engine erkent de waarnemer binnen de periode; daarbuiten valt het terug op de hoofdhouder.

> Per BIO 8.3.1 wordt elke besluitvorming door een waarnemer expliciet als `waarnemerFlag = true` gelogd in `MandaatGebruik`.

## Troubleshooting

| Symptoom | Oorzaak | Oplossing |
|----------|---------|-----------|
| Import faalt met "OrganisatieRol niet gevonden" | Excel-tabel verwijst naar een rol die niet bestaat | Maak de rol aan in **Rollen** of corrigeer de spelling in Decidesk. |
| Diff toont GEWIJZIGD waar niets veranderd lijkt | Verborgen whitespace of decimaal-komma vs. -punt | Excel-cel als tekst opmaken; gebruik `;` als scheidingsteken in `voorwaarden`. |
| Medewerker kan geen besluit nemen, krijgt "niet bevoegd" | Geen actieve `MedewerkerRolToewijzing` op deze datum | Controleer onder **Toewijzingen** of de toewijzing nog geldig is. |
| Besluit wordt geblokkeerd met "plafond overschreden" | Bedrag groter dan `mandaat.plafond` voor de rol | Escalatie is correct; laat het hogere echelon goedkeuren via de escalatie-inbox. |
| Subdelegatie geweigerd | `subdelegatieToegestaan = nee` in het mandaat | Wijzig het brondocument in Decidesk en re-importeer — niet ad-hoc handmatig overrulen. |
| Waarnemer verschijnt niet in audit als waarnemer | Toewijzing buiten geldigheidsperiode | Pas `vanaf`/`t/m` aan en re-genereer escalatierapport via **Rapporten → Audit**. |

## Veelgestelde vragen

**Mag ik een `MandaatGebruik`-record handmatig aanpassen?**
Nee. Het schema is write-once gemarkeerd; corrigeren gebeurt via een nieuw record met `correctieVan` verwijzing.

**Hoe ga ik om met overlappende waarnemers?**
Een rol kan slechts één actieve waarnemer tegelijk hebben. Dossiq weigert overlapping bij het opslaan; los het op door de eerste waarnemer te beëindigen voordat de tweede start.

**Wat gebeurt bij een vervallen mandaat dat nog open zaken raakt?**
Lopende zaken behouden hun originele `MandaatGebruik`-snapshot. Nieuwe besluiten op die zaak gebruiken het nieuwe besluit zodra het geldig is.

**Kan ik de matrix tijdelijk uitzetten?**
Nee. De authorisatie is integraal onderdeel van procesvoering. Voor ad-hoc beheer (bijv. urgentie buiten kantooruren) is er de noodroute in [openspec/changes/mandaat-matrix-06-temporal-and-conflict](../../openspec/changes/mandaat-matrix-06-temporal-and-conflict/proposal.md).

## Bijlage A — Rollen-sjabloon (Excel)

| Kolom | Type | Verplicht | Toelichting |
|-------|------|-----------|-------------|
| `naam` | string | ja | Unieke rolnaam (max 80 tekens). |
| `bovenliggende_rol` | string | ja (behalve top) | Exacte naam van de parent-rol. |
| `beschrijving` | string | nee | Verschijnt in UI-tooltip. |
| `subdelegatie_toegestaan` | enum | ja | `ja` / `nee`. |
| `actief` | enum | ja | `ja` / `nee` (gearchiveerde rol blijft beschikbaar voor historische audit). |

Importeer via **Rollen → Importeren**; Dossiq valideert circular references en weigert imports met cycli.

## Specs

- `openspec/changes/mandaat-matrix-01-schema-foundation/specs/mandaat-matrix/spec.md`
- `openspec/changes/mandaat-matrix-04-decidesk-import/proposal.md`
- `openspec/changes/mandaat-matrix-07-admin-ui/proposal.md`
- `openspec/architecture/adr-000-data-model.md` — entries `MandateringsBesluit`, `Mandaat`, `OrganisatieRol`, `MedewerkerRolToewijzing`, `MandaatGebruik`, `MandaatEscalatie` (worden geseed door member-01).
