# Tasks: portal-contact-channel-follows-the-resident

Tier: V1. Kind: code. Half: portaliq `identity-profile-page`.

## 1. The property

- [x] 1.1 (Ruben 10 Oct, decision 171, Q-dossiq-L2-3 option 2, against the recommendation: the slug enum AND the ZGW mapping. Built: `case.communicationChannel` is a string with the enum email, portal, post, website, zgw-api in `dossiq_register.json` (case 1.41.0), next to `communicationChannelSource`, the value it arrived as. `lib/Service/CommunicationChannel.php` maps a ZGW URL to the slug an administrator mapped it to (`zgw_communication_channel_map`), else `zgw-api`, and intake words to their slug; `ZgwService::applyInboundMapping()/applyOutboundMapping()` run it for a zaak, so no ZGW-created case is refused and the zaak answers with the URL it was given; `ChannelIntake` normalises a message's channel. `lib/Repair/NormaliseCommunicationChannelValues.php` (registered post-migration after InitializeSettings) converts stored values first-run and keeps what it replaced; it replaces the never-registered `ReportCommunicationChannelValues`. Tests: `CommunicationChannelTest`, `ZgwCommunicationChannelBoundaryTest`, `ChannelIntakeCommunicationChannelTest`, `NormaliseCommunicationChannelValuesTest`.) Re-declare `case.communicationChannel` as a slug enum, and a repair step for the stored values outside it (design D3).
  - unit: a schema test asserts the enum; the repair step converts every value off the slugs and keeps what it held

## 2. The listener

- [x] 2.1 (`lib/Listener/PortalContactDetailsChangedListener.php`, registered by name in `CrossAppListenerRegistrar`; `PortalContactDetailsChangedListenerTest`, `CrossAppListenerRegistrarTest::testTheContactChannelFactIsBound`) `PortalContactDetailsChangedListener` and its guarded registration in `CrossAppListenerRegistrar` (design D1, D2).
  - unit: with a stub event class, running cases of the subject get the mapped channel and a timeline entry, ended cases do not, `phone` leaves the channel and adds the entry, and a failing write is logged, not thrown

## 3. Live check

- [ ] 3.1 (live pass, decision 139; `git grep "new PortalContactDetailsChangedEvent"` in portaliq origin/development finds the emitter in `PortalContactAddressService.php`, 10 Oct) Before closing: `git grep "new PortalContactDetailsChangedEvent"` in portaliq finds the emitter; then a resident sets "By post" in the portal and the acknowledgement of their running case goes by post.

## 4. Validation

- [ ] 4.1 `openspec validate portal-contact-channel-follows-the-resident --strict`, `npm run lint`, `composer check:strict` once before push.
