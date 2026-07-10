---
sidebar_position: 9
title: Share cases with another organisation
description: Federate cases to an organisation on another Nextcloud instance — a whole case type, a single confidential case, or automatically by rule — and read or edit them across the federation.
---

# Share cases with another organisation

Procest cases live in OpenRegister, and OpenRegister can **federate** — share objects with an organisation on *another* Nextcloud instance, the way Nextcloud already shares files between servers. Once federated, the other organisation sees your cases as native, live cases: they open in the list, on the map and on detail pages, and (if you allow it) they can edit them, with every change written straight back to your instance.

This tutorial walks the three ways to share and how the other side consumes them.

## Goal

By the end you will have paired two instances, shared a whole case type, shared a single confidential case on its own, edited a case across the federation, and set up a flow that shares matching cases automatically.

## Prerequisites

- Two Nextcloud instances, each running Procest + OpenRegister, that can reach each other over HTTPS.
- On each instance you are an organisation admin (see [Admin settings](../admin/03-admin-settings.md)).
- The two instances are **trusted servers** of each other (Nextcloud *Settings → Administration → Sharing → Federation*), so they can exchange federated shares.

## 1. Pair the organisations

Each organisation has a **federation address** of the form `slug@host` — its OpenRegister organisation slug plus the instance host, e.g. `bauamt@stadt-b.example`. You share *to* that address. Confirm the address of the organisation you want to share with before you start.

## 2. Share a whole case type

Use this when the whole set is meant to be shared — e.g. all **Freedom of Information Request** cases, or all WOO publications.

1. Open the register/schema the case type belongs to.
2. Choose **Share → With another organisation**.
3. Pick **scope = schema** (the case type), set **permissions** to *Read* or *Read & write*, and enter the target `slug@host`.
4. Confirm. A scoped share is created and offered to the other organisation over OCM.

The other organisation accepts the share and the cases appear live on their side. Because this is a schema-wide share, **cases marked confidential are automatically withheld** — a whole-case-type share can never leak a confidential case.

## 3. Share a single confidential case

Cases carry a **confidentiality** level. When only one specific (possibly confidential) case should go to a partner:

1. Open the case.
2. Choose **Share → With another organisation**.
3. Scope is **object** (this case only). Set permissions and the target `slug@host`.
4. Confirm.

Only that exact case is served — nothing else in the case type, confidential or not.

## 4. Read and edit across the federation

On the receiving instance the shared cases behave like local ones: they list, filter, map and open normally, always showing the **current** state (reads are live, not a copy).

If you granted **Read & write**, the partner can edit a shared case. Their save is written back to *your* instance — the source case changes, and the change is recorded in the audit trail on both sides. A federated editor can only ever write into the sharing organisation, so an edit can never plant a case somewhere it doesn't belong.

## 5. Share automatically by rule (a flow)

Instead of sharing case by case, let a **flow** do it. On the case type's schema, add a `federate-share` action to `x-openregister-flows` and give the flow a condition that decides which cases qualify:

```json
{
  "x-openregister-flows": [
    {
      "name": "share-published-woo",
      "trigger": "updated",
      "actions": [
        { "type": "federate-share", "sharedWith": "partner@stadt-b.example", "permissions": "read" }
      ]
    }
  ]
}
```

Now every case that meets the flow's condition (for example *published* and *public*) is shared with the partner organisation the moment it qualifies — no manual step. The action is idempotent, so re-saving a case never creates duplicate shares.

## Revoking

Open the organisation's federated-shares list and **revoke** any share. Access stops immediately — the token is invalidated and the partner's live view goes empty.

## See also

- OpenRegister → **Federation** (concept, API reference and security model).
- [See your cases on the map](./04-see-cases-on-the-map.md) — federated cases with geometry plot on the partner's map too.
