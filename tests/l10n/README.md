# l10n checks

## Register the merge driver first

```bash
git config merge.l10n.name 'l10n catalogue key-wise merge'
git config merge.l10n.driver 'node tools/merge-l10n.js %O %A %B %P'
```

`npm install` runs this for you. Run it by hand in a clone that already installed. Without it, `l10n/en.json` and `l10n/nl.json` merge line by line and every branch that adds a string conflicts with every other one.

Full explanation: [docs/Technical/l10n-merge-driver.md](../../docs/Technical/l10n-merge-driver.md).

## The check

```bash
npm run test:l10n          # exits 1 on any of the four failures below
npm run test:l10n:write    # extracts missing keys, sorts both catalogues
```

`check-l10n.js` reads every `t('dossiq', '...')` and `n('dossiq', ...)` call under `src/`, plus the text fields of `src/manifest.json` and `src/manifest.d/*.json`. It fails when:

1. a used key is missing from `l10n/en.json`
2. the `en.json` and `nl.json` key sets diverge, or a Dutch value is empty
3. a used key has the same value in both catalogues, so an English reader sees Dutch. Genuinely identical words belong in `language-neutral-keys.json`
4. either catalogue is out of canonical order, which is what made every branch conflict

`--write` fixes 1 and 4. It never writes a Dutch translation, because a self-map is not one.

## Files

| File | What it is |
|---|---|
| `check-l10n.js` | the check itself, pure Node, no build step |
| `language-neutral-keys.json` | words that really are identical in English and Dutch |
| `../../tools/merge-l10n.js` | the git merge driver |
| `../vitest/l10nMergeDriver.spec.js` | its unit tests |

Next: add your strings, then run `npm run test:l10n:write`.
