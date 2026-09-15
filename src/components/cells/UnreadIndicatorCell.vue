<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<!--
	The Unread column's cell on the case lists: whether this row changed since
	the reader last looked at it.

	THE COLUMN IS KEYED ON `@self.unread`, WHICH IS NOT A STORED FIELD. It is
	attached per reader on the render path by OpenRegister, memoised per
	request, so a list of four hundred rows costs one query rather than four
	hundred. Nothing sorts on it and nothing filters on it from the column: the
	Unread lens does that, resolved inside the query so the page, the total and
	the facets cannot disagree.

	AN ABSENT FLAG READS AS READ. `@self.unread` is omitted entirely for an
	anonymous read, where there is no "you" to answer for. Treating an absent
	flag as unread would paint every row on a public page, which is the
	silent-widening failure the lens itself guards against on the other side.

	Colour is never the only signal (WCAG 2.2 SC 1.4.1): the dot carries a
	text alternative naming the state, and the cell is empty rather than
	differently coloured for a row that has been read, so the two states differ
	in presence and not only in hue.

	@spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md
-->
<template>
	<span
		class="unread-indicator"
		:data-unread="unread ? 'true' : 'false'"
		data-testid="unread-indicator">
		<span v-if="unread" class="unread-indicator__dot" aria-hidden="true" />
		<span class="unread-indicator__label">{{ label }}</span>
	</span>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { isUnread } from '../../services/readStateApi.js'

export default {
	name: 'UnreadIndicatorCell',

	// Same reason as PriorityBadgeCell: the cell props this component does not
	// read (`value`, `property`, `formatted`) would otherwise fall through onto
	// the root span and render as DOM attributes on every row.
	inheritAttrs: false,

	props: {
		/** The row this cell belongs to, read for its `@self.unread` flag. */
		row: {
			type: Object,
			default: () => ({}),
		},
	},

	computed: {
		/**
		 * Whether this row changed since the reader last saw it.
		 *
		 * @return {boolean} True when the row is unread for this reader.
		 *
		 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md
		 */
		unread() {
			return isUnread(this.row)
		},

		/**
		 * What the cell says.
		 *
		 * The word carries the state on its own, so the dot is decorative and
		 * a reader who cannot separate the two hues loses nothing. A read row
		 * says nothing at all rather than "Read", because four hundred rows
		 * saying Read is noise around the three that matter.
		 *
		 * @return {string} The state in the reader's language.
		 *
		 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md
		 */
		label() {
			return (this.unread ? t('dossiq', 'Unread') : '')
		},
	},
}
</script>

<style scoped>
.unread-indicator {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	white-space: nowrap;
}

.unread-indicator__dot {
	display: inline-block;
	width: 8px;
	height: 8px;
	border-radius: 50%;
	/* The same token every signalering in this app draws in, so a themed
	   instance repoints one variable and this follows. */
	background: var(--color-primary-element, var(--color-primary));
}

.unread-indicator__label {
	font-weight: bold;
}
</style>
