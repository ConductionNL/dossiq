# Design: code-lists-from-concepts

## D-1. Two sources, one precedence

`conceptScheme` set: options are the scheme's concepts (label, notation),
stored as the concept's URI. Else `enumValues`. Both set: the scheme wins
and the authoring surface warns.

## D-2. The picker is the platform's

The case data form renders the property with the concept picker
nextcloud-vue ships for SKOS references; dossiq declares, it does not
render.
