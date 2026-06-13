# Design: Beschikking compose → ondertekenen → Berichtenbox → archief

## Architecture Overview

The beschikking pipeline spans four apps and follows a formal state-machine with eight statuses. Each transition is guarded by authorization (mandaat) or evidence (TSP rapport), logged immutably, and triggers downstream actions (Berichtenbox delivery, archival scheduling).

```
Procest (Host)
├── API: POST /api/beschikkingen (compose from zaakdata)
├── API: PATCH /api/beschikkingen/{id}/akkoord (mandaat-check + state → akkoord-mandaat)
├── API: PATCH /api/beschikkingen/{id}/onderteken (TSP → state → ondertekend)
├── API: PATCH /api/beschikkingen/{id}/verzend (Berichtenbox-route → state → verzonden)
├── Entities:
│   ├── Beschikking (full lifecycle + immutability once ondertekend)
│   ├── BeschikkingTemplate (versioned, docudesk-bound)
│   ├── MandaatRegeling (defines bevoegdheid by niveau + bedrag)
│   ├── StateMachineLog (immutable transition log)
│   └── BezwaarTrigger (auto-scheduling on verzonden)
└── Jobs:
    ├── BezwaarTermijnJob (daily: check if bezwaarTermijnEindDatum has passed; trigger archief-transfer if no bezwaar received)
    └── ArchivalJob (triggered by BezwaarTermijnJob → OpenRegister via REST)

┌─────────────────────────────────────────────────────────────────┐
│ Docudesk                                                        │
├─────────────────────────────────────────────────────────────────┤
│ POST /api/templates/{templateId}/render                        │
│   Input: { zaakId, zaakdata, mandaatGegeven, ... }            │
│   Output: { pdfBytes, checksumSha256, paginas }              │
│ GET /api/templates/{templateId}/versions                      │
│   Returns: [{ version, ingangsdatum, status }]               │
└─────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────┐
│ OpenConnector                                                   │
├─────────────────────────────────────────────────────────────────┤
│ POST /api/tsp/sign                                             │
│   Input: { pdfBytes, ondertekenaar, tspProvider }            │
│   Output: { signedPdfBytes, validatieRapportId }             │
│ POST /api/berichtenbox/send                                    │
│   Routes to: MijnOverheid (BSN) | eHerkenning OIN | Print     │
│   Output: { berichtId, verzondenOp, ontvangstBevestigingOp }  │
└─────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────┐
│ OpenRegister                                                    │
├─────────────────────────────────────────────────────────────────┤
│ POST /api/archief/ingest                                        │
│   Input: { beschikkingId, pdfBytes, tmloMetadata }           │
│   Output: { archiefId, vernietigingsdatum }                   │
└─────────────────────────────────────────────────────────────────┘
```

## State-Machine Diagram

```
┌──────────────┐
│   ONTWERP    │ ← operator fills missing fields
├──────────────┤
│ - alle velden mogen worden gewijzigd
│ - no mandaat-check yet
└────────┬─────┘
         │ "akkoord aanvragen" (REQ-BES-002)
         │
┌────────▼──────────────────┐
│   AKKOORD-MANDAAT         │ ← gemandateerd ambtenaar affirms
├───────────────────────────┤
│ - mandaatGegeven recorded
│ - still not immutable
│ - back to ONTWERP allowed
└────────┬──────────────────┘
         │ "onderteken" (REQ-BES-003)
         │
┌────────▼──────────────────┐
│   ONDERTEKEND             │ ← eIDAS-TSP completes
├───────────────────────────┤
│ - IMMUTABLE from here on
│ - TSP validatierapport saved
│ - no field edits allowed
└────────┬──────────────────┘
         │ "verzend" (REQ-BES-004)
         │
┌────────▼──────────────────┐
│   VERZONDEN               │ ← Berichtenbox accepted
├───────────────────────────┤
│ - berichtId recorded
│ - BezwaarTrigger created (REQ-BES-006)
│ - beschikking marked read-only
└────────┬──────────────────┘
         │ polling Berichtenbox
         │
┌────────▼──────────────────┐
│ ONTVANGEN-BEVESTIGING     │ ← citizen opened beschikking
├───────────────────────────┤
│ - leesBevestigingOp recorded
└────────┬──────────────────┘
         │ bezwaarTermijnEindDatum reached (REQ-BES-007)
         │ AND no bezwaarschrift received
         │
┌────────▼──────────────────┐
│   GEARCHIVEERD            │ ← OpenRegister confirmed ingest
├───────────────────────────┤
│ - TMLO/MDTO metadata embedded
│ - archief-kopie immutable
│ - vernietigingsdatum set
└──────────────────────────┘
```

