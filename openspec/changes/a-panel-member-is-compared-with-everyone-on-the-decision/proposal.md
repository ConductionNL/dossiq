# Proposal: a-panel-member-is-compared-with-everyone-on-the-decision

## Why

Under Awb Art. 7:13 lid 3, no member of a bezwaaradviescommissie panel may have worked on the contested primair besluit. The independence check compared the panel with one person only: the decision's owner, or else its `createdBy`, or else its `steller`. A panel member who decided or signed the decision was never compared. The main spec still said a conflict SHALL NOT block.

## What changes

- Every panel member is compared with every person on the contested decision.
- Those persons are `decidedBy`, `@self.owner`, `createdBy`, `steller`, `signatory` and each entry of `signatories`.
- A signatories entry is a user id, or an object with a `uid` or `userId`.
- Any match blocks the move from `assigned` to `in-deliberation`.
- The main spec's "Committee Composition Tracking" now says a match blocks.
- A chain that resolves no person at all is refused as unverifiable. That part lives in `an-unverifiable-panel-does-not-start-deliberating`.
