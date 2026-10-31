# Proposal: organisations-count-pills

## Why

Board DqOrganisaties (measured r25b, 24 Oct):

- the counts in Lopende zaken and Open tickets are purple pills ("1", "3");
- an empty count is muted text ("Geen");
- KvK-nummer and Rechtsvorm are wide enough to read.

Live draws every count, "Geen" included, as a grey pill, and cuts "KvK-nummer", "Rechtsvorm", "Besloten Ven..." and
"Nog geen co...".

## What changes

On the Organisations list:

- Open cases and Open tickets get `widgetProps.variant: "purple"` and `aggregate.zeroAs: "text"`;
- the columns get the board's widths (KvK 130, Legal form 151, Place 116, Open cases 132, Open tickets 120,
  Last contact 142 px);
- Legal form wraps.

## Scope

Config only (`src/manifest.json`). The muted "Geen" needs nextcloud-vue fix/aggregate-zero-as-text (24t1m); before it,
the zero stays a pill (now purple).
