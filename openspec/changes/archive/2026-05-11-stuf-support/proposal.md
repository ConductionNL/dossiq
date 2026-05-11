# Proposal: stuf-support

## Summary
Implement StUF (Standaard Uitwisseling Formaat) protocol support for Procest, providing a dual API surface (StUF + ZGW) over the same OpenRegister case data. V1 covers outbound StUF via OpenConnector; V2 adds inbound StUF endpoints.

## Motivation
Many Dutch municipalities still rely on StUF-ZKN for case management and StUF-BG for person/address lookups. StUF support enables integration with legacy form systems, legacy case systems during migration, and BRP lookups.

## Affected Projects
- [x] Project: `procest` -- StUF controller, message handler, field mapping service

## Scope

### In Scope (V1 foundation)
- StufFieldMappingService for StUF-ZKN/BG to OpenRegister field mapping
- StufMessageBuilder for constructing outbound SOAP envelopes
- StufController for inbound SOAP message handling (raw XML POST)
- StUF date format conversion (YYYYMMDD <-> ISO 8601)
- StUF namespace handling
- noValue attribute support
- Stuurgegevens configuration and population
- Pre-seeded default field mappings

### Out of Scope
- OpenConnector SOAP adapter (separate project)
- mTLS/WS-Security implementation (OpenConnector handles auth)
- WSDL generation
- StUF XSD validation
