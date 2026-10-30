# Tasks: debt-ratchet-back-to-its-baseline

Kind: code. No runtime behaviour changes: the PHP edits are a docblock tag and a comment, and the
baseline is data the ratchet reads.

## 1. Remove the suppressions that hide nothing

- [x] 1.1 Drop `@psalm-suppress UnusedClass` from `lib/BackgroundJob/LinkCollectionToCaseJob.php`.
  Verify `composer psalm` reports no `UnusedClass` for the file, because `psalm.xml` suppresses
  the issue class app-wide.
- [x] 1.2 Drop `@SuppressWarnings(PHPMD.StaticAccess)` from
  `lib/Controller/Support/TranslatesRefusals.php` and put the reason in a comment at the
  `\OCP\Server::get()` call. Verify `composer phpmd` reports nothing new.
- [x] 1.3 Drop `@SuppressWarnings(PHPMD.UnusedFormalParameter)` from
  `lib/Service/Transitions/DocumentAssessmentGuard.php`. Verify `composer phpmd` over `lib`
  reports nothing new, as it does not for the three sibling guards.

## 2. Raise the baseline by the four suppressions that stay

- [x] 2.1 `eslint.inlineDisables` 14 to 15, for `FirstTodayWidget.vue`.
- [x] 2.2 `phpmd.suppressWarnings` 182 to 185, for `GuardRegistry` and the two on
  `WooPublicationService`.
- [x] 2.3 Leave every counter at or below its baseline untouched.

## 3. Verify

- [x] 3.1 `node scripts/debt-ratchet.js` exits 0.
- [x] 3.2 `composer phpmd`, `composer psalm` and `npm run lint` are no worse than on a clean
  checkout of `origin/development`, same commands, both numbers in the PR body.
- [x] 3.3 `vitest run tests/vitest/debtRatchet.spec.js` passes; it drives the script over a
  fixture tree through `DEBT_RATCHET_ROOT`.
- [x] 3.4 PHPUnit for the changed classes.
