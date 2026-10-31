# Proposal: person-chips

## Why

Board DqTaken draws the handler as a 28px initials chip, and board DqContacten draws each person as a 36px grey chip.
The coordinator decided (24 Oct) that the board look draws chips, with size and tone as configuration
(nextcloud-vue feat/board-avatar-chip).

## What changes

- The Tasks list's handler avatar: `size: 28`.
- The Contacts list's name avatar: `size: 36, tone: "neutral"`.

Config only.
