# Design: import-vocabulary-guard

The guard runs on the merged configuration, the array `SettingsService`
hands to OpenRegister, so a fragment and the monolith are checked together.

The snapshot is data, not code from OpenRegister: dossiq's tests cannot load
OpenRegister. It is refreshed from a running instance with the two GET
endpoints named in its `_source`, never edited to turn a red green. A walk that
reaches fewer than 100 closed-list declarations fails, so an empty walk cannot
pass.

Nested properties (`properties`, and `items.properties`) are walked as well.
Today every key on them is accepted, so the check holds them to the same rule.
