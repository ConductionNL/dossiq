---
kind: code
depends_on: []
---

# Proposal: woo-receipt-also-by-email

## Why

Rehearsal R3, 16 October: a resident who asks a question on the portal gets the receipt in the portal inbox and by e-mail. A resident who files a Woo request gets the acknowledgement of receipt in the portal inbox only (channel `portal-inbox`). The notice sender tries the portal inbox, digital post and e-mail in that order and stops at the first that takes it, so a portal requester never gets the mail, and no case type could ask for it.

## What changes

1. The case type's `acknowledgement` declaration gains `emailCopy` (default off). On, an acknowledgement that went to the portal inbox or digital post is also mailed to the sender's address.
2. The copy is recorded on the case as an outbound message of its own. A copy that cannot go out is recorded as not sent and does not change the duty, which the first channel already met.
3. The Woo request case type declares `emailCopy: true`.

## Not changed

- The channel order (REQ-WRN-002) stays as it is; the copy comes after it.
- Other notices (information requests, decisions) are not copied.

## Rollback

Set `emailCopy` to false on the case type, or revert the commit.
