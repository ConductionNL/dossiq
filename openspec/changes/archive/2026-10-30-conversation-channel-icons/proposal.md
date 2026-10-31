# The conversation chips carry the channel icons

## Why

DqZaakContact draws each message's channel chip with its icon (phone, envelope, globe), the same icons as the contact-moments Kanaal column. Live chips had the name only (r31 verdict: icon true>false on Telefoon, E-mail, Mijn Zuiddrecht).

## What changes

- The resident thread (`case-resident-thread`) declares `channelIcons` with the same map as the Kanaal column's `widgetProps.icons`. Needs nextcloud-vue feat/conversation-channel-icons; ignored before it.
