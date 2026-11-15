# Tasks

- [x] T01 `FreezeDefinition`: entry validation and field classification
- [x] T02 `FreezeRegistry`: entries from app config, `config:` schema references, broken entries skipped and logged
- [x] T03 `FrozenRecordGuard`: frozen fields, thaw, soft and permanent delete, write-once fields, unreadable payload
- [x] T04 `RecordSeal`: canonical SHA-256 seal and verification by the seal's own fields
- [x] T05 `FrozenRecordSuccessor`: next number from the series, both pointers, refusals
- [x] T06 `FrozenRecordListener` registered on updating and deleting; seals on the freezing write
- [x] T07 Unit tests on the neutral fixture record kind `fixtureRecord`
