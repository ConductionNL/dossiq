# Design: beschikking-renders-through-filinq-when-installed

## Screen

No board draws this. The canvas has no Integrations page for dossiq, and the
change adds no field, label or action. The only visible effect is the word on
the existing Document templates card, which the Integrations page already
renders from `lib/Settings/connections.json`.

## D1 · The default follows what is installed

`ConfiguredAdapter::resolve()` takes one mock class today. The template seam
gets a second default: when the key is empty and
`FleetAppId::isEnabledForUser(..., 'filinq')` is true, it resolves
`FilinqTemplateEngineAdapter`. The probe goes through `FleetAppId`, as the
current warning already does, because filinq renamed from docudesk and a
literal app id takes the integration dark without an error.

An admin who names a class keeps that class. An admin who names the mock gets
the mock, and the card says so.

Why not write the key on install: a written key outlives the app it names. An
admin who removes filinq would keep a key pointing at a class whose service
is gone, and `ConfiguredAdapter` would log a refusal on every request. Deciding
at resolve time follows the instance as it is now.

## D2 · The card reads the binding, not the key

`IntegrationStatusService` reads `simulated` from the key's value against
`simulatedValues`, and `""` is in that list. After D1 an empty key can mean a
live renderer. The templates entry therefore asks the same question the seam
asks: empty key and filinq enabled is Live. `simulatedValues` for templates
keeps only the mock class name; the empty value moves to a condition the
service evaluates with the filinq probe.

The card and the seam must not drift apart. One helper decides the default,
and both call it.

## D3 · The warning

`templateFallbackReason()` keeps its first sentence (filinq is not installed).
Its second sentence (filinq installed, no key) no longer fires, because that
case binds filinq now. A third case replaces it: an admin named the mock while
filinq is enabled. The log then says the mock was chosen and names the key to
clear.

## Risks

- An instance where filinq is enabled but its `DocumentService` throws now
  fails a beschikking that used to "succeed" against the mock. That is the
  point: the mock's success was false. `FilinqTemplateEngineAdapter` already
  returns a refusal the dialog shows.
