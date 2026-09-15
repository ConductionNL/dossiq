<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<!--
	The Days in status column's cell on the Cases index.

	A CELL WIDGET, not a formatter, for the reason DeadlineCountdownCell is
	one: a formatter returns a string and a string cannot carry the breached
	state. Being stuck is the only thing this column is for — the plain number
	is the sort key, and the chip is what makes the row worth looking at.

	IT IS NOT THE DEADLINE, and the colour says so. A case can be nine weeks in
	one status with eight weeks left on its term, which is exactly the case
	this column exists to find, so the breached state is drawn in the warning
	hue rather than in the signalering red the Deadline column owns. Two
	different reds for two different facts is how a handler stops telling them
	apart.

	The text carries the state too: the chip reads "stuck", so a reader who
	cannot separate the two colours is told the same thing (WCAG 2.2 SC 1.4.1).

	A case with no held number renders an EMPTY cell rather than a zero. Zero
	is a claim — it means the case entered its status today — and a case that
	predates this change has made no such claim.

	Spec: openspec/changes/what-a-status-declares/specs/doorlooptijd-dashboard/spec.md
-->
<template>
	<span
		v-if="hasNumber"
		class="dwell-days"
		:class="{ 'is-breached': breached }"
		data-testid="dwell-days">
		{{ label }}
		<span v-if="breached" class="dwell-days__chip">{{
			t('dossiq', 'stuck')
		}}</span>
	</span>
	<span v-else class="dwell-days dwell-days--empty" />
</template>

<script>
export default {
	name: 'DwellDaysCell',

	// CnCellRenderer hands every cell widget `{ value, row, property,
	// formatted }`. Declaring only the two that are read leaves the rest in
	// `$attrs`; without `inheritAttrs: false` an undeclared object prop falls
	// through onto the root element and every row carries
	// `property="[object Object]"` in its DOM.
	inheritAttrs: false,

	props: {
		/** The row's `currentStatusDwellDays` — working days, or nothing. */
		value: {
			type: [String, Number],
			default: null,
		},

		/** The whole row, read for the breached flag beside the number. */
		row: {
			type: Object,
			default: () => ({}),
		},
	},

	computed: {
		/**
		 * Whether the row holds a number at all.
		 *
		 * An absent value is not a zero: every case that predates this change
		 * is in that shape, and printing zero would sort them visually beside
		 * the cases that entered their status this morning.
		 *
		 * @return {boolean} True when there is something to render.
		 *
		 * @spec openspec/changes/what-a-status-declares/specs/doorlooptijd-dashboard/spec.md
		 */
		hasNumber() {
			return this.value !== null && this.value !== undefined && this.value !== ''
		},

		/**
		 * The number, in the unit it was counted in.
		 *
		 * @return {string} The cell text.
		 *
		 * @spec openspec/changes/what-a-status-declares/specs/doorlooptijd-dashboard/spec.md
		 */
		label() {
			const days = Number(this.value)
			if (!Number.isFinite(days)) {
				return ''
			}
			return n(
				'dossiq',
				'{count} working day',
				'{count} working days',
				days,
				{ count: days },
			)
		},

		/**
		 * Whether the case is past the maximum its status declares.
		 *
		 * Read off the row's own `statusDwellBreached`, which the engine
		 * writes, rather than compared against a maximum the list has not
		 * fetched: the boundary — exactly the maximum is not yet a breach — is
		 * decided in one place on the server.
		 *
		 * @return {boolean} True when the status maximum is past.
		 *
		 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
		 */
		breached() {
			return this.row?.statusDwellBreached === true
		},
	},
}
</script>

<style scoped>
.dwell-days {
	white-space: nowrap;
}

.dwell-days.is-breached {
	color: var(--color-warning-text);
	font-weight: bold;
}

.dwell-days__chip {
	margin-inline-start: 4px;
	padding: 0 6px;
	border-radius: var(--border-radius-pill, 100px);
	background-color: var(--color-warning);
	color: var(--color-warning-text);
	font-size: 0.85em;
	font-weight: normal;
}
</style>
