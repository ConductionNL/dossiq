# Design: case-followers

## D-1. A strip, not two header actions

The proposal asked for Follow and Unfollow as header actions gated on the
subscription. They cannot be, and the reason is worth writing down because
the manifest gives no sign of it.

`api-call` is the only declarative action type that writes to an arbitrary
endpoint, and it writes POST or PUT. `executeApiCall` reads
`String(action.method || 'POST').toUpperCase() === 'PUT' ? 'put' : 'post'`,
so a `method: "DELETE"` collapses to a POST. Unfollow is a DELETE on
`.../watch`. Declared as a header action it would POST to a route that
takes DELETE, fail, and leave nothing in the manifest to say it could never
have worked. `CnActionButtons`' `toggle` type writes both directions with
one `method`, which is the same wall from the other side, and a `handler`
action is handed `action.args` verbatim with no token resolved, so it would
run with no case to act on.

That is three walls and the star hit all three first
(`case-number-and-favourites`, `CaseFavouriteStrip.vue`). So this ships the
same way: a registry widget, mounted directly after the star inside
`CaseBannerStack.vue`, which is the ONE grid row the case page's strips
share. A row of its own was the first shape and it was wrong for the reason
that container records — a strip that renders conditionally still reserves
its row, so the page showed a gap where it was. Both go the day the library
takes a DELETE verb and a two-verb toggle, which is where this belongs for
every app in the fleet. That is the one platform ask this change leaves
behind.

The state is still declared rather than fetched. `@self.watching` rides
every object read, so the strip paints from what the page already holds and
makes no call until somebody presses it. The lens is `_watching`, resolved
inside the query the way `_unread` and `_favourite` are.

## D-2. Followers are listed, not managed

The People tab section lists the subscriptions on the case with the
follower's name. Removing another person's subscription is not offered:
that needs `manage` in OpenRegister, and a button that 403s on press
teaches nobody anything. You stop following from the strip on the case
page, which acts on your own row only.

Reading the list at all needs `update`, so a reader without it is refused
with 403. The panel draws that apart from an empty list, in words. Drawn
the same way, "nobody follows this case" would be a claim about the
audience that this reader was never told, and the two outcomes render
identically unless something keeps them apart.

The count beside the Follow button follows the same rule.
`@self.watcherCount` is attached only for a reader who may update the case,
so an absent count is silent rather than "0 followers".

## D-3. What a follower actually hears, and when

A Follow button is decoration unless the case addresses its watchers, so
the case schema gains one rule, `caseMovedForItsFollowers`, whose
recipients are `[{"watchers": true}]`.

**The trigger is `transition`, not `updated`.** `ObjectTransitionedEvent`
fires when the status engine moves the case. `updated` fires on every save,
and a follower who hears about every field edit stops reading the ones that
matter. The spec's scenario is about a status change, and `transition` is
the event that is one.

**The recipients are the watchers alone.** The handler and the team already
hear through their own rules. A second rule naming them would arrive as a
second notification about one move.

**The subject names no status.** A case status reaches the template as the
stored value rather than the label an administrator gave it, so
`{{status}}` can render a uuid. A notification reading "moved to 7f3a-…" is
worse than one that says to go and look.

The escalation rule `caseDeclaredMajor` gains `{"watchers": true}` beside
its existing recipients, because an escalation is exactly what somebody
following a sensitive case is following it for. The two `created` rules do
not: nobody can be following a case at the moment it is created, so a
watchers block there addresses nobody by construction and would read as
coverage this change does not have.

The recipient block is spelled `{"watchers": true}` with no `kind`, because
it names a subscription list rather than a value to look up.
`NotificationAnnotationValidator` refuses anything but boolean true, which
is how it stops `"yes"` from quietly addressing nobody.
