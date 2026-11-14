# Tasks: requester-identified-at-intake

- [x] Case schema 1.59.0: `intakeIdentification`, `intakeSourceName` (register.d fragment)
- [x] The portal receiver passes the assertion's audience; the intake maps it to the means (`IDENTIFICATION_BY_AUDIENCE`) and reads the portal name (`SettingsService::getPortalName()`)
- [x] The intake writes both on a portal request only
- [x] Aanvrager card: subtitle `firstOf`, foot link by requester kind, no channel row (nextcloud-vue `firstOf` and `nameField`/`nameMap`)
- [x] Case header meta "via {intakeSourceName}"
- [x] Dutch catalogue entries
- [x] Unit tests
