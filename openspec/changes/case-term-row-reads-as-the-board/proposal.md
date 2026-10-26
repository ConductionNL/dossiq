# Termijn en betaling reads as the board

## Why
DqZaak draws "Wettelijke termijn: 4 weken, te verdagen met 2" and "Laatst gecontroleerd". Live drew "Wettelijke behandeltermijn: P28D" and "Betaalstatus gecontroleerd op". The board is canon.

## What changes
- The case gains `statutoryExtension` (case 1.55.0), its type's extension period, materialised beside `statutoryTerm`.
- The term card labels the term "Statutory term" (Wettelijke termijn) and reads it with nextcloud-vue's `format: 'duration'` and `extendBy: 'statutoryExtension'`.
- `paymentStateCheckedAt` reads "Last checked" (Laatst gecontroleerd).
- An existing case gets its extension on its next save, or through `occ openregister:rematerialise-calculations`.
