# Tasks: receipt-confirmation-card-reads

### Task 1: Outbound messages read as lines
- **spec_ref**: `openspec/changes/receipt-confirmation-card-reads/specs/receipt-confirmation-card/spec.md#requirement-outbound-messages-read-as-lines`
- **files**: `src/services/formatters.js`
- [x] Implement: `outboundMessages` formatter
- [x] Test: `tests/vitest/receiptConfirmationCard.spec.js`

### Task 2: The receipt confirmation card reads in words, in the board's order
- **spec_ref**: `openspec/changes/receipt-confirmation-card-reads/specs/receipt-confirmation-card/spec.md#requirement-the-receipt-confirmation-card-reads-in-words`
- **files**: `src/menu-layout.simple.json`
- [x] Implement: include order, date formatters, outbound formatter
- [x] Test: `tests/vitest/receiptConfirmationCard.spec.js`
