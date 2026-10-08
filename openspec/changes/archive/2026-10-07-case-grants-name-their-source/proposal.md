---
kind: code
depends_on: []
---

# Proposal: case-grants-name-their-source

Round 4 discovery, cluster 11 "Who may do what: roles, grants and their
provenance" and cluster 54 "Access compiled into the query"
(`procest/_round4/discovery/build-plan.md` in
ConductionNL/market-intelligence, 2026-09-14). Cluster 11 holds 23
candidates with 14 `must` and 5 matrix holes, twelve driven passers,
proving system Forgejo. Owner openregister, size L, wave 1. Decision D22,
and D10 beside it. This change is dossiq's half.

## Why

C-access-and-privacy-45's clause: "a gemeente must be able to prove after
the fact who could open a dossier, and no product in the corpus has been
asked this". dossiq answers per transition at write time and has no
effective-permission reader at all.

## The candidates this change's half touches

| candidate | relevance | dossiq | what it asks |
|---|---|---|---|
| C-access-and-privacy-45 | must, matrix hole | no | ask the product who holds which right on a named object, and where each grant came from |
| C-access-and-privacy-62 | must, matrix hole | partial | permission conditions compiled into the query or the index, so the engine filters |
| C-access-and-privacy-46 | must | partial | case-type rights as a department by role matrix, separately per confidentiality level |
| C-access-and-privacy-47 | should | no | case types grouped, and rights granted on the group |
| C-access-and-privacy-64 | must, matrix hole | yes | permissions expressed per action rather than as read and write |
| C-access-and-privacy-79 | must, matrix hole | yes | the record is returned with the actions the current user may take on it |

The proving passer on C-access-and-privacy-45, verbatim from the lane
(`_round4/discovery/candidates.json`, `access-and-privacy.tsv:17`):
"forgejo: /repos/{owner}/{repo}/collaborators/{c}/permission,
/orgs/{org}/permissions, /user/permission (api.go)". Four driven passers:
Forgejo, Gitea, OpenCase and Request Tracker.

On C-access-and-privacy-62, `access-and-privacy.tsv:15`, three driven:
Dimpact ZAC, OpenCase and Valtimo. Its clause: "third independent passer
for this idea after OpenCase's access profile and Valtimo's query
predicates".

**Two of them dossiq already passes**, and the sweep proved it against
`development` rather than by vote. `found-and-lacking.md` lists both among
the twenty it overturned: "`CaseActionProvider.php:178` answers available
actions per calling user with the guards that refused each one", and
"`WorkflowStepAuthorizationResolver` writes group ids onto each
transition, which is action-level permission". So C-access-and-privacy-79
and C-access-and-privacy-64 are `yes`, and this change protects them
rather than building them.

**D6 was answered relevance-led**, so every `must` here enters the corpus
whatever its passer count, including C-access-and-privacy-46, which has
one driven passer. **D17 was answered for a broad market**, and none of
the twenty `not` candidates is in either cluster.

## The decision this rests on

D22, answered as recommended: **option 1, openregister, in the query
layer.** Verbatim: "It is the ownership rule's clearest case: access is a
property of the object, the object lives in openregister, and a filter
that runs after the query has already leaked the count."

And the instruction D22 gives the owner's proposal, which this change
repeats so it survives the hand-off: "Name it explicitly in that proposal,
because 'compiled into the query' and 'checked on the result' are the same
sentence in English and different products in practice."

## What dossiq does

- Declares its rights per case type as a department by role matrix, with
  the confidentiality dimension C-access-and-privacy-46 asks for.
- Declares rights on a group of case types, not only on each one, so a
  samenwerkingsverband grants once.
- Shows a refusal that names the rule that refused it, rather than a bare
  403 a user discovers by clicking.
- Shows, on the case, who holds which right and where each grant came
  from, reading openregister's provenance and computing none of it.
- Keeps `CaseActionProvider` as the answer to "what may I do", and makes
  it read openregister's effective grants rather than only its own guards.

## Ownership

openregister owns the grant, its provenance, deny by default, inheritance
to children, and compiling the condition into the query. Its changes are
`permission-provenance-and-deny` and `rbac-inherits-to-children`, both
open on openregister `development`, and cluster 54's requirement is one
requirement added to the first. dossiq declares and renders. dossiq
computes no effective permission.

## Capabilities

- Modified: `case-management`: a refusal says why, and a grant says where
  it came from.

## Impact

`lib/Lifecycle/CaseActionProvider.php`, the `caseType` schema (the
department by role by confidentiality matrix and the case-type group),
`register.d/61-mandaat-matrix.json`, the case page's access panel, Dutch
and English strings.

## Out of scope

- The grant model, deny by default, inheritance and the compiled query.
  openregister, wave 1.
- Tokens and service accounts. Cluster 40, openregister, D22.
- Erasure and the data subject's own rights. Cluster 38, openregister.
- Masking a BSN and auditing the reveal, C-access-and-privacy-1. It rides
  openregister's `sensitive-field-reveal-audit` and dossiq's existing
  change `sensitive-fields-declared`.
