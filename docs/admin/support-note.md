---
id: support-note
title: The support note
sidebar_position: 6
description: How to switch off the "Support Dossiq" note that opens once for each user, for a demo or an organisation that does not want it.
---

# The support note

Once the welcome tour is behind a user, Dossiq opens a short note from its makers on their next load: "Support Dossiq", with a way to leave a review, ask for a feature or donate. It opens once per user.

For a demonstration, or an organisation that would rather its staff never see it, an administrator can switch it off for everyone:

```bash
occ config:app:set dossiq show_support_note --value=false
```

Switch it back on by setting it to `true` or deleting the key:

```bash
occ config:app:delete dossiq show_support_note
```

`false`, `0`, `no` and `off` switch the note off. Anything else, and no value at all, leaves it on. The setting takes effect on the next page load and does not touch the tour.
