# Design: substituted-work-reaches-my-work

## D-1. One extra fetch, merged client-side

`fetchSubstitutedWork()` answers the absentees' in-scope work for the
signed-in user, resolved server-side by `SubstitutionService`. My work
merges it with `mergeSubstitutedCases()` and marks each row through
`substitutedFor()`. The toggle "Show substituted work" defaults on and is
remembered per user in local storage.

## D-2. Leave sets the period, when there is leave

`SubstitutionService::isActive()` reads an approved humaniq `LeaveRequest`
for the absentee covering today (one bounded query by user and date). When
one exists, the substitution is active for the leave's period even if its
typed dates differ; when none exists, the typed dates rule. humaniq absent
(`class_exists` false or no register) means the typed dates rule, logged
once at info. Nothing writes to humaniq.

## D-3. The dead helpers live or go

Each helper in `substitutionHelpers.js` gets a call site in D-1 or is
deleted. A vitest imports the file and asserts every export is used by
My work.