## Entity Details

### Beschikking

```jsonc
{
  // Core IDs
  "id": "besch-2026-04832",           // Procest-assigned UUID or prefix-serial
  "zaakId": "zaak-2026-wmo-04832",    // Back-reference to case
  "zaaktype": "wmo-melding",          // Case type slug
  "beschikkingType": "toekenning",    // From enum: toekenning | afwijzing | wijziging | intrekking | ...
  "kenmerk": "Z/2026/04832/B01",      // User-visible decision number (RFC-format)

  // Template & Composition
  "templateId": "tpl-wmo-...-v4",     // BeschikkingTemplate UUID
  "ontwerpVersie": 3,                 // Auto-increment as template is re-rendered
  "samengesteldeInhoud": {
    "format": "pdf-a3",               // Always PDF/A-3 for archival durability
    "bestandId": "doc-2026-99231",    // Nextcloud file ID
    "checksumSha256": "a7b3...",      // For integrity verification
    "paginas": 4
  },

  // Addressee
  "geadresseerde": {
    "type": "burger",                 // burger | bedrijf
    "bsn": "123456789",               // Burger only
    "oin": null,                       // Bedrijf only
    "naam": "M.A. Janssen-de Vries",
    "berichtenboxKanaal": "mijnoverheid", // mijnoverheid | eherkenning | print-post
    "berichtenboxBevestigd": true      // Has citizen activated Berichtenbox?
  },

  // Decision Content
  "beslissing": {
    "soort": "toekenning",
    "onderwerp": "huishoudelijke ondersteuning",
    "omvang": "4 uur per week",
    "ingangsdatum": "2026-04-01",
    "einddatum": "2027-04-01"
  },
  "motivering": "Op basis van het onderzoek van 28 maart ...",
  "rechtsmiddelenClausule": "Indien u het niet eens bent met dit besluit ...",
  "legesbedrag": 0.00,

  // Status & Lifecycle
  "huidigeStatus": "ondertekend",     // ontwerp | akkoord-mandaat | ondertekend | verzonden | ontvangen-bevestiging | gearchiveerd
  "bekendmakingDatum": "2026-04-02",  // Date beschikking becomes legal (when Berichtenbox delivered)

  // Mandaat & Signing
  "mandaatGegeven": {
    "mandaatregelingId": "mr-2024-007-wmo",
    "mandaatNiveau": "afdelingsmanager",
    "akkoordDoor": "afdelingsmanager-wmo-15", // Nextcloud UID
    "akkoordDatum": "2026-04-01T14:22:00+02:00"
  },
  "handtekening": {
    "tspProvider": "kpn-gekwalificeerde-handtekening",
    "tspProviderEidasId": "NL-TSP-0001",
    "ondertekenaar": "afdelingsmanager-wmo-15",
    "ondertekeningTijdstip": "2026-04-01T14:25:33+02:00",
    "soort": "gekwalificeerde-elektronische-handtekening",
    "certificaatSerienummer": "0x7a82bc...",
    "validatieRapportId": "val-2026-99231"  // OpenConnector returns this
  },

  // Delivery
  "verzending": {
    "kanaal": "berichtenbox-mijnoverheid",
    "verzondenOp": "2026-04-02T09:00:00+02:00",
    "verzondenDoor": "systeem",
    "berichtId": "MO-2026-04-02-771234",      // Berichtenbox-assigned message ID
    "ontvangstBevestigingOp": "2026-04-03T11:42:00+02:00", // Polling result
    "leesBevestigingOp": "2026-04-04T18:55:00+02:00"       // Citizen opened message
  },

  // Archival
  "archief": {
    "gearchiveerdOp": null,              // Set by ArchivalJob on BezwaarTermijnEindDatum + no bezwaar
    "archiefId": null,                   // OpenRegister's archive ID
    "tmloMetadata": null,                // TMLO-1.2 JSON block
    "vernietigingsdatum": "2041-04-02"   // Calculated on archival
  },

  // Bezwaar & Termijn
  "bezwaarTermijnEindDatum": "2026-05-14",  // Calculated as bekendmakingDatum + 6 weeks (Awb 6:7)
  "herinneringDatum": "2026-05-07"          // When bezwaarAffair should be reminded (1 week before end)
}
```

