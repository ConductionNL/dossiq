<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<!--
	Which "all" a handler actually has.

	Ticking the header checkbox on a list of four hundred selects twenty-five,
	and a handler who thinks otherwise is about to do a quarter of the work and
	believe it was all of it. The opposite mistake costs more. So the number and
	the scope are said in words, and widening to the whole result is a second
	button rather than a side effect of the first (D-5).

	The whole-result offer is withheld when the total is unknown. Offering
	"select all 400" without knowing that there are 400 is the same surprise
	wearing a different hat.

	@spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
-->
<template>
	<div class="selection-scope" data-testid="bulk-selection-scope">
		<p class="selection-scope__sentence" data-testid="bulk-selection-sentence">
			{{ sentence }}
		</p>

		<NcButton
			v-if="offersWholeResult"
			variant="tertiary"
			data-testid="bulk-selection-widen"
			@click="widen">
			{{ wideningLabel }}
		</NcButton>

		<NcButton
			v-if="isWholeResult"
			variant="tertiary"
			data-testid="bulk-selection-narrow"
			@click="narrow">
			{{ t('dossiq', 'Go back to the {count} on this page', { count: selectedIds.length }) }}
		</NcButton>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import {
	canOfferWholeResult,
	describeScope,
	SCOPE_PAGE,
	SCOPE_RESULT,
	widenLabel,
} from '../../utils/selectionScope.js'

export default {
	name: 'BulkSelectionScope',

	components: { NcButton },

	props: {
		/** The rows the handler ticked. */
		selectedIds: { type: Array, default: () => [] },

		/** How many rows match the current search, when that is known. */
		total: { type: Number, default: 0 },

		/** The scope in force: page or result. */
		scope: { type: String, default: SCOPE_PAGE },
	},

	emits: ['update:scope'],

	computed: {
		/**
		 * @return {boolean} Whether the whole result is what is selected.
		 *
		 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
		 */
		isWholeResult() {
			return this.scope === SCOPE_RESULT
		},

		/**
		 * @return {boolean} Whether widening can honestly be offered.
		 *
		 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
		 */
		offersWholeResult() {
			return (
				this.isWholeResult === false
				&& canOfferWholeResult({ pageCount: this.selectedIds.length, total: this.total })
			)
		},

		/**
		 * @return {string} What the handler has, in words.
		 *
		 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
		 */
		sentence() {
			return describeScope(
				{ scope: this.scope, pageCount: this.selectedIds.length, total: this.total },
				t,
			)
		},

		/**
		 * @return {string} The label on the widening button.
		 *
		 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
		 */
		wideningLabel() {
			return widenLabel({ total: this.total }, t)
		},
	},

	methods: {
		t,

		/**
		 * Take the whole result instead of the page.
		 *
		 * @return {void}
		 *
		 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
		 */
		widen() {
			this.$emit('update:scope', SCOPE_RESULT)
		},

		/**
		 * Go back to the rows the handler ticked.
		 *
		 * @return {void}
		 *
		 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
		 */
		narrow() {
			this.$emit('update:scope', SCOPE_PAGE)
		},
	},
}
</script>

<style scoped>
.selection-scope {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	gap: 4px;
}

.selection-scope__sentence {
	color: var(--color-text-maxcontrast);
}
</style>
