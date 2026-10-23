# Proposal: social-side-cards-read-like-the-board

## Why

Board DqSociaalDomein draws the side column of a social domain case as: the
"Bijzondere persoonsgegevens" notice, "Toestemming" as label-left facts (Doel,
Gegeven door, Vastgelegd, Geldig tot), "Wie" as rows behind initials ("FK Fatma
Kaya, regisseur"), "Historie", and "Status" last. Live put Status first, drew the
consent as a link row ("Onderzoek naar jeugdhulp / Beide ouders · 7 okt") and
the people as links with a second line.

## What changes

- Melding Jeugdwet's `sideCards`: notice, Consent, Who, then Status (package 1.5.0
  so an installed package takes the order).
- `side-social-consent` becomes a facts card over the newest consent on the case:
  purpose, given by, recorded (date and channel), valid until ("Until it is
  withdrawn" when the consent names no end date).
- `side-social-people` draws each person behind their initials, name and what
  they do on one line.

- "Toestemming intrekken" ends the consent today (PATCH `withdrawn` and `validTo`), confirmed first; needs nextcloud-vue `feat/r24-api-call-patch`.

## Left

- The board's "Historie" side card (access-purpose log rows and the case's
  reference) has no source yet.
- The notice shows only when the installed case type carries
  `specialDataCategories: [health]`; :8080 drew none (to re-check after the
  package re-install).
