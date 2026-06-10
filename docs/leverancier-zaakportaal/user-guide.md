# Leverancier Zaakportaal — Gebruikershandleiding

Deze handleiding helpt leveranciersmedewerkers en gemeenteambtenaren
bij het gebruik van het leveranciersportaal.

## Inloggen met eHerkenning

1. Klik op de inlogknop op de inlogpagina van het portaal.
2. Kies uw eHerkenningsmakelaar en authenticeer.
3. Het portaal valideert uw KvK-nummer tegen de geregistreerde
   leverancier. Bij onbekende of gedeactiveerde leveranciers krijgt u
   een melding "Onbekende leverancier (KvK-nummer niet geregistreerd)"
   of "Leverancier is inactive".
4. Na succesvolle authenticatie ontvangt u een sessie van 2 uur. 15
   minuten voor het verlopen wordt de sessie stil vernieuwd; bij harde
   verlopen wordt u terug naar de inlogpagina geleid.

## Rollen en zichtbaarheid

| Rol         | Tabs zichtbaar                                                     |
|-------------|--------------------------------------------------------------------|
| admin       | dashboard, profile, tenders, contracts, invoices, messages, team   |
| finance     | dashboard, profile_limited, invoices, messages                     |
| contracts   | dashboard, profile, contracts, tenders, messages                   |
| sales       | dashboard, profile, tenders, messages                              |
| read_only   | dashboard, profile_limited, messages                               |

Alleen rollen `admin` en `contracts` kunnen een contractverlenging
aanvragen. De rol `read_only` kan geen master-datawijzigingen
indienen.

## Dashboard

Het dashboard toont vier kaarten:

- **Tenders** — totaal aantal + aantal gegund / in evaluatie / afgewezen
- **Facturen** — totaal aantal + aantal 90+ dagen te laat + aantal in
  dispuut + leeftijdsanalyse (0-30 / 31-60 / 61-90 / 90+)
- **Contracten** — totaal aantal + aantal binnen 90-dagen-venster +
  aantal met automatische verlenging
- **KPI** — beschikbaar zodra u minstens 3 facturen heeft

## Facturen

- Statusbadges: ontvangen (grijs), in beoordeling (blauw), goedgekeurd
  (groen), in dispuut (oranje), afgewezen (rood), betaald (groen).
- Bij `goedgekeurd` ziet u de verwachte betaaldatum (invoiceDate +
  routing + betalingstermijn).
- Bij 90+ dagen te laat ziet u een rode badge.
- Bij dispuut kunt u via "Reactie geven" een bericht plaatsen in de
  zaak.

## Contracten

- Bij contracten binnen 90 dagen tot vervaldatum verschijnt een
  oranje waarschuwing "Vervalt over [n] dagen".
- Bij `renewalOption: manual_request` en binnen 90 dagen verschijnt
  de knop "Verlenging aanvragen". Dit creëert een Procest-zaak
  `leverancier-contractverlenging-verzoek`.

## IBAN wijzigen (4-ogen)

Een IBAN-wijziging wordt **niet direct** toegepast. Wanneer u een
nieuwe IBAN indient (met geldige mod-97 controlecijfers), wordt er
een 4-ogen-zaak `leverancier-iban-wijziging` aangemaakt. Pas na
goedkeuring door twee gemeenteambtenaren wordt uw IBAN gewijzigd.

## KPI's

- **Gemiddelde betaaldagen** — `actualPaymentDate − invoiceDate`
  voor betaalde facturen. Uitschieters boven 200 dagen worden
  uitgesloten.
- **Op-tijd percentage** — `betaald-op-of-voor-dueDate / totaal × 100`
- **Disputerate** — `disputed / totaal × 100`
- **Compliance-score** — gewogen gemiddelde (40% op-tijd + 30%
  dispuutvrij + 30% compleetheid)
- Maanden met minder dan 3 facturen worden gemarkeerd als
  "Onvoldoende gegevens".
- Naast uw eigen waarden ziet u de gemeentelijke benchmark (gemiddelde
  over alle leveranciers).

## Berichten

- Berichten zijn write-once (immutable audit-trail).
- Bijlagen: maximaal 5 per bericht, elk maximaal 10 MB.
- Toegestane bestandstypen: PDF, PNG, JPEG, WebP, DOC(X), XLS(X).
