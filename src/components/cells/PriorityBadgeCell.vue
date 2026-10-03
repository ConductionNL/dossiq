<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<!--
	The Priority column's cell on the case lists: the priority, drawn in the
	hue the schema declares for it.

	THE COLUMN IS KEYED ON `priorityOrder`, NOT ON `priority`, and that is the
	whole reason this cell exists rather than a formatter. Sorting happens on
	the server, on the stored value of the column's key, and the stored value
	of `priority` is a WORD: sorting by it gives high, low, normal, urgent,
	which is neither the order a handler means nor a usable one. `priorityOrder`
	is the declared order as an integer, so the server sorts it correctly, and
	this cell reads the word back off the row so the reader still sees a
	priority rather than a number.

	NO COLOUR IS DEFINED HERE. The hue NAME comes from `priorityValues.js`,
	which mirrors the `x-enum-colours` declaration on the schema, and the name
	is resolved to a CSS token by `statusColour.js` — the same resolver every
	status badge and board column header in this app already uses. A themed
	install repoints one `--nl-color-*` token and this follows.

	Colour is never the only signal: the badge always carries the priority as
	text, so a reader who cannot separate two hues loses nothing
	(WCAG 2.2 SC 1.4.1).

	@spec openspec/changes/case-priority-impact-urgency/specs/case-priority/spec.md
-->
<template>
	<span
		class="priority-badge"
		:style="style"
		:data-colour="colour"
		:data-priority="priority"
		:data-overridden="overridden ? 'true' : 'false'"
		:title="hint"
		data-testid="priority-badge">
		{{ label }}
	</span>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { priorityColour, priorityLabel } from '../../utils/priorityValues.js'
import { statusColourStyle } from '../../utils/statusColour.js'

export default {
	name: 'PriorityBadgeCell',

	// Same reason as StatusBadgeCell: the cell props this component does not
	// read (`value`, `property`, `formatted`) would otherwise fall through onto
	// the root span and render as DOM attributes on every row. `value` is the
	// declared order as an integer, which the server sorts on and no reader
	// wants to see, so this cell takes the word off the row instead.
	inheritAttrs: false,

	props: {
		/** The row this cell belongs to, read for its `priority`. */
		row: {
			type: Object,
			default: () => ({}),
		},
	},

	computed: {
		/**
		 * The priority this row carries.
		 *
		 * @return {string} One of the declared values, or the empty string.
		 *
		 * @spec openspec/changes/case-priority-impact-urgency/specs/case-priority/spec.md
		 */
		priority() {
			return String(this.row?.priority ?? '')
		},

		/**
		 * Whether a person overrode this row's priority by hand.
		 *
		 * @return {boolean} True when an override stands on the row.
		 *
		 * @spec openspec/changes/case-priority-impact-urgency/specs/case-priority/spec.md
		 */
		overridden() {
			return Boolean(this.row?.priorityOverride)
		},

		/**
		 * The hue name the badge is drawn in.
		 *
		 * @return {string} A name from the NL Design System palette.
		 *
		 * @spec openspec/changes/case-priority-impact-urgency/specs/case-priority/spec.md
		 */
		colour() {
			return priorityColour(this.priority)
		},

		/**
		 * The badge's inline colours.
		 *
		 * @return {object} A style object.
		 *
		 * @spec openspec/changes/case-priority-impact-urgency/specs/case-priority/spec.md
		 */
		style() {
			return statusColourStyle(this.colour)
		},

		/**
		 * What the badge says.
		 *
		 * @return {string} The priority in the reader's language, or the empty
		 *   string for a row that carries none.
		 *
		 * @spec openspec/changes/case-priority-impact-urgency/specs/case-priority/spec.md
		 */
		label() {
			if (!this.priority) return ''
			return priorityLabel(this.priority)
		},

		/**
		 * What the badge says on hover, and to a screen reader.
		 *
		 * A handler reading a queue needs to know which rows the matrix put
		 * where they are and which ones a person did, and the column is too
		 * narrow to say so in text. The case page says who and why; this only
		 * says that somebody did.
		 *
		 * @return {string} The hint, or the empty string.
		 *
		 * @spec openspec/changes/case-priority-impact-urgency/specs/case-priority/spec.md
		 */
		hint() {
			if (!this.priority) return ''
			if (!this.overridden) return ''
			return t('dossiq', 'Priority set by hand, not derived')
		},
	},
}
</script>

<style scoped>
.priority-badge {
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
