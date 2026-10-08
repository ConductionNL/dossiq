<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<!--
	What a row says about itself besides its status: incomplete, held, draft.

	ONE COLUMN AND NOT THREE, because the three are rare and never all true at
	once in practice, and three columns that are empty on nine rows in ten push
	the case title off the screen for nothing.

	Each marker is a word, not a colour (WCAG 2.2 SC 1.4.1). A row in none of
	the three states says nothing at all rather than "Complete": four hundred
	rows saying Complete is noise around the three that matter.

	HELD IS READ FROM THE DATE, not from a flag. A hold ends on its date and
	nothing runs overnight to clear it, so the marker is `heldUntil` still
	being ahead. A flag would need something to clear it, and that something is
	exactly what would fail silently.

	@spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
-->
<template>
	<span class="case-state" data-testid="case-state-markers">
		<span
			v-for="marker in markers"
			:key="marker.key"
			class="case-state__marker"
			:data-marker="marker.key"
			:data-testid="'case-state-' + marker.key">
			{{ marker.label }}
		</span>
	</span>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'

export default {
	name: 'CaseStateMarkersCell',

	// Same reason as UnreadIndicatorCell: the cell props this component does
	// not read would otherwise fall through onto the root span and render as
	// DOM attributes on every row.
	inheritAttrs: false,

	props: {
		/** The row this cell belongs to. */
		row: {
			type: Object,
			default: () => ({}),
		},
	},

	computed: {
		/**
		 * The markers this row carries, in the order they are read.
		 *
		 * Incomplete first: it is the one that changes what a handler should
		 * do next, because the acts needing the missing data are refused.
		 *
		 * @return {Array<{key: string, label: string}>} The markers.
		 *
		 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
		 */
		markers() {
			const found = []
			if (this.yes(this.row?.isIncomplete)) {
				found.push({ key: 'incomplete', label: t('dossiq', 'Incomplete') })
			}
			if (this.held) {
				found.push({ key: 'held', label: t('dossiq', 'On hold') })
			}
			if (this.yes(this.row?.isDraft)) {
				found.push({ key: 'draft', label: t('dossiq', 'Draft') })
			}

			return found
		},

		/**
		 * Whether the hold on this row is still running.
		 *
		 * @return {boolean} True when the wake date is still ahead.
		 *
		 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
		 */
		held() {
			const until = String(this.row?.heldUntil ?? '').trim()
			if (until === '') {
				return false
			}
			const wake = Date.parse(until)

			// An unreadable date reads as NOT held. Painting a marker off a
			// value nobody can parse would put a state on the row that no act
			// put there.
			return Number.isFinite(wake) && wake > Date.now()
		},
	},

	methods: {
		/**
		 * Whether a value read back from JSON means true.
		 *
		 * @param {boolean|number|string|null|undefined} value The stored value.
		 * @return {boolean} True only for the values that mean true.
		 *
		 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
		 */
		yes(value) {
			return value === true || value === 1 || value === '1' || value === 'true'
		},
	},
}
</script>

<style scoped>
.case-state {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	white-space: nowrap;
}

.case-state__marker {
	border-radius: var(--border-radius, 4px);
	padding: 1px 6px;
	font-size: 0.85em;
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}

.case-state__marker[data-marker='incomplete'] {
	background: var(--color-warning, var(--color-background-dark));
	color: var(--color-main-text);
}
</style>
