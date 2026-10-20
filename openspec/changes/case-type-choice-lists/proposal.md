# case-type-choice-lists

## Why

Board dossiq/DqZaaktypeVelden has a "Keuzelijst" card beside Fields: a field's choices, one per row, with an Add. The
forms work (nextcloud-vue batch 18a) gave forms a `lines` widget for `string[]`, which the Fields editor already uses for
a field's `enumValues`.

## What changes

- A "Choice lists" side card beside Fields lists the case type's fields that hold a choice list (`enumValues` set).
  Picking one opens its choices in the `lines` editor, one per line, and saves them on the field. The card adds no
  field; that stays in the Fields list.
- Dutch strings.

## Differs from the board

The board shows one field's choices inline as rows with a drag handle and an "Optie toevoegen" button. The page has no
selected field to bind such a card to, so the card lists the choice-list fields and edits each one's choices in its
form.
