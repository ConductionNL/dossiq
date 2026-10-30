# Design: seeded-case-types-are-published

## Decision: once per instance, by seed slug

The stored value cannot tell an unset seed from a deliberate draft: both read
`true`, because OpenRegister stores the schema default. So the step cannot ask
"was this left unset". It changes only the 13 case types named by their seed slug
(`@self.slug`), and records `seeded_case_types_published = 1` in the app config
when every write succeeded. After that it does nothing, so an administrator who
makes one of them a draft again keeps that choice. A failed write leaves the
marker unset, so the next repair tries again.

## Decision: no boolean filter in the read

The step reads all case types and matches slugs and `isDraft` in PHP. A boolean
`false` filter on OpenRegister's search is currently unreliable (finding O1), and
this step must not depend on it to find the rows it changes.

## Decision: the seeds state isDraft explicitly

A test asserts every shipped case type in the register, its fragments and the two
seed files declares `isDraft`, so a new seed cannot silently ship a draft again.
