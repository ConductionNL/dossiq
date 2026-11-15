---
kind: code
depends_on: []
---

# Proposal: ai-features-on-the-case-consume-hermiq

## What this is

hermiq's wave 3 shipped five changes that end at a contract, and every one of
them names dossiq as the app that consumes it:

| hermiq change | PR | what it left for dossiq |
|---|---|---|
| `a-provider-and-a-place-per-ai-feature` | hermiq#896 | declare features; read which provider and residency each will use |
| `what-the-model-reads-and-what-is-kept` | hermiq#898 | pass a document reference; show the refusal; inherit the retention |
| `the-declared-tool-surface-and-the-prompt-library` | hermiq#899 | declare the tools and ship an initial prompt library |
| `identical-reports-collapse-into-one` | hermiq#900 | ask per report which group it is in; decide what a group means |
| `a-conversational-intake-that-files-for-the-citizen` | hermiq#902 | declare a create-only intake tool and the request catalogue |

This change is dossiq's half of all five, written as one because they are one
sentence: **dossiq declares and renders, hermiq keeps the logic and the provider
choice.** Splitting it into five would give five case-type screens for one
question a beheerder asks once.

## Why it is worth writing down

The division is easy to state and easy to lose. Every one of these five has a
tempting dossiq-side shortcut that would work on the day and be wrong afterwards:

- a second provider setting on the case type, so a gemeente can "just pick a model
  here", which puts the jurisdiction answer in two places that will disagree;
- a `str_contains` duplicate check beside hermiq's grouping, because two meldingen
  about one storing are obviously alike;
- a second create path for the intake, because the existing one asks for fields a
  conversation has not collected yet;
- a locally cached copy of a prompt, so the case screen renders without a round
  trip, which is a prompt an administrator cannot edit.

Each of those is a second copy of a rule that already exists somewhere, and the
second copy is always the one that is out of date. So the requirements below are
mostly about what dossiq does **not** hold.

## What dossiq builds

- **A case type says which AI features are on, and where.** Per case type, per
  feature, with the surface named: the case detail panel, the intake form, or
  neither. A feature nobody switched on renders nothing and costs nothing.
- **The provider and the place are read, never chosen.** The case type screen shows
  which provider each feature will use and where that provider runs, read from
  hermiq's register. There is no provider field on a dossiq case type.
- **A document reference travels with the request, and a refusal is shown as one.**
  When a feature reads a document, dossiq passes the reference and hermiq decides
  whether it may be read. A refusal is rendered with the feature and the reason
  named, so a handler reads why rather than an error.
- **Identical reports collapse on the case, and dossiq decides what that means.**
  dossiq asks hermiq which group an incoming report belongs to and renders the
  count with the near-duplicates beside it. Whether a group becomes one case with
  many reporters stays dossiq's, and so does every confirmation of receipt.
- **A conversational intake files through the path that already exists.** dossiq
  declares its create-only intake tool and its request catalogue. The tool calls
  the same creation path the create form uses. No second write path, no second set
  of rules about what a valid case is.

## What dossiq does not build

- No provider, model or residency setting of its own.
- No duplicate or similarity scoring of its own beside hermiq's grouping. Ledger
  row 2.24, one person filing the same thing twice, keeps its own deterministic
  check: that is a different test and it stays where it is.
- No prompt text in code for anything the assistant offers on a case. dossiq ships
  an initial library into hermiq and stops owning it the moment an administrator
  edits one.
- No sentiment model. `SentimentService` already scores a contactmoment by rule,
  and hermiq prefers a deterministic signal where one exists. dossiq keeps the
  rule and hands hermiq the answer.
- No retention timer. hermiq writes the retention onto the run; dossiq's AI audit
  export keeps its shape and gains an expiry it did not have.

## Size

**Size: M.** One case-type declaration, one read-through surface, one reference
passed, one grouping call rendered, one tool declared against an existing path.
