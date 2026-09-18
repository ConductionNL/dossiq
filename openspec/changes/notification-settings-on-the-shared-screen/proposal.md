---
kind: code
depends_on: [unread-state-on-the-case]
---

# Proposal: notification-settings-on-the-shared-screen

## Why

dossiq keeps its own notification settings screen,
`src/views/settings/NotificationRoutingSettings.vue`, beside
`CnNotificationPreferences` in the shared library. Both answer the same
question, which notices reach you and which layer decided that, and the shared
one answers it with a layer more.

dossiq's own list can say three layers: the shipped default, a team default,
and your own. It has nowhere to put a channel an administrator has FORCED, and
nowhere to put one the platform REFUSES for this recipient. So a handler can
switch a notice off, keep receiving it, and read a page that says "you set
this" while a forced row is what actually decided. That is the exact failure
the shared screen was built to prevent, and it is worse here than a plain
missing feature: the page is confidently wrong rather than silent.

A refusal has the same shape. An internal notice addressed to somebody outside
the organisation comes back refused with a reason. dossiq's list has no way to
show that, so the switch reads as available and the notice never arrives.

## What changes

- `NotificationRoutingSettings.vue` renders `CnNotificationPreferences`
  instead of its own list of switches. Four layers, forced rows with who and
  why, and refusals with their rule.
- A new pure module, `src/services/notificationPreferenceProps.js`, maps the
  platform's answer onto the shared screen's props. dossiq's labels, its
  schema grouping, and its domain scope stay dossiq's; the rendering and the
  precedence become the library's.
- The domain selector stays. Pinning a preference to one part of your work is
  dossiq's own idea and the shared screen takes a scope per row.
- The team default block stays. Setting one is an act of administration the
  platform checks and refuses with a 403, which is shown rather than
  second-guessed.

## What is lost, named rather than hidden

The per-row "use the setting from my team" button goes. `CnNotificationPreferences`
emits true or false and has no third state, so there is no clear control to
hang it on. The store behind the shared screen already accepts a null to clear,
so the control belongs in the component; `clearPreference()` in
`src/services/notificationRoutingApi.js` is kept and is waiting for it.

Until then a handler who wants to go back to their team's setting switches the
row to what the team set, and the row names the layer. That stores an override
with the same value rather than clearing, so a later change to the team default
will not follow them. That is a real regression and it is why this is written
down rather than left for somebody to notice.

## It needs a library release, and the reason matters

`CnNotificationPreferences` is not new. The library has shipped a component of
that name since nextcloud-vue #446: it took NO props, fetched
`/apps/openregister/api/notification-preferences` for itself, and rendered its
own switches. nextcloud-vue #1217 REPLACED it in place with the props-driven
screen this change consumes.

Verified rather than assumed, by unpacking the published tarball:
`@conduction/nextcloud-vue@3.2.0`, which is what dossiq's `package.json` asks
for, still carries the OLD self-fetching component. So on the published version
this screen would hand props to a component that ignores them and fetches for
itself, and a handler would see neither the forced rows nor the refusals — the
two things this change exists to show.

This change is therefore correct and NOT deployable until a nextcloud-vue
release carries the new component, and dossiq's dependency is raised to it.
That is the one thing to check before merging.

Two consequences beyond dossiq, worth raising rather than leaving to be found:
a consumer that mounts the old component with no props now renders an empty
table, and a self-fetching component becoming a props-driven one with the same
name is a breaking change that no release note has declared yet.

## What is not decided here

The channel axis. dossiq's preferences are one switch per notification today,
not per notification and channel. The mapping renders the platform's channels
when it answers with them and ONE column named "Notifications" when it does
not. Fabricating a mail and a push column would tell a handler they can choose
between them when the platform stores no such choice, and their click would be
collapsed onto the single value that exists.
