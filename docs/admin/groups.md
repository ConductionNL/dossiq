---
id: groups
title: Groups Dossiq expects
sidebar_position: 5
description: The Nextcloud groups the shipped case flows assign work to, why Dossiq creates them at install, and what to do when your user backend refuses that.
---

# Groups Dossiq expects

The shipped case flow assigns its behandelaar step to the Nextcloud group `behandelaars`. Dossiq creates that group at install and on every upgrade. The step is idempotent: an existing group is left alone.

Membership stays yours. Dossiq never adds users to the group. Add your case handlers yourself:

```bash
occ group:adduser behandelaars <user>
```

## When the group is missing

Without the group, nobody can complete a step assigned to it. The completion signal is refused with "the user who completed the task is not the assignee of the awaiting step". That is deliberate: the gate fails closed rather than letting anyone answer.

Some user backends refuse group creation, LDAP-only setups for example. Dossiq then logs a warning during install. Create the group in your backend and the flow works without further changes.

## Which groups

| Group | Used by | Purpose |
|-------|---------|---------|
| `behandelaars` | shipped case flow, step `task-behandelaar` | case handlers who finish the inhoudelijke voorbereiding |

A test guards this table's code side: a shipped flow cannot assign work to a group the install does not provision.
