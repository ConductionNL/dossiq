<!-- SPDX-License-Identifier: EUPL-1.2 -->
# Decision: Besluitvorming — keep in dossiq, integrate with decidesk (do not consolidate)

Status: **Recommendation** (2026-06-22) — backlog item #7.

## Question
Dossiq has a "Besluitvorming" nav group (Voorstellen = proposals, Advice =
advice requests). Decidesk is the fleet's decision/meeting platform. Should
dossiq's Besluitvorming consume decidesk instead of re-implementing it?

## What each app actually does
- **Dossiq Besluitvorming** is a *pre-decision routing* workflow:
  - `Voorstellen` (`voorstel` schema 110): concept → in_parafering →
    ter_accordering → geaccordeerd → aangeboden → besloten. A signature-chain
    (parafeerroute) approval attached to a case. No voting, no amendments.
  - `Advice` (`adviesAanvraag` schema 126): opinion requests on a case.
- **Decidesk** is *formal governance decision-making*: meetings, agenda items,
  motions/amendments, voting rounds + individual votes, minutes, decisions
  (universal `decision` supertype, ADR-005), governance bodies. Decision/Meeting
  are top-level; storage is CalDAV-first for action items.

## Recommendation: keep separate, integrate one-way
Dossiq's voorstel is the *internal approval stage that precedes* a formal
decision; decidesk records the *body's formal outcome*. They are different
lifecycle stages, not duplicates:
- voorstel has no voting/amendments; a rejected voorstel returns to the steller.
- dossiq is case-centric (voorstellen attach to a case); decidesk is
  governance-body-centric (decisions are body outcomes).

**Do NOT** fold Besluitvorming into decidesk. **Do** consider two light,
optional, one-way integrations (separate future changes, only if usage warrants):
1. When a voorstel reaches `besloten`, emit a downstream decidesk `decision`
   record to preserve the formal outcome (dossiq → decidesk, write-only).
2. Replace dossiq `task` tracking with decidesk's CalDAV-VTODO `action-item`
   model for better Nextcloud-native task integration (orthogonal to
   Besluitvorming; evaluate fleet-wide).

## Why not consolidate
Mixing them blurs the domain boundary (is a decidesk decision a formal body
outcome or a dossiq internal approval?), and dossiq's parafering chain does
not map onto decidesk's voting/amendment model. Keep boundaries clear.

## Next step
No code change now. If desired, raise integration (1) as an OpenSpec change in
both repos (dossiq emitter + decidesk consumer), gated on real demand.
