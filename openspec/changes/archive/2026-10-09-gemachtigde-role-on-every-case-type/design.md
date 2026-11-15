# Design: gemachtigde-role-on-every-case-type

## D-1. One generic role type, not one per case type

`roleType.caseType` is optional. A row without it is offered on every case.
The Add party form lists the case type's own role types first, then the
generic ones. The bezwaar seed keeps its own row; a duplicate name on one
form is avoided by the form skipping a generic row when the type declares
the same `genericRole`.

## D-2. Who is represented is the delegate link

`role.delegateFrom` already names the party a delegate stands for. A
Gemachtigde row sets `participant` to the representative and `delegateFrom`
to the represented party. The Parties tab titles that column Represented by.

## D-3. The seed is idempotent

The repair step looks the row up by `genericRole` and creates it once.
