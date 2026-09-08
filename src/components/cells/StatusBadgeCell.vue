<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<!--
	The Status column's cell on the Cases index: the status name, drawn in
	the colour its status type carries.

	A CELL WIDGET, not a formatter, for the reason `cellWidgets.js` gives: a
	formatter returns a string, and a string cannot carry a colour. The
	`statusTypeName` formatter this replaces resolved the reference to its
	name and stopped there, so twenty rows of five different statuses read as
	twenty rows of the same grey text.

	`CnStatusBadge` was the obvious component and is the wrong one: it takes
	one of six fixed semantic variants (success, error, …), and a status
	colour is one of twelve NL Design System hue NAMES that a case type's
	author picks. Mapping twelve hues onto six semantics would let two
	statuses an author deliberately coloured differently render identically.

	The colour is read from the statusType collection in the object store,
	the same reactive collection `statusTypeName` reads its label from, so
	the cell re-renders in colour once the collection lands rather than
	fetching once per row.

	Colour is never the only signal: the badge always carries the status NAME
	as text, so a reader who cannot separate two hues loses nothing
	(WCAG 2.2 SC 1.4.1).

	@spec openspec/specs/case-types/spec.md
-->
<template>
	<span
		class="status-badge"
		:style="style"
		:data-colour="colour"
		data-testid="status-badge">
		{{ label }}
	</span>
</template>

<script>
import { useObjectStore } from '../../store/modules/object.js'
import {
	normaliseStatusColour,
	statusColourStyle,
} from '../../utils/statusColour.js'

export default {
	name: 'StatusBadgeCell',

	// Same reason as DeadlineCountdownCell: the undeclared cell props
	// (`row`, `property`, `formatted`) would otherwise fall through onto the
	// root span and render as DOM attributes on every row.
	inheritAttrs: false,

	props: {
		/** The case's `status` value: a statusType UUID. */
		value: {
			type: [String, Number],
			default: '',
		},

		/** The label CnCellRenderer already resolved, when it did. */
		formatted: {
			type: String,
			default: '',
		},
	},

	computed: {
		/**
		 * The status type this cell's value refers to.
		 *
		 * @return {object|null} The row, or null while it is unresolved.
		 */
		statusType() {
			const uuid = String(this.value ?? '')
			if (!uuid) return null
			let store
			try {
				store = useObjectStore()
			} catch {
				return null
			}
			const collection = store.collections.statusType || []
			return (
				collection.find(
					(row) =>
						row.id === uuid
						|| (row['@self'] && row['@self'].id === uuid),
				) || null
			)
		},

		/**
		 * The colour name the badge is drawn in.
		 *
		 * @return {string} A name from the palette; grey while unresolved.
		 */
		colour() {
			return normaliseStatusColour(this.statusType?.colour)
		},

		/**
		 * The badge's inline colours.
		 *
		 * @return {object} A style object.
		 */
		style() {
			return statusColourStyle(this.colour)
		},

		/**
		 * What the badge says.
		 *
		 * @return {string} The status name, the pre-formatted label, or the
		 *   raw value while the collection is still loading.
		 */
		label() {
			return (
				this.statusType?.name || this.formatted || String(this.value ?? '')
			)
		},
	},
}
</script>

<style scoped>
.status-badge {
	display: inline-block;
	max-width: 100%;
	overflow: hidden;
	padding: 2px 10px;
	border-radius: var(--border-radius-pill, 100px);
	font-size: 0.9em;
	line-height: 1.4;
	white-space: nowrap;
	text-overflow: ellipsis;
}
</style>
