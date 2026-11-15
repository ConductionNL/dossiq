## ADDED Requirements

### Requirement: The Woo request starts from the signed-in resident's name and e-mail address

Both Woo request actions MUST declare `prefill: name` on `verzoekerNaam` and `prefill: email` on `verzoekerEmail`, so portaliq starts those fields from the signed-in resident's own details. The resident MUST still be able to change them.

#### Scenario: Step 3 knows who is asking
- GIVEN Henk Bakker is signed in to the portal
- WHEN he reaches "Uw gegevens" in the Woo request
- THEN his name and e-mail address are already filled in
- @e2e exclude pinned by `PortalContributionProviderTest::testTheWooRequestPrefillsTheRequesterFromTheProfile`; the filling itself is portaliq's and pinned there
