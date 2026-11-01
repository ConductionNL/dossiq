# Design: case-access-card-lists-team-and-group-grants

## D-1. The source

```
GET /apps/openregister/api/objects/dossiq/case/@objectId/grants?principalTypes=user,group
```

Shape and origins are defined in openregister
`openspec/changes/an-object-lists-its-grants-with-their-origin/design.md` (D-1, D-2). dossiq
reads `results` and renders it. It merges nothing, fills nothing in and caches nothing, the
same rule `src/services/caseAccessApi.js` follows (D-5 of `case-grants-name-their-source`).

`principalTypes=user,group` keeps `authenticated` and `public` out, so the relative rule
"whoever is the assignee" does not become a row called "authenticated". The assignee is
already on the card above (Partijen) and in the case header.

The service account `dossiq-background-service` holds rights on every case. Its row is
hidden by id in the card config (`hideWhen: principal.id in [...]`), not by the endpoint,
because which principals are plumbing is this app's knowledge.

## D-2. Rows

The grant rows follow the link rows in the same card, under the same rule line:

| field | value |
|---|---|
| avatar | `AccountGroup` icon for `principal.type` `group`; initials of `principal.displayName` for `user`; 32px, the link rows' size |
| title | `{principal.displayName}`, falling back to `{principal.id}` |
| origin line | see D-3 |
| pill | `Verloopt {expiresAt|date}` when a share has an end date |
| row action | none |

## D-3. The origin line

Built from configuration, in this order, first match wins:

| when | en | nl |
|---|---|---|
| `origin` is `rule` and `matchedOn` contains `caseType` | via the case type | via het zaaktype |
| `origin` is `rule` and `role` is set | via the role {role} | via de rol {role} |
| `origin` is `rule` | via a rule on all cases | via een regel op alle zaken |
| `origin` is `shared` | shared by {sharedBy.displayName} | gedeeld door {sharedBy.displayName} |
| `origin` is `inherited` | via the main case, shared by {sharedBy.displayName} | via de hoofdzaak, gedeeld door {sharedBy.displayName} |
| `origin` is `owner` | owner of the case | eigenaar van de zaak |

When `sharedBy.displayName` is null (the sharer's account is gone), the line reads "shared by
a former user" / "gedeeld door een oud-gebruiker".

## D-4. Who sees the rows

OpenRegister refuses the read with 403 unless the caller may read the case's grants. dossiq
writes who that is, in a new register fragment `lib/Settings/register.d/99-case-grants-read.json`:

```json
{"components": {"schemas": {"case": {"authorization": {
  "readGrants": [
    {"group": "authenticated", "match": {"assignee": "$userId"}},
    "dossiq-background-service"
  ]
}}}}}
```

This matches who sees the case's links (`/api/access-links/case/{id}` answers only for an
assignee). The owner, administrators and `grant_manage` holders are admitted by OpenRegister
without this rule. `CaseTeamAccessProjector` must carry the key through when it recomputes
the case block from the shipped base (its D-3 already starts from the merged fragments).

A 403 shows the card's existing error text for this section: "Only the people this case is
assigned to can see who has access."

## D-5. One card, two sources

The record list takes one `endpointSource` today. The board draws both kinds of rows in one
card, so nextcloud-vue's record list gains `sections`: a list of `{endpointSource, avatar,
title, meta, pill, rowAction, emptyText, errorText}`, rendered in order under one header.
A section that fails shows its own error text and leaves the other section standing. The
existing single-source shape stays valid. Per the rule that a blocking dependency is built
where it lives, this is a nextcloud-vue PR, not a dossiq workaround.

If the record list's `meta` takes no `cases` (D-3), the same nextcloud-vue PR adds it, with
the `when` vocabulary the `pill` already uses plus `op: contains` for arrays.
