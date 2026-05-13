# StUF Support

The StUF (Standaard Uitwisseling Formaat) support feature provides compatibility with the legacy Dutch government data exchange standard.

## Overview

StUF is a SOAP/XML-based standard historically used for data exchange between Dutch government systems. While being phased out in favor of REST-based APIs, many legacy systems still use StUF.

## Planned Features

- **StUF-ZKN** -- Support for the StUF Zaken (case management) message format.
- **StUF-BG** -- Support for the StUF Basisgegevens (base data) message format.
- **Message translation** -- Translate between StUF XML messages and OpenRegister's JSON objects.
- **Endpoint provisioning** -- Expose StUF SOAP endpoints for legacy system integration.
- **Message logging** -- Log all StUF messages for audit and debugging.
- **Error handling** -- Proper StUF error responses (Fo/Fout messages).
- **Asynchronous processing** -- Support for asynchronous StUF message patterns.

## Migration Path

StUF support is intended as a bridge technology, enabling organizations to connect legacy systems while migrating to modern ZGW APIs. The ZGW API mapping feature provides the forward-looking integration path.

## Status

This feature is defined in the spec at `openspec/specs/stuf-support/spec.md` and is planned for future implementation.
