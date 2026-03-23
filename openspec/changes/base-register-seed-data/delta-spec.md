## Delta Spec: base-register-seed-data

### REQ-SEED-008a: Default Case Types — IMPLEMENTED
- 4 case types seeded: Omgevingsvergunning (P56D), Subsidieaanvraag (P42D), Klacht behandeling (P42D), Melding openbare ruimte (P14D)
- All published (isDraft: false) and immediately usable

### REQ-SEED-008b: Default Status Types — IMPLEMENTED
- Omgevingsvergunning: Ontvangen (1), In behandeling (2), Besluitvorming (3), Afgehandeld (4, isFinal)
- Subsidieaanvraag: Ontvangen (1), Beoordeling (2), Besluitvorming (3), Afgehandeld (4, isFinal)
- Klacht behandeling: Ontvangen (1), Onderzoek (2), Afgehandeld (3, isFinal)
- Melding openbare ruimte: Ontvangen (1), In behandeling (2), Afgehandeld (3, isFinal)

### REQ-SEED-008c: Default Role Types — IMPLEMENTED
- 4 role types: Behandelaar, Aanvrager, Gemachtigde, Technisch adviseur

### REQ-SEED-008d: Default Result Types — IMPLEMENTED
- 8 result types across case types with archive action and retention period
