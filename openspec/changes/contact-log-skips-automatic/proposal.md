# Automatic messages stay out of the contact log

## Why

DqZaakContact counts four contact moments ("4 vastgelegd", tab Contact 4) while the conversation shows five messages: the receipt the system sent on its own ("Ontvangstbevestiging, automatisch verstuurd") is in the thread, not in the log. Live listed and counted it (r34: 5 against 4), because a contact moment had no way to say it was sent automatically.

## What changes

- contactmoment 1.9.0 gets `automatic` (boolean): true on a message the system sent by itself, absent on a contact a person recorded. No default, so a recorded contact never carries it.
- The case's contact log (`case-contact-moments-card`) and the Contact tab's count filter on `automatic[isnull]`. Configuration only; the list widget and the tab count already pass operator filters.
- The conversation keeps every moment, the receipt too; the history still records it.
