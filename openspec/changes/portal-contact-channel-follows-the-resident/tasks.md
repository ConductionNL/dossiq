# Tasks: portal-contact-channel-follows-the-resident

Tier: V1. Kind: code. Half: portaliq `identity-profile-page`.

## 1. The property

- [ ] 1.1 Re-declare `case.communicationChannel` as a slug enum in `lib/Settings/register.d/78-communication-channel-slug.json`, and a repair step that lists stored values outside it (design D3).
  - unit: a schema test asserts the enum; the repair step reports an unknown value and changes nothing

## 2. The listener

- [ ] 2.1 `PortalContactDetailsChangedListener` and its guarded registration in `CrossAppListenerRegistrar` (design D1, D2).
  - unit: with a stub event class, running cases of the subject get the mapped channel and a timeline entry, ended cases do not, `phone` leaves the channel and adds the entry, and a failing write is logged, not thrown

## 3. Live check

- [ ] 3.1 Before closing: `git grep "new PortalContactDetailsChangedEvent"` in portaliq finds the emitter; then a resident sets "By post" in the portal and the acknowledgement of their running case goes by post.

## 4. Validation

- [ ] 4.1 `openspec validate portal-contact-channel-follows-the-resident --strict`, `npm run lint`, `composer check:strict` once before push.
