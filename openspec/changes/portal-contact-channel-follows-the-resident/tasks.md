# Tasks: portal-contact-channel-follows-the-resident

Tier: V1. Kind: code. Half: portaliq `identity-profile-page`.

## 1. The property

- [x] 1.1 (Q-dossiq-L2-3 option 1 meanwhile: `format: uri` dropped in `dossiq_register.json` (a fragment cannot remove a key), no enum because the ZGW Zaken API writes a channel URL there; case 1.40.0. `ReportCommunicationChannelValues` names every case off the slugs and writes nothing; `ReportCommunicationChannelValuesTest`. The enum waits on Q-dossiq-L2-3) Re-declare `case.communicationChannel` as a slug enum in `lib/Settings/register.d/78-communication-channel-slug.json`, and a repair step that lists stored values outside it (design D3).
  - unit: a schema test asserts the enum; the repair step reports an unknown value and changes nothing

## 2. The listener

- [x] 2.1 (`lib/Listener/PortalContactDetailsChangedListener.php`, registered by name in `CrossAppListenerRegistrar`; `PortalContactDetailsChangedListenerTest`, `CrossAppListenerRegistrarTest::testTheContactChannelFactIsBound`) `PortalContactDetailsChangedListener` and its guarded registration in `CrossAppListenerRegistrar` (design D1, D2).
  - unit: with a stub event class, running cases of the subject get the mapped channel and a timeline entry, ended cases do not, `phone` leaves the channel and adds the entry, and a failing write is logged, not thrown

## 3. Live check

- [ ] 3.1 (live pass, decision 139; `git grep "new PortalContactDetailsChangedEvent"` in portaliq origin/development finds the emitter in `PortalContactAddressService.php`, 10 Oct) Before closing: `git grep "new PortalContactDetailsChangedEvent"` in portaliq finds the emitter; then a resident sets "By post" in the portal and the acknowledgement of their running case goes by post.

## 4. Validation

- [ ] 4.1 `openspec validate portal-contact-channel-follows-the-resident --strict`, `npm run lint`, `composer check:strict` once before push.
