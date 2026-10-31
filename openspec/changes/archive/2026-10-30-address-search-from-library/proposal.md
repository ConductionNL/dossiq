# Proposal: address-search-from-library

## Why

dossiq searched addresses with its own AddressSearch over a PDOK shim (`src/services/pdokService.js`) that routes every call through the PDOK proxy app. The library now has CnAddressSearch, which does the same through the same proxy routes (`/apps/<integriq|openconnector>/api/pdok/{suggest,free,lookup}`), says when the proxy is missing or PDOK is down, and emits the same `{ result, coordinates, address }`. Every app that stores a location needs the field, so it belongs in the library.

## What changes

- CaseLocationDialog and LocationPicker use CnAddressSearch.
- AddressSearch.vue, pdokService.js and its vitest spec are deleted.
- The pdok-consumer capability's requirements on the shim move to the library component; the requirement that no browser call reaches api.pdok.nl stays.
