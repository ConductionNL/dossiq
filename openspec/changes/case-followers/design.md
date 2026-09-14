# Design: case-followers

## D-1. Declared over the subscription object

Follow is `open-form`-less: a handler that creates the subscription for
`@objectId` and `@me`; Unfollow deletes it. `visibleIf` on whether one
exists. The lens filters cases by "subscribed by @me" through the query
`object-watchers` defines. The tile is an object count over the same
query.

## D-2. Followers are listed, not managed

The People tab section lists the subscriptions on the case with the
follower's name. Removing another person's subscription is not offered.
