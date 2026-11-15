---
id: intro
title: Introduction
sidebar_position: 1
description: Dossiq is the case management system for Nextcloud. It follows the ZGW APIs and CMMN, runs flows per case type and archives inside the workspace.
---

# Dossiq documentation

Dossiq is the case management system for Nextcloud. You handle a case from request to decision to archive, in the workspace your staff already use.

We built it with Nextcloud, for the municipality of Baarn and the Open Webconcept municipalities.

## Start here

| You want to | Read |
|---|---|
| Know what Dossiq can do | [What Dossiq can do](./capabilities.md) |
| See what it looks like | [Screens](./screens.md) |
| Know how it archives | [Archiving](./archiving.md) |
| Install it | [Installation](./installation.md) |
| Read one feature in depth | [Feature documentation](./Features/README.md) |

![The Dossiq dashboard](/img/screens/DqDashboard.webp)

## What sets it apart

- **Dutch and European standards.** Cases follow the ZGW APIs. A case type can also run as a CMMN case plan.
- **Flows per case type.** You set phases, tasks, rules and decision tables per case type. A new procedure is configuration, not custom code.
- **Inside Nextcloud.** Documents are Nextcloud files, mail is Nextcloud Mail, rights are Nextcloud groups. Dossiq fits a sovereign workspace that runs on Nextcloud, such as Mijn Bureau.
- **Built on Nextcloud Tables.** We build Dossiq on Nextcloud Tables. Together with Nextcloud we bring Tables in line with the principle of data at the source.
- **Fit for the archive.** Retention, destruction and transfer to the e-Depot belong to the case.

## Architecture

- **Frontend**: a Vue.js app inside the Nextcloud framework.
- **Backend**: PHP, as a Nextcloud app with controllers and services.
- **Data layer**: OpenRegister, with typed schemas, an audit trail and retention. OpenRegister serves a table from Nextcloud Tables as a register, without copying it.
- **API**: ZGW-compatible REST API through configurable field mapping.
- **AI**: Nextcloud ExApps, such as Ollama and Presidio.
- **Theming**: NL Design System tokens.

Read the [architecture](./Technical/architecture.md) for the detail.
