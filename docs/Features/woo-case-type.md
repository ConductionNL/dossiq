# WOO Case Type

The WOO (Wet open overheid / Open Government Act) case type handles requests for government information disclosure.

## Overview

The WOO replaced the older WOB (Wet openbaarheid van bestuur) and requires Dutch government organizations to proactively publish information and handle disclosure requests within strict timeframes.

## Planned Features

- **WOO request intake** -- Structured intake form for WOO disclosure requests.
- **Scope assessment** -- Determine which documents fall within the scope of the request.
- **Document collection** -- Gather relevant documents from various sources.
- **Redaction support** -- Mark documents or passages for redaction with legal grounds.
- **Third-party consultation** -- Track consultations with third parties mentioned in documents (zienswijze procedure).
- **Decision recording** -- Record the formal disclosure decision per document.
- **Publication** -- Publish disclosed documents on the WOO platform.
- **Deadline tracking** -- 4-week initial deadline, extendable by 2 weeks.
- **Appeal handling** -- Link to bezwaar (objection) cases if the decision is appealed.

## Legal Requirements

The WOO prescribes:
- Response within 4 weeks (extendable by 2 weeks).
- Mandatory grounds for refusal (e.g., personal privacy, business confidentiality, state security).
- Proactive publication obligations.

## Status

This feature is defined in the spec at `openspec/specs/woo-case-type/spec.md` and is planned for future implementation.
