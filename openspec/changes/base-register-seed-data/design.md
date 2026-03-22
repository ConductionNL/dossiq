## Architecture

### Seed Data Format

Objects use the `@self` pattern from OpenCatalogi for register/schema/slug references:
```json
{
  "@self": { "register": "procest", "schema": "caseType", "slug": "omgevingsvergunning" },
  "title": "Omgevingsvergunning",
  ...
}
```

### Seed Objects

**Case Types (4):**
1. Omgevingsvergunning (P56D deadline, published)
2. Subsidieaanvraag (P42D deadline, published)
3. Klacht behandeling (P42D deadline, published)
4. Melding openbare ruimte (P14D deadline, published)

**Status Types (13):**
- Omgevingsvergunning: Ontvangen, In behandeling, Besluitvorming, Afgehandeld
- Subsidieaanvraag: Ontvangen, Beoordeling, Besluitvorming, Afgehandeld
- Klacht behandeling: Ontvangen, Onderzoek, Afgehandeld
- Melding openbare ruimte: Ontvangen, In behandeling, Afgehandeld

**Role Types (4):**
- Behandelaar, Aanvrager, Gemachtigde, Technisch adviseur

**Result Types (8):**
- Omgevingsvergunning: Vergunning verleend, Vergunning geweigerd, Ingetrokken
- Subsidieaanvraag: Subsidie toegekend, Subsidie afgewezen
- Klacht behandeling: Klacht gegrond, Klacht ongegrond
- Melding openbare ruimte: Afgehandeld

## Decisions

1. **Slugs as cross-references** — status types reference case types via `@self` slug since UUIDs are generated at load time
2. **ISO 8601 durations** — processing deadlines use standard duration format
3. **Published by default** — all seed case types are `isDraft: false` so they're immediately usable
