# Design: the-social-domain-plan-and-its-grounds

## D-1. An intervention is the unit, not the goal

A goal without an intervention is a wish, and an intervention without a goal
is activity. Modelling the intervention and letting it name the goal it
serves keeps both, and it means several interventions can serve one goal,
which is what a real plan looks like.

## D-2. A provider is a party, not a string

The provider is an organisation the municipality already knows, often with a
contract and a contact. A string cannot be phoned, cannot be reported on and
cannot be corrected once. So the provider is a party in the platform's
contact model.

## D-3. A goal says what would count as met

"Improve the home situation" cannot be closed by anybody. Naming the
observable thing that would count means a review has something to decide,
and it means the plan can be evaluated by the household as well as by us.

## D-4. The strings are migrated, not dropped

`goals` and `deploymentTrajectories` hold text somebody wrote about a real
household. The migration carries each string into a goal or an intervention
with its text intact, unmapped fields empty and a mark that it came from the
old shape, so nothing is invented and nothing is lost.

## D-5. A plan that is not reviewed is the failure case

The plan that goes stale is exactly the plan that harms. A review date and a
record of what changed at each review make staleness visible, and cost one
field each.

## D-6. Existence is the smallest answer that helps

Yes, a case exists; it is Wmo; here is the contact. That is enough to pick
up the phone and not enough to learn anything about the household. Every
field beyond those three is a field somebody has to justify, so none of them
is there.

## D-7. The ground is chosen before the answer, not recorded after it

A ground recorded afterwards is a formality somebody fills in. Choosing it
first makes the lookup deliberate, and it is what makes the log meaningful
when the person asks what was looked up about them.

## D-8. The log is the deliverable, not the side effect

`authorisationGround` is declared and unread today, which is the failure
this row names. The view is specified so that it cannot answer without
writing the log: the log is part of the act, not something a listener
notices afterwards.
