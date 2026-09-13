# Design: attribute-catalogue-folders

## D-1. Same shape as the case types folders

`folderSidebar: {source: field, field: category, allLabel: All attributes}`
on the property definitions index, the shape `#CaseTypes` already carries.
A row without a category lands under Uncategorised.

## D-2. The picker groups, it does not filter

On `#CaseTypeDetail` the property picker shows attributes grouped by
category with the group name as a heading, so an author sees the whole
catalogue while adding to a type.

## D-3. Templates stay filinq's

A template category is filinq's to declare. The picker reads it when the
library answers one; dossiq stores nothing about it.
