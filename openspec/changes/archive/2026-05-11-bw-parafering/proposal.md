# Proposal: bw-parafering

## Summary
Implement the ambtelijk (civil servant) workflow for B&W parafering: creating voorstellen, configuring parafeerroutes, executing sequential/parallel parafering steps, and maintaining an audit trail. The bestuurlijk part (college vergadering) is handled by external RIS systems.

## Motivation
B&W besluitvorming is found in 20+ tenders (29% of all). It is the #6 Nice-to-have but weighs heavily in scoring (3-8% of total score, up to 68 points).

## Affected Projects
- [x] Project: `procest` -- Parafering services, controller, audit trail

## Scope

### In Scope
- Voorstel creation from case context
- Configurable parafeerroute per case type and voorstel type
- Sequential and parallel parafering steps
- Parafering actions: paraferen, terugsturen, adviseren, paraferen namens
- Immutable audit trail for all parafering actions
- ParaferingService, VoorstelService, ParaferingController

### Out of Scope
- RIS connectors (iBabs/NotuBiz) -- V2
- Mobile parafering -- V2
- Vergaderbeheer -- V2
