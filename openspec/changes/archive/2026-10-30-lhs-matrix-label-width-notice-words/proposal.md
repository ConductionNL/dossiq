# Proposal: lhs-matrix-label-width-notice-words

## Why

r29 on :8080 (DqHandhaving): the matrix's row labels took a 148px column where the board draws 170px, so every cell was
5.5px wider and every centred cell text sat 2.8px further in (titleInsetLeft +2.8 on 16 cells, firstRowInsetLeft 203 vs
180.9). The advice notice read "Advies: Last onder dwangsom" with the cell's capital, where the board writes "Advies:
last onder dwangsom" mid-sentence, so compdiff did not pair it.

## What changes

- lhs-matrix `matrixLabelWidth: 170`.
- The notice puts the intervention in words of its own, lower case mid-sentence ("last onder dwangsom", "waarschuwing",
  "proces-verbaal en bestuursdwang"), instead of the matrix cells' labels.
