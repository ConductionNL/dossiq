<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl> -->
<!--
  Delegate selector ("Namens") for parafering actions.
  Renders nothing when the actor has no configured mandates.
  Emits update:onBehalfOf and update:mandate when a mandate is chosen.

  @spec openspec/changes/parafering-actions/tasks.md#T08
-->
<template>
	<div v-if="mandates && mandates.length > 0" class="delegate-selector-field">
		<label class="delegate-selector-field__label" for="parafering-delegate">
			{{ t('procest', 'On behalf of') }}
		</label>
		<select
			id="parafering-delegate"
			class="delegate-selector-field__select"
			:value="selected"
			@change="onChange">
			<option value="">
				{{ t('procest', 'Self (no mandate)') }}
			</option>
			<option
				v-for="entry in mandates"
				:key="entry.mandateReference"
				:value="entry.mandateReference">
				{{ formatOption(entry) }}
			</option>
		</select>
	</div>
</template>

<script>
export default {
	name: 'DelegateSelectorField',
	props: {
		/**
		 * Array of mandates the logged-in user holds.
		 * Each entry: { principalUid, principalDisplayName, mandateReference }
		 */
		mandates: {
			type: Array,
			default: () => [],
		},
	},
	emits: ['update:onBehalfOf', 'update:mandate'],
	data() {
		return {
			selected: '',
		}
	},
	methods: {
		/**
		 * @param entry
		 * @spec openspec/specs/parafering-actions/spec.md
		 */
		formatOption(entry) {
			const name = entry.principalDisplayName || entry.principalUid
			return this.t('procest', 'On behalf of {name} (mandate {ref})', {
				name,
				ref: entry.mandateReference,
			})
		},
		/**
		 * @param event
		 * @spec openspec/specs/parafering-actions/spec.md
		 */
		onChange(event) {
			const value = event.target.value
			this.selected = value
			if (!value) {
				this.$emit('update:onBehalfOf', null)
				this.$emit('update:mandate', null)
				return
			}
			const entry = this.mandates.find((m) => m.mandateReference === value)
			if (entry) {
				this.$emit('update:onBehalfOf', entry.principalUid)
				this.$emit('update:mandate', entry.mandateReference)
			}
		},
	},
}
</script>

<style scoped>
.delegate-selector-field {
	margin-top: 12px;
	padding-top: 12px;
	border-top: 1px solid var(--color-border);
}

.delegate-selector-field__label {
	display: block;
	font-weight: 600;
	margin-bottom: 4px;
}

.delegate-selector-field__select {
	width: 100%;
	padding: 6px 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
}
</style>
