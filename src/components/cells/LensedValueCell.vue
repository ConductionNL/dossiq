<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<!--
	A lens column's cell: a field that belongs to another record, shown here.

	A LENS IS NOT A COPY. OpenRegister resolves it while it renders the row, so
	the value in this cell is the linked object's own field as it stands right
	now, not a value somebody wrote onto the link when they made it. The column
	key is the lens name from `x-openregister-lenses` on the schema, and nothing
	stores it.

	THE WITHHELD MARKER IS WHY THIS IS A COMPONENT AND NOT A FORMATTER. Where
	the reader may not open the linked object, the lens answers
	`{ "@withheld": true, "reason": "access" }` instead of the value. Rendered
	as a blank cell that reads as "this case is about nothing", which is a
	different and wrong statement, and the difference matters most where the
	information is sensitive. So an unreadable value says so in words.

	Three states, three shapes, never two that look alike:

	  withheld  a padlock and the word, plus the reason on hover
	  empty     an em dash, the same as every other empty cell in the app
	  a value   the value

	Colour carries none of that (WCAG 2.2 SC 1.4.1): each state differs in its
	text, and the padlock carries a text alternative.

	@spec openspec/changes/case-objects-hinge-on-the-object/specs/case-management/spec.md
-->
<template>
	<span
		class="lensed-value"
		:data-state="state"
		:title="hint"
		data-testid="lensed-value">
		<template v-if="state === 'withheld'">
			<Lock :size="14" class="lensed-value__icon" aria-hidden="true" />
			<span class="lensed-value__withheld">{{ withheldLabel }}</span>
		</template>
		<span v-else-if="state === 'empty'" class="lensed-value__dash">—</span>
		<span v-else class="lensed-value__text">{{ text }}</span>
	</span>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import Lock from 'vue-material-design-icons/Lock.vue'
import { isWithheld, withheldReason } from '../../utils/lensValue.js'

export default {
	name: 'LensedValueCell',

	components: {
		Lock,
	},

	// The cell props this component does not read (`row`, `property`,
	// `formatted`) would otherwise fall through onto the root span and render
	// as DOM attributes on every row.
	inheritAttrs: false,

	props: {
		/** The resolved lens value: a scalar, null, or the withheld marker. */
		value: {
			type: null,
			default: null,
		},
	},

	computed: {
		/**
		 * Which of the three states this cell is in.
		 *
		 * @return {string} `withheld`, `empty` or `value`.
		 *
		 * @spec openspec/changes/case-objects-hinge-on-the-object/specs/case-management/spec.md
		 */
		state() {
			if (isWithheld(this.value)) return 'withheld'
			if (this.value === null || this.value === undefined || this.value === '')
				return 'empty'
			return 'value'
		},

		/**
		 * The value as text.
		 *
		 * @return {string} The value, stringified.
		 *
		 * @spec openspec/changes/case-objects-hinge-on-the-object/specs/case-management/spec.md
		 */
		text() {
			return String(this.value ?? '')
		},

		/**
		 * What a withheld cell says.
		 *
		 * @return {string} The label.
		 *
		 * @spec openspec/changes/case-objects-hinge-on-the-object/specs/case-management/spec.md
		 */
		withheldLabel() {
			return t('dossiq', 'Withheld')
		},

		/**
		 * What the cell says on hover, and to a screen reader.
		 *
		 * @return {string} The hint, or the empty string.
		 *
		 * @spec openspec/changes/case-objects-hinge-on-the-object/specs/case-management/spec.md
		 */
		hint() {
			if (this.state !== 'withheld') return ''
			if (withheldReason(this.value) === 'access') {
				return t(
					'dossiq',
					'You may not open the linked object, so its value is not shown.',
				)
			}
			return t('dossiq', 'This value is not shown.')
		},
	},
}
</script>

<style scoped>
.lensed-value {
	display: inline-flex;
	gap: 4px;
	align-items: center;
	max-width: 100%;
}

.lensed-value__icon {
	flex: 0 0 auto;
	color: var(--color-text-maxcontrast);
}

.lensed-value__withheld {
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.lensed-value__dash {
	color: var(--color-text-maxcontrast);
}

.lensed-value__text {
	overflow: hidden;
	white-space: nowrap;
	text-overflow: ellipsis;
}
</style>
