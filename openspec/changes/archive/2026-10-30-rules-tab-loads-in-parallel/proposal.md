# Proposal: rules-tab-loads-in-parallel

## Why

On :8080 (24 Oct, b35b) the Rules tab of a case type (DqZaaktypeRegels) showed only a spinner when measured. The tab
reads eight things one after the other: the rule vocabulary, the case schema, the case type, its blueprint, three
catalogue lists and the rule inventory. Timed as the board user they take 1.7 to 3.3 seconds each, about 17 seconds in
all, and nothing shows until the last one answers.

## What changes

RulesTab asks for them side by side: vocabulary, schema, inventory and the case type reads at once, and within the case
type read the case type, blueprint and the three lists at once (the property and status lists only when there is no
blueprint, as before). What it shows and how a refused inventory reads are unchanged. Expected wait: the slowest read,
about 3 seconds.