### StateMachineLog

Every transition is immutably logged:

```jsonc
{
  "id": "smlog-2026-04832-007",
  "beschikkingId": "besch-2026-04832",
  "overgang": {
    "van": "akkoord-mandaat",
    "naar": "ondertekend",
    "tijdstip": "2026-04-01T14:25:33+02:00",
    "actor": "afdelingsmanager-wmo-15",
    "actorType": "medewerker",
    "trigger": "handmatig",
    "bewijsMateriaal": {
      "soort": "tsp-handtekening-rapport",
      "rapportId": "val-2026-99231"
    }
  }
}
```

### BezwaarTrigger

Auto-created when status transitions to "verzonden":

```jsonc
{
  "id": "bezw-trig-2026-04832",
  "beschikkingId": "besch-2026-04832",
  "bekendmakingDatum": "2026-04-02",
  "bezwaarTermijnEindDatum": "2026-05-14",
  "herinneringDatum": "2026-05-07",
  "bezwaarOntvangen": false,
  "bezwaarZaakId": null,
  "archiefTriggerActief": true,
  "archiefDatum": "2026-05-15"  // When archival is due
}
```

### MandaatRegeling

Defines who can sign what at what level:

```jsonc
{
  "id": "mr-2024-007-wmo",
  "naam": "Mandaatregeling WMO toekenningen",
  "verleendDoor": "college-bw",
  "verleendDatum": "2024-03-15",
  "intrekkingsDatum": null,
  "mandaatGroepen": [
    { "niveau": "consulent", "tot_bedrag": 5000, "zaaktypes": ["wmo-melding"], "beschikkingTypes": ["toekenning"] },
    { "niveau": "afdelingsmanager", "tot_bedrag": 25000, "zaaktypes": ["wmo-melding"], "beschikkingTypes": ["toekenning", "afwijzing"] },
    { "niveau": "directeur", "tot_bedrag": null, "zaaktypes": ["wmo-melding"], "beschikkingTypes": ["toekenning", "afwijzing", "wijziging"] }
  ],
  "ondermandaatToegestaan": true
}
```

## Seed Data (Three beschikkingen in different states)

Procest seeds `lib/Settings/seed/beschikkingen.json` with example beschikkingen for QA and documentation:

