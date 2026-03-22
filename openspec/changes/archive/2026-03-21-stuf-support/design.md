# Design: StUF Protocol Support

## Architecture
- **Pattern**: Dual API surface — ZGW REST + StUF SOAP over same OpenRegister data
- **Inbound**: StUF endpoints in Procest for receiving SOAP messages from legacy consumers
- **Outbound**: Leverages OpenConnector SOAPService with StUF-ZKN awareness
- **Protocols**: StUF-ZKN (case management), StUF-BG (person/address lookups)
