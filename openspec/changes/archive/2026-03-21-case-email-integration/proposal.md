# case-email-integration Specification

## Problem
Send and receive email from within case context. Emails are converted to PDF and stored as case documents, creating a complete communication audit trail. Template variables from case data enable consistent correspondence.

## Proposed Solution
Implement case-email-integration Specification following the detailed specification. Key requirements include:
- Requirement 1: Send email from case context
- Requirement 2: Email templates per case type (zaaktype)
- Requirement 3: Inbound email linking
- Requirement 4: Email threading
- Requirement 5: Email-to-PDF conversion

## Scope
This change covers all requirements defined in the case-email-integration specification.

## Success Criteria
#### Scenario 1.1: Send email with case template
#### Scenario 1.2: Send ad-hoc email without template
#### Scenario 1.3: Send email with case document attachments
#### Scenario 1.4: Send email with CC and BCC recipients
#### Scenario 1.5: Prevent sending from closed case