```jsonc
[
  {
    // COMPLETED: WMO toekenning huishoudelijke hulp (gearchiveerd)
    "id": "besch-2026-04832",
    "zaakId": "zaak-2026-wmo-04832",
    "zaaktype": "wmo-melding",
    "beschikkingType": "toekenning",
    "kenmerk": "Z/2026/04832/B01",
    "templateId": "tpl-wmo-toekenning-huishoudelijke-hulp-v4",
    "huidigeStatus": "gearchiveerd",
    "geadresseerde": {
      "type": "burger",
      "bsn": "123456789",
      "naam": "M.A. Janssen-de Vries",
      "berichtenboxKanaal": "mijnoverheid",
      "berichtenboxBevestigd": true
    },
    "beslissing": {
      "soort": "toekenning",
      "onderwerp": "huishoudelijke ondersteuning",
      "omvang": "4 uur per week",
      "ingangsdatum": "2026-04-01",
      "einddatum": "2027-04-01"
    },
    "motivering": "Op basis van het onderzoek van 28 maart 2026 ...",
    "bekendmakingDatum": "2026-04-02",
    "archief": {
      "gearchiveerdOp": "2026-05-15T06:30:00+02:00",
      "archiefId": "openregister-2026-99231",
      "vernietigingsdatum": "2041-04-02"
    }
  },
  {
    // IN-PROGRESS: Omgevingsvergunning (ondertekend, waiting on Berichtenbox delivery polling)
    "id": "besch-2026-00156",
    "zaakId": "zaak-2026-omg-00156",
    "zaaktype": "omgevingsvergunning",
    "beschikkingType": "toekenning",
    "kenmerk": "Z/2026/00156/B01",
    "templateId": "tpl-omgevingsvergunning-reguliere-procedure-v3",
    "huidigeStatus": "ondertekend",
    "geadresseerde": {
      "type": "bedrijf",
      "oin": "00000001234567890000",
      "naam": "Bouwbedrijf de Vries B.V.",
      "berichtenboxKanaal": "eherkenning",
      "berichtenboxBevestigd": true
    },
    "beslissing": {
      "soort": "toekenning",
      "onderwerp": "bouwactiviteiten Kerkstraat 12",
      "omvang": "n.v.t.",
      "ingangsdatum": "2026-05-01",
      "einddatum": null
    },
    "motivering": "De aanvrager heeft voldaan aan de eisen van de Omgevingswet ...",
    "handtekening": {
      "tspProvider": "kpn-gekwalificeerde-handtekening",
      "ondertekenaar": "afdelingsmanager-bouw-23",
      "ondertekeningTijdstip": "2026-04-22T10:15:42+02:00",
      "validatieRapportId": "val-2026-99405"
    },
    "verzending": {
      "kanaal": null,
      "verzondenOp": null
    }
  },
  {
    // DRAFT: Subsidietoekenning (concept, awaiting treatment)
    "id": "besch-2026-00489",
    "zaakId": "zaak-2026-sub-00489",
    "zaaktype": "subsidieaanvraag",
    "beschikkingType": "toekenning",
    "kenmerk": "Z/2026/00489/B01",
    "templateId": "tpl-subsidie-v2",
    "huidigeStatus": "ontwerp",
    "geadresseerde": {
      "type": "burger",
      "bsn": "987654321",
      "naam": "J. Pieterse",
      "berichtenboxKanaal": "mijnoverheid",
      "berichtenboxBevestigd": false
    },
    "beslissing": {
      "soort": "toekenning",
      "onderwerp": "subsidie culturele activiteiten",
      "omvang": "€ 2.500",
      "ingangsdatum": "2026-06-01",
      "einddatum": "2026-12-31"
    },
    "motivering": null,  // Not yet filled in by treatment officer
    "motivering_required": true,
    "verzending": {
      "kanaal": null,
      "verzondenOp": null
    }
  }
]
```

## Key Design Decisions

### D1: State-Machine Immutability Contract

Once a beschikking reaches "ondertekend", its content (motivering, beslissing, etc.) is locked. This is legally essential: the signed PDF is what was publicly delivered, and the underlying beschikking object must never contradict it. Any correction requires a new beschikking (wijziging or intrekking).

**Enforcement:** On every PATCH to `/api/beschikkingen/{id}`, check `huidigeStatus` ∈ {ondertekend, verzonden, ontvangen-bevestiging, gearchiveerd}. If so and the payload contains a field in {motivering, beslissing.*, geadresseerde, etc.}, reject with HTTP 409 Conflict. Allow only state-transition fields (verzondenOp, leesBevestigingOp, etc.).

### D2: TSP Validation Report is Durably Stored

