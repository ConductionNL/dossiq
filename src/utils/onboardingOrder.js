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
 * An administrator can switch the note off for everyone with the
 * `show_support_note` app config (`support_note_shown`,
 * SupportNoteInitialStateListener.php).
 *
 * @param {unknown} walkthroughSeen The `walkthrough_seen` initial state.
 * @param {unknown} [supportNoteShown] The `support_note_shown` initial state.
 * @return {boolean} The CnAppRoot `supportDialog` prop: false holds the note back.
 *
 * @spec openspec/specs/first-load-onboarding/spec.md
 * @spec openspec/changes/the-support-note-can-be-switched-off/specs/first-load-onboarding/spec.md#requirement-an-administrator-can-switch-the-support-note-off-req-flo-002
 */
export function supportDialogForThisLoad(walkthroughSeen, supportNoteShown) {
	// Only a definite "not seen" or a definite "off" holds the note back. A
	// page served without the state (an older server) keeps the library's
	// default.
	return walkthroughSeen !== false && supportNoteShown !== false
}
