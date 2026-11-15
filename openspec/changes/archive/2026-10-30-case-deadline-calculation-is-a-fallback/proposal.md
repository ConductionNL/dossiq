# The case deadline calculation is a fallback

## Why
`rematerialise-calculations dossiq case` reported 23 of 75 cases as not materialised, all on `deadline`. Those cases have a statutory term, and `CaseDeadlineFollowsTermListener` puts the term's end back on every save, as designed. The calculated deadline only stands for a case without a term.

## What changes
The case `deadline` calculation declares `fallback: true` (case 1.56.0). This needs openregister fix/rematerialise-calculation-an-app-may-override, which stops counting a fallback the app kept as a failure. Without it the key is ignored.
