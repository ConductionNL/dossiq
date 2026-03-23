# case-sharing-collaboration Specification

## Problem
Share case access with external parties (ketenpartners) for inter-organizational collaboration on cases. Supports both token-based access for ad-hoc sharing and account-based access for recurring partners, with scoped permissions controlling what shared parties can view and do.

## Proposed Solution
Implement case-sharing-collaboration Specification following the detailed specification. Key requirements include:
- Requirement: Share case with external party via secure token link
- Requirement: Share case with registered partner organization
- Requirement: Granular permission levels with field-level control
- Requirement: Share lifecycle management
- Requirement: External access activity tracking

## Scope
This change covers all requirements defined in the case-sharing-collaboration specification.

## Success Criteria
- Create share link with configurable permissions
- Access shared case via token with view permission
- Access shared case via token with comment permission
- Expired token shows Dutch-language error
- Password-protected share link
