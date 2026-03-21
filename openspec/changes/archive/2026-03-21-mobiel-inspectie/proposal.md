# Mobiel Inspectie Specification

## Problem
Mobiel Inspectie provides field inspectors with a Progressive Web App (PWA) for conducting inspections on location. Inspectors need to complete checklists, take photos, capture GPS coordinates, and add observations -- often in areas with poor or no connectivity. The app syncs data when back online.
**Tender demand**: 16% of tenders (11/69) explicitly require mobile inspection. It is a critical differentiator for VTH tenders -- mobile inspection is the primary tool for field inspectors at omgevingsdiensten.
**Standards**: PWA (Progressive Web App), Service Workers (offline), Geolocation API, MediaStream API (camera)
**Feature tier**: V2 (online PWA with photo/GPS), V3 (offline capability, sync queue, field signatures)

## Proposed Solution
Implement Mobiel Inspectie Specification following the detailed specification. Key requirements include:
- See full spec for detailed requirements

## Scope
This change covers all requirements defined in the mobiel-inspectie specification.

## Success Criteria
#### Scenario MOB-01a: Install PWA on mobile device
#### Scenario MOB-01b: Responsive layout for mobile
#### Scenario MOB-01c: PWA manifest configuration
#### Scenario MOB-01d: Session persistence on PWA launch
#### Scenario MOB-01e: Landscape mode for tablets
