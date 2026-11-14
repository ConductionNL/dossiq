# Proposal: woo-redaction-reads-filinqs-detector-report

## Why

Filinq now refuses an anonymisation run with no live entity detector instead
of filing a copy of the input as the anonymised document, and reports on
every completed run which detector looked and whether it found anything
(filinq change `anonymisation-fails-closed-without-a-detector`, branch
`fix/anonymisation-fails-closed`, REQ-DDADH-002 and REQ-DDADH-003).
`FilinqRedactionClient` infers "nothing detected" from an empty entity list
and turns filinq's refusal into a generic failure, so a Woo officer cannot
tell "switch detection on" from "filinq broke" (dossiq#3191).

## What changes

- A refusal (`DetectionUnavailableException`, recognised by name) becomes the
  outcome `detection_unavailable`, carrying filinq's reason and the backend it
  named. The document falls to manual redaction with the reason
  `filinq_has_no_live_detector`.
- On a completed run the backend is read from filinq's `detection.backend`
  when present, else from OpenRegister as before, and filinq's
  `detection.outcome: nothing_found` is `no_entities_detected`.

## Out of scope

- The HTTP 503 `detectionUnavailable` body: dossiq calls filinq's service in
  process, where the refusal is the exception.
