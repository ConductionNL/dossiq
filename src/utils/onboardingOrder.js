// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The order of the two first-open overlays: the welcome tour first, the
 * support note on a later load.
 *
 * A new user qualifies for both. nextcloud-vue's CnAppRoot shows the support
 * note first and holds the tour until it is closed, so a new user met
 * "Steun Dossiq" before they had seen the app at all, and the tour only on the
 * next load (finding N-7). The tour is onboarding, so dossiq turns the support
 * note off on every load where the tour is not yet behind the user. The page
 * render says which (`walkthrough_seen`, WalkthroughSeenInitialStateListener.php).
 * Once the tour is finished or skipped, the next load shows the note the way
 * the library always did, once.
 *
 * @param {unknown} walkthroughSeen The `walkthrough_seen` initial state.
 * @return {boolean} The CnAppRoot `supportDialog` prop: false holds the note back.
 *
 * @spec openspec/changes/case-page-dutch-labels-one-history-tour-first/specs/first-load-onboarding/spec.md
 */
export function supportDialogForThisLoad(walkthroughSeen) {
	// Only a definite "not seen" holds the note back. A page served without
	// the state (an older server) keeps the library's default.
	return walkthroughSeen !== false
}
