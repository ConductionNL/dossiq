/**
 * The pure decisions the personal queue makes on the client.
 *
 * Kept out of the views so each one is checkable on its own. Two of them are
 * load-bearing enough to say out loud:
 *
 * - `canDismiss` is a function that always returns false. It exists so the
 *   refusal is a fact somebody can read and test rather than the absence of a
 *   button somebody might add back.
 * - `touchedToday` is the end-of-day filter, and it reads the register's own
 *   per-reader read state. dossiq keeps no second record of who saw what, per
 *   `unread-state-on-the-case`.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
 */

/** What the reader is offered when they try to remove live work. */
export const OFFER_HIDE_GROUP = 'hide-group-for-today'

/** The integration leaf that records time, owned by humaniq. */
export const HOURS_LEAF_ID = 'humaniq-hours'

/**
 * Whether a person may make this item disappear.
 *
 * Never. An item leaves when the thing it points at is done, taken over or
 * withdrawn, and nothing a reader presses is any of those.
 *
 * @return {boolean} Always false.
 *
 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
 */
export function canDismiss() {
	return false
}

/**
 * What to offer instead of removing an item.
 *
 * @param {object} item The queue item.
 * @return {{offer: string, group: string}} The offer and the group it applies to.
 *
 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
 */
export function offerInsteadOfDismissing(item) {
	return { offer: OFFER_HIDE_GROUP, group: String(item?.source ?? '') }
}

/**
 * The groups still on screen.
 *
 * @param {Array} groups The groups the server sent.
 * @param {Array} hiddenGroups The group keys the reader hid for today.
 * @return {Array} The groups to render.
 *
 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
 */
export function visibleGroups(groups, hiddenGroups) {
	const hidden = new Set((hiddenGroups ?? []).map(String))

	return (groups ?? []).filter((group) => !hidden.has(String(group?.key ?? '')))
}

/**
 * Whether humaniq is on this instance.
 *
 * `OC.appswebroots` lists the web root of every installed app, so an app that
 * is absent is absent from it. Read through optional chaining because a
 * component under test has no Nextcloud shell around it.
 *
 * @return {boolean} TRUE when humaniq is installed.
 *
 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
 */
export function humaniqIsPresent() {
	const roots = globalThis.OC?.appswebroots ?? {}

	return Object.hasOwn(roots, 'humaniq')
}

/**
 * The items the reader touched today.
 *
 * `lastSeenAt` is the register's per-reader read state: the moment THIS reader
 * last opened the object. An item the register has no read state for is not
 * listed, because "everybody changed it today" is a different question from
 * "you worked on it today", and answering the first while claiming the second
 * is how an end-of-day screen becomes a list nobody recognises.
 *
 * @param {Array} items The queue candidates.
 * @param {object} readStates Item id to its read state, as `{ lastSeenAt }`.
 * @param {string} today Today, as `YYYY-MM-DD`.
 * @return {Array} The items seen today.
 *
 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
 */
export function touchedToday(items, readStates, today) {
	return (items ?? []).filter((item) => {
		const seen = String(readStates?.[item?.id]?.lastSeenAt ?? '')

		return seen.slice(0, 10) === today
	})
}

/**
 * Today, in the format the read state answers in.
 *
 * @param {Date} now The moment to read from.
 * @return {string} Today as `YYYY-MM-DD`.
 *
 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
 */
export function todayOf(now = new Date()) {
	const month = String(now.getMonth() + 1).padStart(2, '0')
	const day = String(now.getDate()).padStart(2, '0')

	return `${now.getFullYear()}-${month}-${day}`
}
