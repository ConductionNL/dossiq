# Design: stuf-support

## Architecture

### Dual API Coexistence
StUF and ZGW APIs share the same OpenRegister data. A case created via StUF is immediately visible via ZGW and vice versa. The StUF layer is a translation/mapping layer, not a data duplication layer.

### Message Processing Flow (Inbound)
1. SOAP message arrives at `/api/stuf/{service}` as raw XML POST
2. StufController reads php://input, parses SOAP envelope
3. Dispatches to handler based on root element (zakLk01, zakLv01, npsLv01)
4. Handler uses StufFieldMappingService to map StUF fields to OpenRegister properties
5. Handler creates/updates/queries OpenRegister objects
6. Response is constructed as SOAP XML using StufMessageBuilder

### Message Processing Flow (Outbound)
1. Case event triggers (status change, document upload)
2. StufMessageBuilder constructs SOAP envelope with stuurgegevens
3. Field mapping converts OpenRegister properties to StUF XML paths
4. Message sent via OpenConnector's SOAPService

### Key Classes
- `StufFieldMappingService` -- Bidirectional field mapping with date/enum transformation
- `StufMessageBuilder` -- SOAP envelope construction with namespace management
- `StufController` -- Raw XML POST handler with SOAP dispatch

### API Endpoints
- `POST /api/stuf/zaken` -- Inbound StUF-ZKN messages (zakLk01, zakLv01)
- `POST /api/stuf/personen` -- Inbound StUF-BG messages (npsLv01)

## Dependencies
- OpenConnector SOAPService for outbound messages
- PHP DOMDocument for XML parsing/construction
- OpenRegister for case data storage
