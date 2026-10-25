# Proposal: search-placeholder-default

## Why

Board DqKop draws the top bar's search field on a dossiq page as "Zoek zaken, documenten of contacten". Thematiq
(ConductionNL/thematiq#1398) now shows an app's own `search_placeholder` app config value there, translated through the
app's catalogue.

## What changes

Dossiq declares a config lexicon (`ConfigLexicon`, registered in `Application::register()`) with one entry:
`search_placeholder`, a string defaulting to "Search cases, documents or contacts" (nl "Zoek zaken, documenten of
contacten"). A lexicon default reads without ever being written, so no repair step is needed. Every undeclared key keeps
working (strictness IGNORE). An administrator can still set another value; the theme's own entry for dossiq wins over it.
Without thematiq#1398 nothing reads the key.
