# Side statuses off the stages strip

## Why

Ruben (27 Oct): a social-domain case shows the stages strip, main path only, as DqZaak draws it (design-system #678). Jeugdwet's "Verlenging aangevraagd" and "Ingetrokken" are side statuses and the strip drew them.

## What changes

- statusType 1.9.0 gains `offPath` (register.d/99-status-type-off-path.json).
- The case-stages widget names `offPathField: "offPath"` (nc-vue 24t2j 5437c6c5e).
- jeugdwet 1.14.0 marks the two side statuses; every other package marks its statuses at order 90 and up (aanbesteding 1.5.0, bezwaar-beroep 1.6.0, bijstandsaanvraag 1.2.0, re-integratietraject 1.3.0, subsidie 1.4.0, wmo-melding 1.1.0).
