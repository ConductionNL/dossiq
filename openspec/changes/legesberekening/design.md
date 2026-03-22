# Design: legesberekening

## Architecture

### Calculation Engine
The `LegesCalculationService` implements fee calculation using a rule-based approach:
1. Look up the applicable verordening based on the case start date
2. Match the case type to the relevant artikelen in the verordening
3. For each matching artikel, apply the calculation type (vast/percentage/staffel/maximum)
4. Sum all applicable fees, apply caps/maxima
5. Store the calculation result with a breakdown per artikel
6. Maintain version history for audit trail

### Data Model (OpenRegister Schemas)
- `legesVerordening` -- Year, valid-from, valid-until, status (draft/active/archived)
- `legesArtikel` -- Verordening ref, titel, hoofdstuk, artikel number, calculation type, rate, brackets
- `legesBerekening` -- Case ref, verordening ref, total amount, status, calculated-by, timestamp
- `legesRegel` -- Berekening ref, artikel ref, grondslag, calculated amount, breakdown

### API Endpoints
- `POST /api/leges/calculate` -- Trigger calculation on a case
- `GET /api/leges/calculations/{caseId}` -- Get calculation history for a case
- `POST /api/leges/export` -- Export definitieve berekeningen
- `GET /api/leges/verordeningen` -- List verordeningen
- `POST /api/leges/verordeningen` -- Create/import verordening

### Calculation Types
```
vast:       result = artikel.bedrag
percentage: result = grondslag * (artikel.percentage / 100)
staffel:    result = sum of (bracket_amount * bracket_rate) for each bracket
maximum:    result = min(calculated, artikel.maximum)
combinatie: result = sum of sub-calculations
```

## Dependencies
- OpenRegister for data storage
- Case Management spec for case data (bouwkosten, activiteiten)
- OpenConnector for financial system export adapters
