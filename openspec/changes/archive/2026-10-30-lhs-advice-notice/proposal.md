# Proposal: lhs-advice-notice

## Why

DqHandhaving draws an info notice under the LHS matrix: "**Advies: last onder dwangsom**, ... Uitkomst van de
beslistabel ... met gedrag 2 en gevolg C." The page had no such line under the matrix.

## What changes

The matrix widget's content carries a `notice` (nc-vue feat/chart-notice): lead "Advice: {intervention}" and text
"Outcome of the decision table, version {version}, with {behaviour} and {consequence}." from the advice's
recommendedIntervention, matrixVersion, behaviour and severity. The intervention reads through the matrix's own value
labels; behaviour and severity as "gedrag 1-4" and "gevolg A-C". Tone info.

Not said: the board's "begunstigingstermijn 6 weken" and the matrix's name, which the advice record does not hold
(lhsRecommendation has no grace period; the matrix is named on lhsMatrix, which the notice cannot read).
Before feat/chart-notice is released the key is ignored.
