# Termijnbewaking & Dwangsom Engine

Procest bewaakt wettelijke beslistermijnen onder de Algemene wet bestuursrecht
(AWB) en het Wabo regime. Bij overschrijding kan een burger een
ingebrekestelling indienen die — na 14 dagen grace — een dwangsom doet
oplopen. Deze module modelleert die volledige levenscyclus.

## Wat doet de module

- **Schema's** voor `TermijnDefinitie`, `TermijnInstance`, `TermijnGebeurtenis`,
  `Ingebrekestelling`, `DwangsomBerekening`, `DwangsomUitbetaling`. Definities
  zijn versie-gedragen via `validFrom`/`validUntil`.
- **TermijnService** bindt bij elke nieuwe zaak een termijn aan de zaaktype
  (AWB 4:13) en schrijft een `start`-event in de audit-log.
- **PauseService** (AWB 4:5 / 4:15) en **ExtensionService** (AWB 4:14)
  registreren onderbrekingen en verlengingen, met handhaving van de wettelijke
  limieten (één verlenging, max-duur) en een supervisor-override-pad met
  aparte audit-trail.
- **DailyTermijnScanJob** bekijkt elke nacht (default 01:00 UTC) alle lopende
  termijnen, bucket-eert ze op 14/7/2/0 dagen tot deadline en triggert
  escalatie-notificaties (behandelaar → teamleider → manager).
- **IngebrekestellingService** valideert een binnenkomende ingebrekestelling
  (vereist `status = overschreden`), spawnt eenmalig een
  `DwangsomBerekening` met 14-daagse grace en blokkeert dubbele aanmaak.
- **DwangsomCalculationService** rekent dagelijks (€23 → €35 → €45, max
  €1.442 — of overrides per zaaktype zoals het Woo-regime €15/dag, max €500).
- **DwangsomUitbetalingService** bereidt de betaal-signalering voor zodra de
  beschikking valt, valideert IBAN/rekeninghouder en stuurt een
  `dwangsom-payment-signal` naar openconnector. De
  `DwangsomPaymentCallbackController` neemt de bevestiging in ontvangst.
- **DwangsomBezwaarService** ondersteunt bezwaar (berekening blijft bevroren,
  uitbetaling op `on-hold-bezwaar`) en heroverweging met aangepaste bedragen.
- **TermijnReportingService** levert KPI-dashboard, kwartaalrapport en jaarlijks
  dwangsom-jaarrekening (CSV/JSON/HTML).

## REST-eindpunten

| Verb   | URL                                                    | Doel                                |
|--------|--------------------------------------------------------|-------------------------------------|
| POST   | `/api/termijn/instances`                               | Nieuwe TermijnInstance aanmaken     |
| GET    | `/api/termijn/instances/{id}`                          | TermijnInstance opvragen            |
| POST   | `/api/termijn/instances/{id}/pauze`                    | Pauze registreren (AWB 4:5/4:15)    |
| POST   | `/api/termijn/instances/{id}/hervat`                   | Hervatten na aanvulling             |
| POST   | `/api/termijn/instances/{id}/verleng`                  | Verlenging aanvragen (AWB 4:14)     |
| POST   | `/api/termijn/instances/{id}/voltooi`                  | Beschikking-stop registreren        |
| POST   | `/api/termijn/ingebrekestellingen`                     | Ingebrekestelling indienen          |
| GET    | `/api/termijn/ingebrekestellingen/{id}`                | Ingebrekestelling opvragen          |
| GET    | `/api/termijn/dwangsom/{id}`                           | Dwangsom-staat opvragen             |
| POST   | `/api/termijn/dwangsom/{id}/beschikking`               | Beschikking registreren             |
| POST   | `/api/termijn/dwangsom/{id}/bezwaar`                   | Bezwaar registreren                 |
| POST   | `/api/termijn/dwangsom/{id}/bezwaar/heroverweging`     | Heroverweging vastleggen            |
| GET    | `/api/termijn/dashboard/kpi`                           | Dashboard KPI                       |
| GET    | `/api/termijn/reports/kwartaal`                        | Kwartaalrapport                     |
| GET    | `/api/termijn/reports/jaarrekening`                    | Jaarlijks dwangsom-rapport          |
| POST   | `/api/procest/openconnector/dwangsom-payment-callback` | Webhook van openconnector (publiek) |

## Configuratie

Configureer per zaaktype een `TermijnDefinitie` (Admin → Termijndefinities).
De volgende drie zijn standaard geseed via `lib/Settings/termijnbewaking_seed_data.json`:

- **`omgevingsvergunning-regulier`** — 56 dagen (Wabo 3.9), maximaal 1
  verlenging van 42 dagen, pauze 14 of 28 dagen.
- **`wmo-melding`** — 42 dagen (Wmo 2015 art 2.3.5), geen verlenging.
- **`woo-verzoek`** — 28 dagen (Woo art 4.4), 1 verlenging van 14 dagen,
  custom dwangsom-regime €15/dag, plafond €500, grace 14 dagen.

App-config-sleutels:

- `dwangsom_callback_secret` — HMAC-signing secret voor openconnector callback.
- `termijn.block_on_missing_definition` — `true` om zaak-aanmaak hard te
  blokkeren wanneer geen passende definitie bestaat (default `false`).

## Troubleshooting

- **Geen `TermijnInstance` na zaak-aanmaak**: controleer dat er een
  `TermijnDefinitie` is met `zaaktype` exact gelijk aan de zaaktype-slug en
  een `validFrom` ≤ vandaag. De listener logt op `debug` als er geen match is.
- **Daily scan slaat een rij over**: per-instance failures worden gelogd maar
  stoppen de batch niet. Zoek in `data/nextcloud.log` op `tag:procest-termijn`.
- **Dwangsom blijft op €0**: de berekening start pas 14 dagen ná de
  ontvangstdatum van de geldige ingebrekestelling (AWB 4:17 grace).
- **Webhook-callback geeft 401**: verifieer `dwangsom_callback_secret` matcht
  het ondertekeningsgeheim van openconnector.
