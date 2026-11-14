# Design: duplicate-warning-at-intake

## D-1. Three rules, declared

Requester plus case type within 30 days (exact); address plus case type
(exact on the normalised address); subject similarity (the platform's
similarity operator, threshold 0.8). All three in the schema.

## D-2. The panel is the platform's answer

On change of requester, address or subject the form asks the dedup
endpoint and renders the matches as a warning with links. Nothing is
evaluated client-side.

## D-3. Policy is per case type

`warn`: Save stays enabled. `block`: Save is disabled unless the user is in
`dossiq-coordinators`, who see a Continue anyway with a reason field that
lands on the new case's first note.
