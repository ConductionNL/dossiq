# Archief en e-Depot — beheerdersgids

Procest draagt afgeronde zaken op een controleerbare manier over aan een gemeentelijk of regionaal e-Depot conform GiHandover/MDTO. Deze gids is geschreven voor de **DIV-beheerder** en de functioneel beheerder: bewaartermijnregels configureren, batchoverdrachten initiëren, bewijsstukken raadplegen en problemen oplossen.

> **Specs:** `openspec/changes/archief-edepot-handover-01-schema-config` t/m `archief-edepot-handover-08-admin-ui-docs`
> **Entiteiten:** `BewaarTermijnRegel`, `OverdrachtTrigger`, `SipBundel`, `OverdrachtTransactie`, `ArchiefBewijs`, `OverdrachtAuditLog`
> **Status:** in opbouw — admin-UI en dashboard komen in member-08.

## Wat doet de archief-pipeline?

1. Een zaak bereikt een eindstatus die in `BewaarTermijnRegel` een bewaartermijn raakt → er wordt een `OverdrachtTrigger` aangemaakt.
2. Op de geconfigureerde overdrachtsdatum bundelt Procest metadata en documenten in een SIP-bundel (GiHandover BagIt + MDTO XML).
3. De SIP wordt via `openconnector` naar het e-Depot gestuurd; de uitwisseling wordt vastgelegd in `OverdrachtTransactie`.
4. Het e-Depot retourneert een acceptatie/proof; Procest slaat het bewijs op in `ArchiefBewijs` en logt elke stap in `OverdrachtAuditLog`.

## 1. Bewaartermijnregels configureren

Bewaartermijnregels koppelen een zaaktype aan een bewaarduur en een selectielijstcategorie.

### Eerste keer — VNG-defaults

Bij installatie zijn er drie voorbeeldregels geseed:

| Zaaktype | Bewaartermijn | Selectielijst |
|----------|---------------|---------------|
| `omgevingsvergunning` | 5 jaar | Selectielijst gemeenten 4.1.3 |
| `wmo-aanvraag` | 10 jaar | — |
| `subsidie-verlening` | permanent | — |

Pas ze aan vóór livegang aan jullie eigen selectielijst.

### Regel toevoegen of wijzigen

1. Open **Beheer → Procest → Archief → Bewaartermijnregels**.
2. Klik **Regel toevoegen** of selecteer een bestaande regel.
3. Vul in:
    - **Zaaktype** — dropdown van gepubliceerde zaaktypen.
    - **Bewaartermijn** — geheel getal in jaren of `permanent`.
    - **Selectielijst-categorie** — vrije tekst, bv. `Selectielijst gemeenten 4.1.3`.
    - **Trigger** — vanaf welk moment de bewaartermijn loopt (`zaakAfgesloten`, `besluitOnherroepelijk`, `eindBezwaarTermijn`).
    - **Verlengingsgrond** — optioneel, voor zaken die wettelijk langer bewaard moeten blijven.
4. **Opslaan**. De regel werkt door op alle nieuwe en bestaande zaken die deze trigger nog niet hebben gepasseerd.

### Validaties

- `bewaartermijnJaren` ≥ 1 of `permanent`.
- `zaaktypeKey` moet bestaan en gepubliceerd zijn.
- Een (`zaaktypeKey`, `trigger`)-combinatie is uniek; je kunt geen tweede regel voor hetzelfde paar opslaan.

### Regel verwijderen

Een regel verwijderen verandert **niets** aan reeds aangemaakte triggers of overgedragen zaken. Het stopt alleen nieuwe triggers voor zaken die nog binnen de scope vallen.

## 2. Dashboard en monitoring

Het dashboard **Procest → Archief → Overzicht** toont:

- **Statkaarten**:
    - `Ready` — triggers waarvan de overdrachtsdatum gepasseerd is en die nog niet zijn opgepakt.
    - `In Progress` — actieve `OverdrachtTransactie`-records.
    - `Failed` — transacties met fout-status, wachtend op retry of correctie.
    - `Completed` — succesvol bevestigd door het e-Depot.
    - `Total Transferred` — cumulatief totaal sinds livegang.
- **Triggers-tabel** — alle openstaande triggers met zaaknummer, regel, geplande overdrachtsdatum.
- **Batch-jobs tabel** — laatste 50 batchruns met start, doorlooptijd, aantal SIP-bundels, en eindstatus.
- **Snelle acties**:
    - **Batch starten** — start een ad-hoc batchrun voor alle Ready-triggers.
    - **Mislukte opnieuw proberen** — herstart alle Failed-transacties met de oorspronkelijke SIP-bundel.
    - **Bewijs bekijken** — opent de `ArchiefBewijs`-detailweergave voor een geselecteerde zaak.

## 3. Batchverwerking

De daemon draait dagelijks (cron via `BackgroundJob`) en pakt alle Ready-triggers op. Voor handmatige verwerking is er **Batch starten**.

### Een handmatige batch

1. Klik op **Batch starten**.
2. Filter optioneel op zaaktype of overdrachtsdatum-bereik.
3. Bevestig het aantal triggers (max 200 per batch).
4. Volg de voortgang in real-time via de WebSocket-update op het dashboard.
5. Na afronding verschijnt een rapport: succesvol / mislukt / wachtend op e-Depot bevestiging.

### Concurrency

Procest verwerkt maximaal **5 SIP-bundels gelijktijdig** (geconfigureerd in `lib/Settings/ArchiefAdmin.php` → `archief_max_concurrent`). Dit beschermt de e-Depot-API tegen overbelasting.

