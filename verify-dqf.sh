#!/bin/bash
# usage: verify-dqf.sh <branch>; logs to verify-<branch>.log in the clone
cd ~/lanes/fix-dqf || exit 2
b=$1; log=verify-${b//\//_}.log
git checkout -q "$b" || exit 2
git merge -q --no-edit origin/development || { echo "MERGE CONFLICT"; exit 3; }
{
echo "== $b @ $(git rev-parse --short HEAD)"
flock ~/lanes/heavy.lock npx vitest run --maxWorkers=2 > vitest-${b//\//_}.log 2>&1; echo "vitest exit $?"; grep -E "Test Files|Tests " vitest-${b//\//_}.log
npm run lint >/dev/null 2>&1; echo "lint exit $?"
npm run format >/dev/null 2>&1; echo "format exit $?"
npm run check:manifest >/dev/null 2>&1; echo "check:manifest exit $?"
npm run test:l10n >/dev/null 2>&1; echo "test:l10n exit $?"
npm run check:l10n-js >/dev/null 2>&1; echo "check:l10n-js exit $?"
npm run check:schema-l10n >/dev/null 2>&1; echo "check:schema-l10n exit $?"
npm run check:debt-ratchet > debt-${b//\//_}.log 2>&1; echo "check:debt-ratchet exit $?"
for c in $(git diff --name-only origin/development...HEAD -- openspec/changes | cut -d/ -f3 | sort -u); do openspec validate "$c" --strict >/dev/null 2>&1; echo "openspec $c exit $?"; done
} > "$log" 2>&1
cat "$log"
