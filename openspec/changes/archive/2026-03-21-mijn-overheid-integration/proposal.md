# mijn-overheid-integration Specification

## Problem
Send official government messages to the national Mijn Overheid Berichtenbox from within Procest case context, and provide citizen portal integration for case status tracking. Mijn Overheid is the government-mandated channel for official citizen correspondence. Messages follow strict format requirements and support read tracking. This integration also covers DigiD-authenticated status page access and proactive case status push notifications.

## Proposed Solution
Implement mijn-overheid-integration Specification following the detailed specification. Key requirements include:
- Requirement 1: Send messages to Berichtenbox
- Requirement 2: Bericht type codes for message categorization
- Requirement 3: Read tracking for sent messages
- Requirement 4: Message format compliance
- Requirement 5: Case status push to Mijn Overheid

## Scope
This change covers all requirements defined in the mijn-overheid-integration specification.

## Success Criteria
#### Scenario 1.1: Send a simple text message
#### Scenario 1.2: Send message with PDF attachment
#### Scenario 1.3: Reject message without BSN
#### Scenario 1.4: Send decision notification (beschikking)
#### Scenario 1.5: Batch message sending
