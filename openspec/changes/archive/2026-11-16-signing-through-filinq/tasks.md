# Tasks: signing-through-filinq

- [x] Open dossiq PRs checked for files under `Beschikking/` and `BeschikkingService` (none open and not a draft, the release PR aside), so the R5 freeze no longer applies.
- [x] `Service/Signing/DocumentSigningRequester` raises filinq's request event by class name.
- [x] `BeschikkingService::onderteken` asks and waits visibly; `Service/Signing/SigningConclusionRecorder` records filinq's outcome.
- [x] `Listener/SigningConcludedListener`, registered by name in `CrossAppListenerRegistrar`.
- [x] The audit packet says no validation report is available.
- [x] LibreSign adapter, mock adapter, interface and binding deleted after `git grep` found no other caller.
- [x] `beschikking` schema 1.2.0 with `signing`, l10n, digests, app version moved.