## 4. Bewijs van overdracht (Proof of Transfer)

Voor elke succesvol overgedragen zaak slaat Procest een `ArchiefBewijs` op met:

- `eDepotReceiptId` — de ontvangstbevestiging van het e-Depot.
- `checksumSha256` — checksum van de SIP-bundel, voor latere verificatie.
- `acceptanceTimestamp` — wanneer het e-Depot accepteerde.
- `mdtoBundleReference` — pad naar de gearchiveerde MDTO-bundle.

Het bewijs is **immutabel**. Verwijdering vereist een formeel vernietigingsbesluit en wordt afzonderlijk geregistreerd in `OverdrachtAuditLog`.

### Verifiëren

1. Open de zaak → tab **Archief**.
2. Klik op **Bewijs valideren**. Procest:
    - Haalt de SIP-bundel uit het archief.
    - Berekent de checksum opnieuw.
    - Vergelijkt met `ArchiefBewijs.checksumSha256`.
3. Bij mismatch: melding in audit log, en het bewijs wordt gemarkeerd als `verificatieFailed` voor handmatig onderzoek.

## 5. Rollback (corrigerende overdracht)

In zeldzame gevallen moet een overdracht worden teruggetrokken (bv. ontdekt dat verkeerde documenten zijn meegebundeld).

1. Open de transactie via **Archief → Transacties** en kies **Rollback aanvragen**.
2. Voer motivatie en autoriserende rol in.
3. Procest stuurt het rollback-verzoek naar het e-Depot via openconnector.
4. Na bevestiging worden:
    - `OverdrachtTransactie.status` op `rolledBack` gezet.
    - Het bijbehorende `ArchiefBewijs` op `ingetrokken` gezet (niet verwijderd, voor audit).
    - Een nieuwe trigger aangemaakt zodra de fout is gecorrigeerd.

Niet alle e-Depots ondersteunen rollback. Bij ontbreken stuurt Procest een **correctie-bundle** in plaats van een rollback; gedrag is per e-Depot-adapter configureerbaar.

## 6. Audit en BIO-conformiteit

Elke gebeurtenis (rule wijziging, trigger aanmaak, SIP-creatie, submission, accept, reject, retry, rollback, proof validatie) wordt vastgelegd in `OverdrachtAuditLog` met `actor`, `timestamp`, `payloadHash`. De log is append-only.

- **BIO 8.3.1 (Logging)** — actor, tijd, gebeurtenistype, identificatie van de zaak. Geen inhoud van documenten.
- **MDTO** — XML wordt gegenereerd uit `case`-metadata en gevalideerd tegen de actuele XSD vóór bundeling.
- **GiHandover BagIt** — `bagit.txt`, `bag-info.txt`, `manifest-sha256.txt`, `tagmanifest-sha256.txt` worden meegeleverd in elke bundle.

## 7. Troubleshooting

| Symptoom | Oorzaak | Oplossing |
|----------|---------|-----------|
| Triggers blijven op `Ready` staan | Daemon-cron staat uit | Controleer `occ background-job:list` op `lib/Cron/ArchiefDaemon.php`. |
| SIP-creatie faalt met "MDTO validation failed" | Zaak mist verplichte metadata | Vul ontbrekende velden aan en klik **SIP opnieuw genereren**; audit log toont de specifieke XSD-fout. |
| Transactie hangt op `submitting` | e-Depot endpoint timeout of 5xx | Check **Beheer → openconnector** voor de gebruikte connection; verhoog timeout of wacht op de retry-job (max 5 attempts). |
| Bewijs validatie geeft mismatch | SIP-bundle in archief was aangepast (bv. anti-malware) | Stop sanitisatie op het archiefpad; herstel via rollback + opnieuw aanmaken. |
| Dashboard toont 0 in alle stat-kaarten | DIV-rol mist `procest_archief_view` permission | Voeg de rol toe in **Beheer → Procest → Rollen**. |
| Rollback wordt afgewezen | Doel-e-Depot ondersteunt geen rollback API | Bouw de correctie als nieuwe SIP en stuur die in als `correctieVan` → oorspronkelijke transactie. |

## 8. Veelgestelde vragen

**Wat gebeurt er met een zaak die nooit een eindstatus bereikt?**
Geen trigger; geen overdracht. De zaak blijft in Procest tot DIV besluit hem alsnog (mogelijk vervroegd) over te dragen.

**Kan ik een handmatige overdracht starten voor één specifieke zaak?**
Ja, via **Zaak → Acties → Overdracht initiëren**. Een ad-hoc `OverdrachtTrigger` wordt aangemaakt met datum = vandaag.

**Hoe wijzig ik de bestemming (welke e-Depot)?**
Configureer in `openconnector`: een nieuwe connection met de juiste endpoint en credentials, en koppel die als default in **Beheer → Procest → Archief → e-Depot-connectie**.

**Mag een Procest-gebruiker een `ArchiefBewijs` verwijderen?**
Nee. Verwijdering is alleen mogelijk met een formeel vernietigingsbesluit; de UI biedt deze knop niet. Voor het correct verwijderen van data uit het e-Depot zelf: contacteer DIV.

## Specs

- `openspec/changes/archief-edepot-handover-01-schema-config/specs/archief-edepot-handover/spec.md`
- `openspec/changes/archief-edepot-handover-08-admin-ui-docs/proposal.md`
- `openspec/architecture/adr-000-data-model.md` — entries `BewaarTermijnRegel`, `OverdrachtTrigger`, `SipBundel`, `OverdrachtTransactie`, `ArchiefBewijs`, `OverdrachtAuditLog` (worden geseed door member-01).
