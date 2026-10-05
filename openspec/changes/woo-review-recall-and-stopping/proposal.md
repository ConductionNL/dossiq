---
kind: code
depends_on: [woo-review-triage]
---

# Proposal: woo-review-recall-and-stopping

Woo capability programme, round 1, wave 4. Rows 19.12 and 19.13.

| row | text | our rating today |
| --- | --- | --- |
| 19.12 | The product estimates how much of the relevant material the review has found so far | no |
| 19.13 | A stopping rule for the review is declared in advance, says how much of the material must be found, and the product records that it was met | no |

Implements Ruben's decision D1: the review of a Woo request is dossiq's. Before D1 this change was
planned on opencatalogi. There is no fleet precedent for a statistical review estimate; this change
names the method so a building agent does not choose one.

## Why

After `woo-review-triage`, every collected document on a Woo case is marked in scope, out of scope
or unmarked, by a reviewer or a rule. Nothing says how much relevant material sits in the documents
set aside or not yet looked at. A large request then stops when people run out of time, and the
decision cannot say how complete the search was.

## The method

An elusion sample, the standard check in technology-assisted review:

- **found** is the number of documents marked in scope.
- **the null set** is every document marked out of scope or still unmarked; **N** is its size.
- A simple random sample of **n** documents is drawn from the null set with a recorded seed. A
  reviewer judges each one in or out of scope. **k** of them are judged in scope.
- The elusion rate is k / n. Its one-sided upper bound at confidence c is the exact
  (Clopper-Pearson) bound: the p at which the binomial probability of k or fewer in n is 1 - c.
- The recall estimate is found / (found + (k / n) x N), and its lower bound at confidence c is
  found / (found + upper x N).

Fixture used in the scenarios, computed by hand and to be kept in the tests: found 400, N 1600,
n 200. With k 2 the estimate is 0.9615 and the one-sided 95 percent lower bound 0.8892. With k 10
the estimate is 0.8333 and the lower bound 0.7500.

## What changes

1. **A stopping rule declared in advance** (19.13): a Woo case declares `targetRecall` (for example
   0.80) and `confidence` (for example 0.95) before the first relevance marking. After that the rule
   cannot be changed.
2. **An elusion sample and a recall estimate** (19.12): the handler draws a sample from the null set;
   its documents are reviewed in a sample batch; the estimate, its lower bound, n, k, N and found are
   shown on the case and stored with the date. A new sample can be drawn later; every estimate is
   kept.
3. **The rule recorded as met** (19.13): when an estimate's lower bound at the declared confidence
   reaches the target, dossiq records a `stoppingRuleMet` event on the case: an audited record with
   the estimate it rests on, and an internal timeline entry.

## What does not change

- Relevance marking, rules, batches and pages seen (`woo-review-triage`).
- Nothing stops the review automatically. The product records that the rule was met; the handler
  decides to stop.

## Dependencies

- Planned, dossiq, wave 3: `woo-review-triage` (relevance on each document, batches).

No other app is involved.

## Wave and done

Wave 4. Done means merged on `development` with CI green. 19.12 and 19.13 then read `yes` (build),
and `production` only once a dossiq store release carries them.
