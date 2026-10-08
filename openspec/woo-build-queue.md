# Woo build queue for this repository

12 OpenSpec changes in this repository close gaps in the Woo capability programme. Each has a change folder under `openspec/changes/` and an issue titled `[OpenSpec] <change-name>` that the OpenSpec workflow keeps in step with the spec.

## How to pick up a change

1. Take the first change below whose dependencies are all merged on `development`. A dependency in another repository is linked to its issue there; check that issue's linked PR is merged.
2. Inside a wave, the order below is the order to build. Statutory rows come first.
3. Read `openspec/woo-build-rules.md` before the first command, then the change's `proposal.md`, its specs and its `tasks.md`.
4. The decisions the specs cite (D1 to D13) are in `openspec/woo-decisions.md`. A spec never contradicts one. If a task seems to, stop and say so in the issue.
5. Work on the branch the issue names, open one PR with `--base development`, and close the issue through the PR.

Two things need a person, not an agent: settling the Woo refusal grounds against the law (dossiq `woo-refusal-grounds-list`, task 1, blocks seeding), and the screen-reader pass for row 15.5.

## Wave 1

| change | rows | depends on |
|---|---|---|
| [dossiq/woo-refusal-grounds-list](https://github.com/ConductionNL/dossiq/issues/3288) | 12.29, 13.28 | nothing |
| [dossiq/woo-requester-notices-really-go-out](https://github.com/ConductionNL/dossiq/issues/3286) | 7.11 | nothing |
| [dossiq/woo-term-is-computed-and-reported-right](https://github.com/ConductionNL/dossiq/issues/3287) | 10.9, 16.2 | nothing |

## Wave 2

| change | rows | depends on |
|---|---|---|
| [dossiq/woo-case-screens-and-objections](https://github.com/ConductionNL/dossiq/issues/3290) | 7.13 | [dossiq/woo-requester-notices-really-go-out](https://github.com/ConductionNL/dossiq/issues/3286), [dossiq/woo-term-is-computed-and-reported-right](https://github.com/ConductionNL/dossiq/issues/3287) |
| [dossiq/woo-delivered-set-is-a-record](https://github.com/ConductionNL/dossiq/issues/3291) | 19.15, 19.16 | [openregister/object-archive-state](https://github.com/ConductionNL/openregister/issues/4390) |
| [dossiq/woo-request-corpus-collection](https://github.com/ConductionNL/dossiq/issues/3292) | 19.1, 19.2, 19.3, 19.4, 19.18 | nothing |
| [dossiq/woo-request-scoped-access](https://github.com/ConductionNL/dossiq/issues/3302) | 12.28 | [openregister/reviewer-owns-their-decisions](https://github.com/ConductionNL/openregister/issues/4394), [dossiq/woo-request-takes-over-from-opencatalogi](https://github.com/ConductionNL/dossiq/issues/3289) |
| [dossiq/woo-request-takes-over-from-opencatalogi](https://github.com/ConductionNL/dossiq/issues/3289) | supports 7.1, 7.2, 7.3, 7.4, 7.5, 7.6, 10.8 | [dossiq/woo-requester-notices-really-go-out](https://github.com/ConductionNL/dossiq/issues/3286), [dossiq/woo-term-is-computed-and-reported-right](https://github.com/ConductionNL/dossiq/issues/3287) |

## Wave 3

| change | rows | depends on |
|---|---|---|
| [dossiq/woo-decision-records-what-was-withheld](https://github.com/ConductionNL/dossiq/issues/3303) | supports 6.16 | [opencatalogi/woo-decision-shows-what-was-withheld](https://github.com/ConductionNL/opencatalogi/issues/1797), [dossiq/woo-refusal-grounds-list](https://github.com/ConductionNL/dossiq/issues/3288), [dossiq/woo-request-takes-over-from-opencatalogi](https://github.com/ConductionNL/dossiq/issues/3289) |
| [dossiq/woo-review-reports](https://github.com/ConductionNL/dossiq/issues/3293) | 16.10, 16.11 | [dossiq/woo-request-corpus-collection](https://github.com/ConductionNL/dossiq/issues/3292) |
| [dossiq/woo-review-triage](https://github.com/ConductionNL/dossiq/issues/3294) | 19.7, 19.8, 19.9, 19.10, 19.11, 19.17 | [dossiq/woo-request-corpus-collection](https://github.com/ConductionNL/dossiq/issues/3292) |

## Wave 4

| change | rows | depends on |
|---|---|---|
| [dossiq/woo-review-recall-and-stopping](https://github.com/ConductionNL/dossiq/issues/3295) | 19.12, 19.13 | [dossiq/woo-review-triage](https://github.com/ConductionNL/dossiq/issues/3294) |
