# pdok-integration (delta)

## REMOVED Requirements

### Requirement: PdokBagService SHALL be the single server-side ingress for PDOK BAG WFS v2_0 lookups, with normalization and 24h caching

**Reason**: Its only caller, dossiq's PDOK BAG adapter, is deleted. Integriq reads PDOK's BAG WFS
(ADR-118).

**Migration**: The app config keys `pdok_bag_endpoint` and `pdok_bag_source` are no longer read.
