---
sidebar_position: 2
title: Set up automatic actions
description: "Automatic actions are OpenRegister flows. Migrate the old records, then build and run actions in the flow editor with the steps of the apps that own them."
---

# Set up automatic actions

Automatic actions are **OpenRegister flows**. A flow is built from steps, and each step comes from the app that owns what it does:

| What the step does | Step | App |
|---|---|---|
| Send an email | `openregister.send-email` | OpenRegister |
| Send a notification | `openregister.send-notification` | OpenRegister |
| Write a field on the case | `openregister.object-write` | OpenRegister |
| Evaluate a decision table | `openregister.decision-table` | OpenRegister |
| Generate a document | `filinq.generate-document` | Filinq |
| Call a webhook | `openconnector.source-call` | Integriq |
| Ask for a decision | `decidiq.request-decision` | Decidiq |
| Set the status, create a task or sub-case, ask a person, publish a besluit | `dossiq.*` | Dossiq |

Dossiq only offers steps for what a case can do and no other app owns.

:::info What changed
Dossiq used to offer its own steps for mail, notifications, webhooks, field writes, decision tables, documents and decisions. They are gone, because the apps above now provide them. When you upgrade, Dossiq rewrites every stored Dossiq flow that still uses one of the old steps into the step that replaced it, and logs each change. A step it cannot carry over faithfully stays where it is and is logged as a warning (see [Common issues](#common-issues)).

What Dossiq still does for you:

- A mail a flow sends about a case is filed on the case and shows on its timeline.
- A document Filinq generates for a case is filed in the case dossier, when the step names a document type.
- A decision Decidiq concludes for a case becomes the case's besluit.
- A webhook step keeps calling its URL. Integriq makes the call now, through a source for the URL's scheme, host and port. The upgrade asks Integriq for that source, and Integriq creates it the first time, enabled and without credentials, with a description that says Dossiq asked for it. The rest of the URL becomes the step's endpoint. The step posts the case, as before. A webhook a case type declares on a status transition also still posts the transition beside the case.
:::

:::warning An older version of this page was wrong
Dossiq used to have its own **Automatische acties** settings page. It has been retired. An earlier version of this page described a rule engine with *triggers*, *conditions* and a *Last run* column. **None of that existed.** A rule created through that page was saved and then never ran. If you configured actions there, nothing was lost: the records are still stored, and the migration below turns each into a flow that does run.
:::

## Goal

By the end you will have migrated any existing automatic actions to flows, and know where to build new ones.

## Prerequisites

- Administrator role on the Nextcloud instance, and shell access for the migration command.
- OpenRegister installed (Dossiq requires it).
- Filinq installed, for any step that generates a document, including the **Generate document** button on a case.
- Decidiq installed, for any step that asks for a decision.
- Integriq installed, for any step that calls a webhook.
- The user you migrate as must belong to an organisation. A flow takes its owner and organisation from that user, permanently.

## Migrate existing automatic actions

Run a dry run first. It writes nothing and shows exactly what would be created:

```bash
occ dossiq:actions:migrate-to-flows --user=<uid> --dry-run
```

```
dossiq:actions:migrate-to-flows (dry run, nothing was written)
  total    = 1
  created  = 1
  updated  = 0
  skipped  = 0
  failed   = 0
  [created] dossiq:automaticAction:<tenant>:<slug> (dry run, no write)
```

Then run it for real:

```bash
occ dossiq:actions:migrate-to-flows --user=<uid>
```

Each action becomes a flow around the step of the app that owns it: `sendEmail` and `notifyRole` become OpenRegister's mail and notification steps, `createDocument` and `mergeTemplate` become Filinq's document step. The configuration is translated: `{{case.title}}` in a template becomes `{{ title }}` in a mail and `{{ item.title }}` in a document.

The command is safe to re-run: the second run reports `updated` rather than creating a duplicate.

:::caution The migrated flows are enabled
These actions have never fired before. Migrating them makes them runnable, so review each one in the flow editor before triggering it. A mail step only mails an address that the case itself holds; a different address is refused and shows in the run log. Each migrated flow uses a **manual** trigger, so it runs only when someone runs it. It will not start firing on its own.
:::

Reading the summary:

| Outcome | Meaning |
|---|---|
| `created` | A new flow was made for this action. |
| `updated` | The flow this command made earlier was refreshed. |
| `skipped` | The action cannot become a flow step. The row says why: nothing replaces its type, its configuration cannot be carried over faithfully, or the app that owns the step is not installed. |
| `failed` | The record is missing its tenant or slug and cannot be identified. Fix the record and re-run. |

## Build and run actions

1. From the Dossiq navigation, open **Automatische acties** in the configuration block. It takes you to OpenRegister's **Flows** page.
2. Use **New flow**, or open a migrated flow to review it.
3. A runnable flow needs an entry and an exit: a trigger step, your action steps, and an end step, wired with edges. The migration builds exactly that shape.
4. Pick steps from the node catalogue by what they do; the table at the top of this page says which app provides each.

## Verification

You have it working when the flow appears on the **Flows** page with app `dossiq`, and running it produces the effect you configured: the email arrives and shows on the case timeline, the document appears in the case dossier, the field on the case changes.

## Common issues

| Symptom | Fix |
|---|---|
| `occ dossiq:actions:migrate-to-flows` says `--user is required` | It has no default on purpose: the created flows inherit that user's identity and organisation permanently. Pass a real uid. |
| The command reports `OpenRegister exposes no FlowService on this instance` | OpenRegister is missing or too old. Flows live in OpenRegister. |
| The upgrade log says a webhook step "could not be carried over" | The log gives the reason. Integriq is not installed, or it refused the URL (not http or https, or a local or private address this instance may not call), or the step's URL was chosen per tenant when it ran, or the step sent an `Authorization` or other credential header. Install Integriq, or set the credential on the source in Integriq, then run the upgrade again, or rebuild the step with Integriq's source call step. Until then the flow stops at that step. |
| A webhook on a status transition fails, saying the run is unattributed | Integriq makes a call only on behalf of a user, and this transition was made by the system rather than by a person. Move the webhook into a flow that runs as its owner. |
| A webhook needs a secret | The source Integriq created for it carries none. Open the source in Integriq and add the credential there. Every step calling that host uses it. |
| The upgrade log says a step "could not be carried over" for another reason | The log names the flow, the step and the reason, for example a template that reads a nested case field in a mail, or a mail that used a stored email template. Rebuild the step in the flow editor. |
| A `scheduleReminder` action was `skipped` | Dossiq no longer offers a reminder action. It never sent a reminder: the job it queued did not exist. The stored record is kept, but no flow is made from it. Build the reminder as a flow instead. |
| **Generate document** says it needs Filinq | Install and enable Filinq. Dossiq no longer renders documents itself. |
| A flow saves but will not run | Check it has both a trigger step and an end step. OpenRegister reports a flow with neither as not runnable. |
| Actions attached to a status transition are not on this page | Those are a different mechanism: they live on the case type's workflow, not here. A transition action of a retired type (send email, notify, set field, webhook) still runs, as the step that replaced it. See [Configure case types and workflows](./01-configure-case-types.md). |

## Reference

- [Configure case types and workflows](./01-configure-case-types.md): status-transition actions, which are configured on the case type.
- [Case management](../../Features/case-management.md): the case lifecycle these actions hang off.
