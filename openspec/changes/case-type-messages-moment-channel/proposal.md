# Proposal: case-type-messages-moment-channel

## Why

Board DqZaaktype draws "Berichten aan de aanvrager" on a case type's Phases and tasks tab: a table of when each
message goes out (Zaak ontvangen, Termijn verlengd, Aanvullende gegevens nodig, Besluit genomen), how it reaches the
applicant (Berichtenbox, anders e-mail) and which message it is, with "Bericht toevoegen" in the title row and a note
on opted-out applicants. A message template only had a name, subject and body, so nothing said when or how it goes
out, and the tab drew the template editor instead (coordinator decision, 23 Oct: build it as config).

## What changes

- emailTemplate 1.3.0 (register.d/99-email-template-moment-and-channel.json): `moment`, a generic case event
  (case-received, status-changed, information-requested, term-extended, decision-made, case-closed), and `channel`
  (mailbox-then-email, mailbox, email, portal, post). No procedure names; a case type picks per template.
- The three shipped templates name their moment and channel.
- EmailTemplateService and the template endpoints store both; a new version keeps the moment, channel and message
  category the edit does not name (the category was dropped on every edit before).
- The template editor sets both (src/utils/messageDelivery.js is the one list of options and labels).
- The case type page: `case-type-messages`, a record-list table (Moment, Channel, Message) with the opt-out footnote,
  replaces the editor on Phases and tasks; the editor moves to an overflow tab "Message templates", which
  "Add a message" opens.

## Not in this change

Sending on the moment. Templates still prefill a Nextcloud Mail draft; the moment is the declared intent a dispatcher
can read. The new tab gets no side cards yet (the side column tab lists are touched by r24/case-type-fields-tab-board-cards).

## Reach

Schema: emailTemplate 1.3.0 (digest recorded). PHP: EmailTemplateService, EmailTemplateController. Vue: the editor.
Config and l10n.
