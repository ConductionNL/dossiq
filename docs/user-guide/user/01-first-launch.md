---
sidebar_position: 1
title: Open Procest for the first time
description: Open Procest, walk the navigation, and confirm the OpenRegister back end is wired up.
---

# Open Procest for the first time

A first look at Procest — where the app lives, what the navigation gives you, and how to tell it is connected to OpenRegister.

## Goal

By the end you will have opened the Procest app, found your way around the dashboard and left-hand navigation, and confirmed the OpenRegister-backed lists (Cases, Tasks, Bezwaren, …) load.

## Prerequisites

- A Nextcloud account on an instance where the **Procest** app is installed and enabled.
- The **OpenRegister** app installed and enabled — Procest stores cases, tasks, decisions and case types in OpenRegister, so it is a hard dependency.
- The Procest register and its schemas imported. An admin runs this once from **Administration settings → Procest → Re-import configuration** (see [Manage Procest settings](../admin/03-admin-settings.md)).

## Steps

1. Open the Nextcloud app menu in the top bar and pick **Procest**. You land on the dashboard.

   ![Procest dashboard](/screenshots/tutorials/user/01-first-launch-01.png)

2. Read the dashboard widgets — *Cases by Status*, *Cases by Type*, *My Work*, *Deadline Alerts*, *Task Due Reminders*, *Stalled Cases*, *Case Map*. On a fresh install they read *Widget not available* until cases are created and the register is fully configured.

   ![Dashboard widgets](/screenshots/tutorials/user/01-first-launch-02.png)

3. Open the left-hand navigation. The top group is your day-to-day work — **Dashboard**, **My Work**, **Work Queue**, **Cases**, **Bezwaren**, **Beroepen**, **Beslissingen op bezwaar**, **Tasks**, **Map**, **Voorstellen**, **Advice**, **BAC-adviezen**, **Transfers**. Below the divider sits the configuration group — **Case Types**, **Legesverordeningen**, **Parafeerroutes**, **Automatische acties**, **Handhavingsstrategie**, and the rest of the admin entries — ending in **Settings**.

   ![Procest navigation](/screenshots/tutorials/user/01-first-launch-03.png)

4. Click **Cases**. The list view opens with a *Cards / Table* toggle, an **Add Item** button, and a search/actions row. An empty install shows *No items found* — expected until someone creates the first case.

   ![Cases list, empty state](/screenshots/tutorials/user/01-first-launch-04.png)

## Verification

You are set up correctly when: the Procest dashboard renders without an error banner, the left navigation lists the entries above, and clicking **Cases** (or any other list) shows either rows or a clean *No items found* state — not a load error.

## Common issues

| Symptom | Fix |
|---|---|
| "OpenRegister is not installed or enabled" banner | Install and enable the OpenRegister app, then reload Procest. |
| Lists load but **Add Item** opens a dialog with no form fields | The Procest register import is incomplete — an admin re-runs **Administration settings → Procest → Re-import configuration**. |
| Procest is missing from the app menu | The app is not enabled for your account — ask an administrator to enable it (and check it is not restricted to a group you are not in). |
| Dashboard widgets all read *Widget not available* | The register is not connected — see [Manage Procest settings](../admin/03-admin-settings.md). |

## Reference

- [Case management](../../features/case-management.md) — the data model and lifecycle behind every list.
- [Manage Procest settings](../admin/03-admin-settings.md) — register import, schema mapping, ZGW configuration.
