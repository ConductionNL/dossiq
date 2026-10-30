# Proposal: contacts-bsn-plain-head

## Why

The BSN becomes an explicit-reveal value (openregister #4843): OpenRegister answers it masked and refuses sorting and
filtering on it. The coordinator decided (24 Oct) that DqContacten's BSN head is a plain label (design-system #424).
Live still offered a sort and a header filter, which would now answer 400.

## What changes

The Contacts list's BSN column is `sortable: false, filterable: false`. Config only.
