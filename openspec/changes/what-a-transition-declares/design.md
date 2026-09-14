# Design: what-a-transition-declares

## D-1. Withholding beats refusing

A transition that is offered and then refused teaches the handler that the
list is unreliable. A transition that is not offered, with the reason
readable in its place, teaches them what to do next. The information is the
same; only one of the two is usable.

## D-2. A dependency is a declaration, not a service

`ConsultationService::getBlockingConsultations` is the right behaviour
written in the wrong place: inside the one thing that happens to need it.
Declaring the dependency on the transition means the second and the third
one cost a line of configuration rather than a class.

## D-3. An obligation has exactly three parts

It is placed, it blocks, and meeting it releases. Writing those three parts
once, and naming what settles the obligation, is what makes advice requests,
fee payments, inspections and external approvals the same thing. A new
obligation then declares what settles it and nothing else.

## D-4. The advice request is the first obligation, not a special case

Rewriting consultations as an obligation is what keeps the count at one
mechanism. Leaving them beside it would leave two, which is the state the
row already describes.

## D-5. Guidance belongs where the choice is made

A description on a status is read when the case is already there. The
sentence a handler needs is at the moment of choosing, which is the
transition. So the transition carries its own text and the status keeps
its own, and both are rendered.

## D-6. Four eyes is a negative rule about an act, not a role

"An approver may not be the author" cannot be expressed as a role, because
the same person legitimately holds both roles on other cases. It is a
statement about who performed a named earlier act on this case. So the
transition names the act, and the engine reads who performed it.

## D-7. The refusal says who, so the handler knows what to do

"You may not do this" sends the handler to a colleague to ask why. "You
prepared this decision on 3 March, so somebody else approves it" sends them
to the right colleague. The case type may name who that is.
