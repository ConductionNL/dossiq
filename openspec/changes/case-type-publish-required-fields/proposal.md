# Required case type fields are a publish-time check

## Why

The retired admin editor refused to save a case type without title, purpose,
trigger, subject, processing deadline, origin, confidentiality and
responsible unit. Making those `required` in the caseType schema would refuse
about 25 shipped templates, and a responsible unit belongs to each
organisation, so a template cannot honestly carry one. Ruben decided they
become a check at publication instead.

## What changes

- PublicationChecks refuses to publish a case type while any of the eight
  fields is empty, with one finding per field.
- The schema's `required` stays `["title"]`; drafts can be saved incomplete.
