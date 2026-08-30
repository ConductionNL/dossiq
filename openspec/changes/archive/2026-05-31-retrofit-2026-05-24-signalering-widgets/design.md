# Design — retrofit signalering-widgets

Retrofit change. Tasks describe retroactive annotation. Four IWidget implementations share an identical method shape, so a single REQ per concern (contract, load, registration) covers all 4 files.

## Out of scope
- Adding the Vue components to the spec — they live in src/views/widgets and are covered by the existing Bucket 3 frontend assumption.
