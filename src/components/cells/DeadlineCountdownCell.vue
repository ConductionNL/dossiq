<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<!--
	The Deadline column's cell on the Cases index: days left, and days
	overdue once the deadline has passed.

	A CELL WIDGET, not a formatter. A formatter returns a string, and a
	string cannot carry the overdue state — the whole point of the column is
	that a past-due row reads differently from a row with three weeks on it,
	and colour is how a list says that at a glance. `cnCellWidgets` is the
	seam CnCellRenderer offers for exactly this: the column declares
	`widget: "deadlineCountdown"` and receives `{ value, row, property,
	formatted }`.

	The overdue colour is `--color-error-TEXT`, not `--color-error`. On
	Nextcloud 34 `--color-error` is a FILL token (#FFE7E7), so the obvious
	spelling renders pale pink text on white — the same defect
	`tests/vitest/countdownColourTokens.spec.js` pins down for the countdown
	tile. Colour is not the only signal either: the text itself says
	"overdue", so the state survives a reader who cannot separate the two
	colours (WCAG 2.2 SC 1.4.1).

	A case with NO deadline renders an EMPTY cell (the `v-else` span), not
	"0 days left": zero is a claim about a deadline, and a case that has none
	has made no such claim — printing zero would sort it visually beside the
	cases due today. That note lives here and not between the two spans
	because a template comment BETWEEN root elements makes the component
	multi-root, and a multi-root component's `wrapper.classes()` reads the
	fragment rather than the span — which is how the empty-cell unit test
	first failed.

	Spec: openspec/changes/one-case-list/specs/signalering-widgets/spec.md
-->
<template>
	<span
		v-if="countdown"
		class="deadline-countdown"
		:class="{ 'is-overdue': countdown.overdue }"
		:title="title"
		data-testid="deadline-countdown">
		{{ countdown.text }}
	</span>
	<span v-else class="deadline-countdown deadline-countdown--empty" />
</template>

<script>
import { deadlineCountdown } from '../../utils/deadlineCountdown.js'

export default {
	name: 'DeadlineCountdownCell',

	props: {
		/** The row's `deadline` value — a date string, or empty. */
		value: {
			type: [String, Number, Date],
			default: null,
		},

		/** The whole case row (unused here; part of the cell-widget contract). */
		row: {
			type: Object,
			default: () => ({}),
		},

		/** The schema property (unused here; part of the cell-widget contract). */
		property: {
			type: Object,
			default: () => ({}),
		},
	},

	computed: {
		/**
		 * The cell content, or null when the row carries no readable deadline.
		 *
		 * @return {{days: number, overdue: boolean, text: string}|null}
		 */
		countdown() {
			return deadlineCountdown(this.value)
		},

		/**
		 * The raw deadline as the cell's tooltip, so the exact date stays
		 * reachable for anyone who needs it rather than being replaced by the
		 * count.
		 *
		 * @return {string}
		 */
		title() {
			return this.value ? String(this.value) : ''
		},
	},
}
</script>

<style scoped>
.deadline-countdown {
	white-space: nowrap;
}

.deadline-countdown.is-overdue {
	color: var(--color-error-text);
	font-weight: bold;
}
</style>
