/**
 * The pure decisions the personal queue makes on the client.
 *
 * Kept out of the views so each one is checkable on its own. One of them is
 * load-bearing enough to say out loud: `canDismiss` is a function that always
 * returns false. It exists so the refusal is a fact somebody can read and test
 * rather than the absence of a button somebody might add back.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
 */

/** What the reader is offered when they try to remove live work. */
export const OFFER_HIDE_GROUP = 'hide-group-for-today'

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

