## Why

A fresh Procest installation has no case types, status types, role types, or result types configured. Users must manually create all configuration before they can create their first case. This makes the app unusable out of the box. Seed data for common Dutch government case types enables immediate demos and reduces setup friction.

## What Changes

- **REQ-SEED-008a**: Add 4 default case types (Omgevingsvergunning, Subsidieaanvraag, Klacht behandeling, Melding openbare ruimte)
- **REQ-SEED-008b**: Add status types per case type with correct ordering and isFinal flags
- **REQ-SEED-008c**: Add role types (Behandelaar, Aanvrager, Gemachtigde, Technisch adviseur)
- **REQ-SEED-008d**: Add result types per case type with archive parameters

## Capabilities

### New Capabilities
- `procest-seed-data`: Default case configuration loaded on first install

### Modified Capabilities
- `procest-register`: Register JSON now includes seed objects in `components.objects`

## Impact

- **Data**: `lib/Settings/procest_register.json` — add `components.objects` with seed data
- **No code changes** — existing `SettingsService::loadConfiguration()` handles loading
