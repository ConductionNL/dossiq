# Design: fees-and-payments-on-the-case

## D-1. The fee is a declaration with a citation, and it varies by channel

xxllnc puts the tarieven on the case type beside the web form
(`_round4/discovery/candidates.json`, C-intake-44). A Dutch
legesverordening sets a different amount for a digital and a paper
aanvraag, so one amount per case type would be wrong for one of the two
every time.

So the declaration is a list: the intake channel, the amount, and the
article it comes from. The article matters because a citizen may ask why
they are being charged, and "because the configuration says so" is not an
answer a gemeente may give.

A case type declaring a fee with no article warns on publication, the same
way `decision-outcomes-on-the-case` warns on a missing remedy clause.

## D-2. dossiq holds a state, never an amount received

The money is shillinq's. dossiq holding "paid" and shillinq holding
"€162,50 received on the 4th" is one fact in two places, and the day they
disagree the citizen is right and both systems are wrong.

So the case carries a payment state read from shillinq: not required,
outstanding, paid, or waived. dossiq stores it as a projection for
listing and filtering, and never as the source. A projection that cannot
be refreshed reads as stale and says so, rather than showing a paid case
that is not.

## D-3. The manual override is a recorded act by a named role

xxllnc lets the state be set by hand when the money arrived another way
(C-intake-7). A gemeente receives a cash payment at the balie and a
transfer that never matched.

So it is an act, not a field: a named role performs it, records who, when
and why, and the record travels with the case. What it must not be is a
dropdown any handler can change, because that turns a financial fact into
a guess.

## D-4. Whether an unpaid case proceeds is the case type's decision

Some cases must be paid before they are handled and some must not. A
melding openbare ruimte that waited for a payment is a pothole nobody
fixed.

So the case type declares it, and where it declares that payment is
required first, the guard refuses the dependent acts with the rule named.
Per ADR-102, a case type requiring payment whose state dossiq cannot read
refuses rather than allows: letting an unpaid case through because
shillinq was briefly unreachable is the fail-open shape
`refusals-carry-a-status` exists to end.

## D-5. The contract is a reference, and both directions are readable

GLPI links a ticket to a contract and holds the contract's own costs
(C-parties-and-contacts-1). The contract is shillinq's record.

dossiq's half is a reference on the case and a way for shillinq to list
the cases raised under a contract. Nothing about the contract's term, its
costs or its renewal is copied into dossiq, because a copied term is a
term that goes stale and then alerts on the wrong date.
