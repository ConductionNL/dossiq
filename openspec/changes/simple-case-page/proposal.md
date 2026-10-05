---
kind: code
depends_on: [simple-structure-profile]
---

# Proposal: simple-case-page

## Why

The case page declares 25 header actions in one menu, with no primary button.
A handler has to know which of the 25 is the next step. The Zuiddrecht design
(`Acties`, 4 October 2026) puts the actions on four levels: one next-step
button that follows the case's step, three quick actions, a grouped "More"
menu, and an admin group at the end.

`@conduction/nextcloud-vue` 2.60.0 carries the keys for this. This change uses
them on the case page in the simple structure. The full structure keeps the
page as it is.

## What changes

1. **The stage is on the case.** A new computed field `statusRole` holds the
   role of the status the case sits in (`statusType.role`). OpenRegister
   materialises it, the way it does `waitingOn`.
2. **The next step follows the stage.** Intake: Claim. Waiting for information:
   Remind. Handling and review: Next step, which opens the Lifecycle dialog.
3. **A what-now card per stage**, with a checklist read from fields on the case.
4. **Three quick actions:** Send digital post, Generate document, Log contact.
5. **The More menu is grouped:** Case, Publication, Dossier, and an admin group
   that only an administrator sees. Refresh and the help links leave it.
6. **A status pill** above the title.
7. **A side column** with the requester and the handling.
8. **Five tabs and More:** Overview, Documents, Contact, Tasks, History. The
   other eight tabs sit under More.
9. The library range moves from `^2.57.1` to `^2.60.0`.

## What the design asks for and dossiq cannot give yet

Named here and not invented:

- **Record the decision** as a step action. dossiq has no such action. Decidiq
  makes the decision. The review stage opens the Lifecycle dialog.
- **Publish** as a step. Publish (Woo) exists and stays in the Publication
  group. It depends on `wooPublicationStatus`, and no status role means
  "publishing".
- **Close the case** as one act. It is a choice inside the Lifecycle dialog.
- **Receipt confirmed** in a checklist. The case has no field for it. The
  acknowledgement is asked through its own endpoint.
- **A type pill.** The case holds its case type as a uuid and no name.
- **Counts on tabs.** The case carries no count of documents, messages or tasks.
- **Deadline and linked records in the side column.** The deadline tile sits in
  the page grid. Moving it means redrawing the grid, which needs a browser.
- **The six sidebar tabs under History and More.** They are sidebar components.
  The tab strip holds widgets.
- **Twelve entries at most in More.** The Case group alone holds twelve.
- **Email and Notes inside Contact.** They are widgets of their own and sit
  under More.

## Impact

- The register version moves to 0.20.15, so the import runs on an existing
  instance. After it, `occ openregister:rematerialise-calculations` fills
  `statusRole` on the cases that exist.
- A case whose status type has no role shows no card and no stage button. The
  Lifecycle entry stays first in the More menu. 90 of 125 status types on the
  dev instance have no role today.
