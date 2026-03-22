# B&W Parafering (Executive Approval Workflow)

The B&W parafering feature implements the formal approval workflow for decisions that require authorization by the Board of Mayor and Aldermen (College van Burgemeester en Wethouders).

## Overview

In Dutch municipal government, certain decisions must be formally approved (geparafeerd) by the B&W (Board of Mayor and Aldermen). This feature provides a structured digital approval workflow.

## Planned Features

- **Approval routing** -- Route decisions through the required approval chain.
- **Digital signing** -- Support for digital parafering (initialing/signing) by authorized officials.
- **Mandate checking** -- Verify that the approver has the required mandate for the decision type.
- **Approval history** -- Track the full approval chain with timestamps and approver identities.
- **Rejection handling** -- Handle rejections with feedback and re-routing.
- **B&W agenda integration** -- Link approval items to B&W meeting agendas.
- **Delegation** -- Support for delegated approval authority.
- **Batch approval** -- Allow authorized officials to approve multiple items in a single session.

## Legal Context

Municipal decisions often require formal authorization at specific mandate levels. The B&W parafering workflow ensures compliance with the municipality's mandate register (mandaatregister).

## Status

This feature is defined in the spec at `openspec/specs/bw-parafering/spec.md` and is planned for future implementation.
