---
sidebar_position: 2
title: Set up automatic actions
description: "Automatic actions are OpenRegister flows. Migrate the old records, then build and run actions in the flow editor."
---

# Set up automatic actions

Automatic actions are **OpenRegister flows**. Dossiq contributes the action nodes — send an email, notify a role, call a webhook, create a document, merge a template, schedule a reminder — and OpenRegister's flow engine runs them.

:::warning What changed, and why this page was wrong

Dossiq used to have its own **Automatische acties** settings page. It has been retired.

An earlier version of this page described a rule engine with *triggers*, *conditions* and a *Last run* column. **None of that existed.** The stored record had no trigger field and no condition field, and nothing in the application ever executed one — the code that fires actions on a status change reads a different, separate definition that lives on the case type. A rule created through that page was saved and then never ran.

If you configured actions there, nothing was lost: the records are still stored, and the migration below turns each into a flow that does run.
:::

## Goal

By the end you will have migrated any existing automatic actions to flows, and know where to build new ones.

## Prerequisites

- Administrator role on the Nextcloud instance, and shell access for the migration command.
- OpenRegister installed (Dossiq requires it).
- The user you migrate as must belong to an organisation — a flow takes its owner and organisation from that user, permanently.

## Migrate existing automatic actions

Run a dry run first. It writes nothing and shows exactly what would be created:

```bash
occ dossiq:actions:migrate-to-flows --user=<uid> --dry-run
```

```
dossiq:actions:migrate-to-flows (dry run — nothing was written)
  total    = 1
  created  = 1
  updated  = 0
  skipped  = 0
  failed   = 0
  [created] dossiq:automaticAction:<tenant>:<slug> — dry run — no write
```

Then run it for real:

```bash
occ dossiq:actions:migrate-to-flows --user=<uid>
```

The command is safe to re-run: the second run reports `updated` rather than creating a duplicate.

:::caution The migrated flows are enabled
These actions have never fired before. Migrating them makes them runnable, so review each one in the flow editor before triggering it — particularly anything that sends email to an address outside your organisation. Each migrated flow uses a **manual** trigger, so it runs only when someone runs it; it will not start firing on its own.
:::

Reading the summary:

| Outcome | Meaning |
|---|---|
| `created` | A new flow was made for this action. |
| `updated` | The flow this command made earlier was refreshed. |
| `skipped` | No node implements that action's type, so no flow was written. A flow around a node that does not exist would report success and do nothing. |
| `failed` | The record is missing its tenant or slug and cannot be identified. Fix the record and re-run. |

## Build and run actions

1. From the Dossiq navigation, open **Automatische acties** in the configuration block. It takes you to OpenRegister's **Flows** page.
2. Use **New flow**, or open a migrated flow to review it.
3. A runnable flow needs an entry and an exit: a trigger node, your action node(s), and an end node, wired with edges. The migration builds exactly that shape.
4. Dossiq's action nodes appear in the node catalogue under `dossiq.action.*`.

## Verification

You have it working when the flow appears on the **Flows** page with app `dossiq`, and running it produces the effect you configured — the email arrives, the webhook is called, the document is generated.

## Common issues

| Symptom | Fix |
|---|---|
| `occ dossiq:actions:migrate-to-flows` says `--user is required` | It has no default on purpose: the created flows inherit that user's identity and organisation permanently. Pass a real uid. |
| The command reports `OpenRegister exposes no FlowService on this instance` | OpenRegister is missing or too old. Flows live in OpenRegister; Dossiq only contributes nodes. |
| An action was `skipped` | Its `type` is not one Dossiq implements a node for. The six are `sendEmail`, `notifyRole`, `callWebhook`, `createDocument`, `mergeTemplate` and `scheduleReminder`. |
| A flow saves but will not run | Check it has both a trigger node and an end node. OpenRegister reports a flow with neither as not runnable. |
| Actions attached to a status transition are not on this page | Those are a different mechanism: they live on the case type's workflow, not here. See [Configure case types and workflows](./01-configure-case-types.md). |

## Reference

- [Configure case types and workflows](./01-configure-case-types.md): status-transition actions, which are configured on the case type.
- [Case management](../../Features/case-management.md): the case lifecycle these actions hang off.
