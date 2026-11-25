# Proposal: slug-search-takes-the-openregister-target

## Why

OpenRegister main changed `ObjectService::searchObjectsBySlug()` to take one
`Contract\RegisterSchemaSlugs` value instead of two slug strings (openregister
#9103). Dossiq's one search bridge (`Service\Support\SearchesObjects`) still
passed two strings, so every slug search failed with a TypeError on an
instance with OpenRegister main. Found in a live pass on 25 Nov 2026: the case
page's Terms card and the Requests card answered 503 ("The case type could not
be read", `aanvullingsverzoek_unreadable`).

## What changes

The bridge reads the type of the service's first `searchObjectsBySlug()`
parameter. When it is a class, it passes one value of that class built from the
register and schema slugs; otherwise it passes the two strings as before. So
dossiq runs on OpenRegister main and on released OpenRegister versions alike,
without a version check.

## Scope

`lib/Service/Support/SearchesObjects.php` only. No route, schema or UI change.

