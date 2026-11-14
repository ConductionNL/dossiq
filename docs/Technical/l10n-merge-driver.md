# Merging the translation catalogues

Run this line once per clone:

```bash
git config merge.l10n.name 'l10n catalogue key-wise merge'
git config merge.l10n.driver 'node tools/merge-l10n.js %O %A %B %P'
```

`npm install` runs it for you. Run it by hand in a clone that already installed.

## Why it exists

`l10n/en.json` and `l10n/nl.json` used to grow at the bottom. Every new key went after the last one. Two branches that each add a string therefore wrote the same last line, and git refused the merge.

That cost was real. Of the last 20 merged pull requests, 14 touched both catalogues. One branch needed five merge cycles to land.

Two changes remove it.

**The catalogues are sorted.** A new key lands next to its alphabetical neighbours instead of at the end. "Add a case" and "Zoom to fit" are thousands of lines apart, so two branches adding different strings no longer edit the same place. `npm run test:l10n` fails if a catalogue drifts out of order, and `npm run test:l10n:write` puts it back.

**What is left merges key by key.** `tools/merge-l10n.js` reads all three versions as sets of keys, not as lines. A key one side added is kept. A key one side deleted is dropped. A key only one side retranslated takes that side's text.

Only a key that both sides translated differently is a conflict. You then get the usual markers, and the file will not parse until you pick one. That is deliberate: a broken catalogue fails `npm run test:l10n` loudly, where a silent choice would drop someone's translation with nothing on screen.

The generated `l10n/en.js` and `l10n/nl.js` follow their source, so the driver reads those too. A clean merge of one is byte-identical to `npm run l10n:build`.

## Why the config line is not in the repository

`.gitattributes` names the driver. It cannot supply the command, because git refuses to run a command a repository ships. Any branch you fetched could otherwise run code on your machine.

So the name lives in the repository and the command lives in your local config. Without the config, git falls back to its ordinary merge and you get the conflicts you had before. Nothing breaks, it is only slower.

## The one merge that still conflicts

A branch you created before this landed has no `.gitattributes`. Git reads merge attributes from your working tree, so the very merge that brings the file in cannot use the driver yet. That merge conflicts once, on the catalogues. Every merge after it is clean.

Do not resolve it with `git checkout --theirs`. That takes development's whole catalogue and drops your own Dutch translations with it. Run the driver by hand instead, on the three versions git already staged:

```bash
git merge origin/development     # conflicts on the catalogues

for f in $(git diff --name-only --diff-filter=U -- l10n); do
  git show ":1:$f" > /tmp/l10n-base || : > /tmp/l10n-base
  git show ":2:$f" > /tmp/l10n-ours
  git show ":3:$f" > /tmp/l10n-theirs
  node tools/merge-l10n.js /tmp/l10n-base /tmp/l10n-ours /tmp/l10n-theirs "$f" \
    && cp /tmp/l10n-ours "$f" && git add "$f"
done

npm run test:l10n                # must exit 0
```

Verified on the real catalogues: both branches' keys survive, the Dutch translations survive, and both files come out sorted.

## Why not merge=union

`union` keeps both sides of every hunk. On a JSON object that leaves two entries with no comma between them, or two closing braces. The catalogue stops parsing, and every string in the app falls back to its raw key. `union` is safe for changelogs, not for structured data.

## Adding a string

Nothing about this changes:

```bash
npm run test:l10n:write   # extracts new keys into en.json, sorts both catalogues
# translate the new keys in l10n/nl.json
npm run l10n:build        # regenerates the browser catalogues
npm run test:l10n         # must exit 0
```

Put your Dutch translation anywhere in `nl.json`. The next `test:l10n:write` sorts it into place.

## Check that it is registered

```bash
git config --get merge.l10n.driver
```

An empty answer means you are still merging line by line. Run the two lines at the top of this page.
