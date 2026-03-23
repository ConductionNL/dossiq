# Design: Zaak Intake Flow

## Architecture
- **Pattern**: Bridge between external input and internal case lifecycle
- **Sources**: Open Formulieren, DSO/Omgevingsloket, manual entry, API calls
- **Flow**: Automatic zaaktype assignment, status init, task creation, notification, initiator linking
- **Integration**: n8n workflows for complex intake routing
