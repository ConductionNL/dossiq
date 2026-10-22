# Proposal: register-version-is-the-highest

## Why

Register fragments merge in filename order and a scalar overwrites, so a schema's `version` is whatever the last
fragment says. `68-case-type-variants.json` moved `caseType` to 1.29.0, and `99-case-type-which-macros.json`, sorting
after it, still said 1.28.0: the merged caseType read 1.28.0, below a version already shipped. A bump that a later
fragment undoes never makes an instance re-import the schema, which is the whole reason the fragments bump it.

## What changes

`RegisterFragmentMerger` keeps the higher of two version numbers (`x.y.z`) for a `version` key; every other scalar is
overwritten as before, and so is a `version` value that is not a version number. A structural test pins that the
shipped caseType carries the highest version any fragment declares.
