# Tasks: rebind-dialog-translated-labels

Kind: code. Fixes `case-type-rebind` and `case-type-version-chain`.

- [x] 1.1 `StatusPublicLabels::textOf()` accepts a preferred language.
- [x] 1.2 `lib/Service/Support/TranslatedText.php` resolves a value in the
  reader's language.
- [x] 1.3 `CaseTypeResolver::mergeKey()` and `tag()` read language maps.
- [x] 1.4 `CaseRebindService` labels targets, statuses, the current
  binding and the journal titles through `TranslatedText`.
- [x] 1.5 `CaseVersionDiff` names statuses through `TranslatedText` and
  reads answers through `CaseAnswerReader`.
- [x] 1.6 `CaseTypeVersionChain` names versions through `TranslatedText`.
- [x] 2.1 Unit tests: language maps in the resolver, the rebind options and
  preview, the version preview, `textOf()` language order, and the version
  move's fixture in the register's list shape. Five fail on the old code.
- [x] 2.2 Live check of the dialog on a case.
