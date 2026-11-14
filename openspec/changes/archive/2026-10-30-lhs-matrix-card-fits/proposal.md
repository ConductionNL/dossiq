# Proposal: lhs-matrix-card-fits

## Why

On :8080 the LHS matrix card on an enforcement advice (DqHandhaving) was 536px high with the matrix centred in it, so
about 110px of empty card stood between the subtitle and the matrix. The board's card is 411px and starts the matrix
under the subtitle. The card's height came from a fixed gridHeight 7.

## What changes

The matrix's layout item takes `sizeToContent`, so the card is as high as the matrix with its title and the
grid closes up behind it. gridHeight stays as the advisory height.
