---
sidebar_position: 10
title: Open a case with special personal data
description: Why Dossiq asks why you open some cases, what each purpose does, and how choosing handling makes you the handler.
---

# Open a case with special personal data

Some cases hold special categories of personal data: health, a criminal record, an income test. The AVG asks that every look at such data has a purpose. Dossiq records that purpose for you, with your name and the time, in the case's history.

## When Dossiq asks

Dossiq asks when two things are true:

- The case type lists special categories of personal data. An administrator sets these on the case type's Privacy tab.
- You are not this case's handler.

You are the handler when the case is assigned to you, or when you are on the case in a handler role. The handler opens the case without the question. Dossiq records "Behandeling" as the purpose for you, with your name and the time, as it does for everyone else.

## What you see

The dialog "Waarom open je deze zaak?" opens before anything of the case shows. Its first line says why: you are not this case's handler. Pick a purpose and click **Openen**.

| Purpose | What it records | What else happens |
|---|---|---|
| Behandeling van de zaak | You open the case to work on it. | You become a handler of the case. The dialog says so under this option. |
| Advies of overleg over de zaak | You give advice or talk the case over. | Nothing else. |
| Toezicht of een kwaliteitscontrole | You supervise or check the work. | Nothing else. |
| Een wettelijke verplichting | A law requires you to look. | Nothing else. |
| Een verzoek van de betrokkene | The person in the case asked for it. | Nothing else. |
| Overig | Your own words, up to 500 characters. | Nothing else. |

Dossiq asks once per case per browser session. Close the dialog and the case stays closed: click **Doel opgeven** to answer after all.

## Behandeling makes you the handler

When you pick "Behandeling van de zaak", Dossiq adds you to the case in a handler role after you confirm. The case's assignee stays as it was. You find yourself on the case's People tab.

The case's history then shows two lines:

- "Zaak ingezien, doel behandeling", with your name and the time.
- "Bij het openen toegevoegd als behandelaar", with your name and the time.

Next time you open the case, you are its handler, and Dossiq does not ask again.

## For administrators

Which roles skip the question, which purpose Dossiq records for them, and which purpose makes the reader a handler is app configuration, not code. The key is `access_purpose_roles` in the Dossiq app config:

```json
{
  "skipFor": ["handler"],
  "skipPurpose": "handling",
  "assign": { "handling": "handler" }
}
```

The roles are generic roles: the "Generic role" a role type carries on the case type's Roles tab. Dossiq binds a reader with the case type's own role type for that generic role, or with one every case type shares. Give a case type a role type with the generic role "handler" before you rely on this.

Set it with `occ config:app:set dossiq access_purpose_roles --value '<json>'`, then open a case with special personal data as a colleague who is not its handler and check the history.
