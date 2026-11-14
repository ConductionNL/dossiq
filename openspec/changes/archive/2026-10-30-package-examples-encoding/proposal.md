# Example rows take the shape their schemas declare

## Why

With example data on, `occ dossiq:package:install bezwaar-beroep jeugdwet
aanbesteding` failed for all three packages and rolled back: "Property
'statusHistory' should be type 'string or null' but is 'array'." The register
declares some properties as JSON-encoded strings (JsonEncodedStringProperties
lists them per schema), and the example files wrote them as JSON arrays. The
existing tests did not send a real package's rows past a schema.

## What changes

- PackageExamples encodes every property JsonEncodedStringProperties lists
  for the schema an example file writes to, so that list stays the one place
  that knows them. An example file can keep readable JSON.
- A unit test sends every shipped package's example rows through
  PackageExamples and checks each one against the effective register: types,
  enums and required properties.
- A unit test installs the shipped bezwaar-beroep package with example data on.