The eIDAS validatierapport from the TSP (which includes the certificate chain, timestamp, and revocation-status-at-signing-time) must be kept forever, because future signature-validation (in a rechtsgang, or per ETSI EN 319 102-1) relies on it. OpenConnector stores the rapport and returns a UUID; Procest stores that UUID.

**Consequence:** If OpenConnector is ever decommissioned, the rapport must be migrated to OpenRegister or a dedicated archief-service before deletion. This is a data-stewardship responsibility, not handled in-app.

### D3: Mandaat Regeling is Point-in-Time, Not Time-Series

When a beschikking is signed, the MandaatRegeling that was valid AT THAT MOMENT must be saved (or at least its ID and version). If a municipality later changes the mandaatregeling (e.g., raising the afdelingsmanager limit from €25k to €30k), a future bezwaar audit must still be able to prove that the original signing was within the limit at the time.

**Implementation:** At the akkoord step, capture `mandaatGegeven.mandaatregelingId` + the MandaatRegeling's `version` (if versioned) in the beschikking. Do NOT store the full regelings-object; instead, treat the combination of `mandaatregelingId` + `version` as a pointer that can be queried from OpenRegister at audit time.

### D4: Berichtenbox Delivery Polling vs. Push

Berichtenbox (Logius MijnOverheid) can push delivery confirmations via webhook, but not all implementations support it. To ensure reliability, Procest polls the Berichtenbox API on a schedule (e.g., hourly) to fetch delivery + read status. Once read status is received, polling stops (no need to re-check forever).

**Implementation:** The `BezwaarTrigger` is created immediately on `verzonden`, setting `archiefTriggerActief = true` and a `bezwaarTermijnEindDatum`. The daily `BezwaarTermijnJob` checks if the termijn has passed; if yes and `bezwaarOntvangen = false`, it triggers archival regardless of whether `leesBevestigingOp` has been recorded yet. This ensures archival is not blocked if polling fails.

### D5: TMLO vs. MDTO: Dynamic Per-Gemeente Selection

Different municipalities use different archival metadata standards. At the time of archival, Procest queries OpenRegister or gemeente-config to learn which standard the gemeente uses (TMLO-1.2 or MDTO), then instructs the OpenRegister archief-service to generate the corresponding metadata block.

**Implementation:** `archief.tmloMetadata` in the schema is a generic `{ schema: "TMLO-1.2" | "MDTO", fields: {...} }` union. At archival time, the ArchivalJob calls a Procest service that knows the gemeente's preference.

### D6: Audit-Pakket is Verifiable, Not Encrypted

The audit-pakket (ZIP export per REQ-BES-009) contains sensitive data (beschikking content, TSP rapport) but is NOT encrypted. Instead, it is signed by Procest (using Procest's own keypair, separate from the decision-maker's TSP certificate). This allows any future system or external party (e.g., a rechter) to verify the package's integrity without needing decryption keys from Procest.

**Implementation:** On export request, Procest generates a PKCS#7 detached signature over the ZIP's content, includes the signature file in the ZIP, and returns the whole thing. Verification is done with Procest's public cert.

## Integration Points

- **Docudesk**: REST call to render template → PDF bytes.
- **OpenConnector**: REST call to sign (TSP flow) and deliver (Berichtenbox/eHerkenning).
- **OpenRegister**: REST call to ingest beschikking + metadata on archival.
- **Procest UI** (caseDetail): "Beschikking opstellen" button, modal workflow for composition, akkoord, sign, verzend.
- **Procest Jobs**: Daily scheduled tasks for bezwaartermijn and archival triggers.

## Non-Goals

- **Custom template authoring within Procest UI** — Docudesk owns the template editor.
- **Bezwaar lifecycle state-machine** — Handled by the separate `bezwaar-lifecycle` spec; Procest only provides the cross-link.
- **AVG / PII redaction on archival** — Handled by the gemeente's archival governance; this spec assumes the gemeente's policies are already configured in OpenRegister.
- **Performance optimization for high-volume beschikking exports** — V1 assumes moderate volume (hundreds per day); batch-archival optimization deferred to V2.
