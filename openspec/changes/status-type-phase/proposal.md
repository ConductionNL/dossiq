# status-type-phase

DqProcesanalyse "Dagen per fase" draws five generic phases (Ontvangst, Beoordeling, Advies, Besluit, Verzending) across
case types. Live drew one column per status name: 21 columns over 13 case types, unreadable, because every case type
has its own statuses. A status type gets an optional, translatable `phase`; the matrix groups a case type's statuses by
it and falls back to the status name where none is set. The seeded status types (register, register.d, packages) get
the five phases; final statuses get none. Coordinator decision 25 Oct.
