---
kind: code
---

# Proposal: notification-email-opt-in

## Why

Board DqPersoonlijkeInstellingen draws the notification settings with two
columns, "In Nextcloud" and "Per e-mail", e-mail switched on for some
notifications only. dossiq declared Nextcloud notifications only, and the
settings drew one column. Ruben decided (25 October 2026): e-mail is an opt-in
channel, off until a person switches it on.

## What changes

- Every per-person dossiq notification (caseAssigned, caseDeclaredMajor,
  caseHandoffIntake, caseMovedForItsFollowers; and the mock register's)
  declares `optInChannels: ["email"]` (OpenRegister feat/notification-opt-in-channels).
- The preference mapping draws one column per channel the platform names
  (`availableChannels`), with e-mail off by default (per-channel `appDefault`,
  nc-vue feat/notification-matrix-default-per-channel), and writes a row's
  whole channel set when one cell changes.
- Column names: "In Nextcloud", "Per e-mail".

Generic: the columns come from what the platform declares, not from dossiq
naming e-mail; a platform without channels still gets one column.
