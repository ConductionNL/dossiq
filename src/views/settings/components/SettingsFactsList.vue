<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  Label and value rows for a settings card that reports rather than edits.
  `layout="inline"` puts the label beside its value; `layout="grid"` stacks
  each label above its value and lays the pairs out in columns.

  @spec openspec/specs/admin-settings/spec.md
-->
<template>
	<dl class="settings-facts" :class="`settings-facts--${layout}`">
		<div v-for="row in rows" :key="row.label" class="settings-facts__row">
			<dt>{{ row.label }}</dt>
			<dd :class="{ 'settings-facts__value--muted': row.muted, 'settings-facts__value--strong': row.strong }">
				<code v-if="row.code">{{ row.value }}</code>
				<template v-else>
					{{ row.value }}
				</template>
				<span v-if="row.hint" class="settings-facts__hint">{{ row.hint }}</span>
			</dd>
		</div>
	</dl>
</template>

<script>
export default {
	name: 'SettingsFactsList',

	props: {
		/** `{ label, value, muted?, strong?, code?, hint? }` per row. */
		rows: {
			type: Array,
			required: true,
		},

		/** `inline` (label beside value) or `grid` (label above value, in columns). */
		layout: {
			type: String,
			default: 'inline',
			validator: (value) => ['inline', 'grid'].includes(value),
		},
	},
}
</script>

<style scoped>
.settings-facts {
	display: grid;
	gap: 8px 16px;
	margin: 0;
	font-size: 14px;
}

.settings-facts--inline {
	grid-template-columns: max-content 1fr;
}

.settings-facts--inline .settings-facts__row {
	display: contents;
}

.settings-facts--grid {
	grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
}

.settings-facts--grid .settings-facts__row {
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.settings-facts__row dt {
	color: var(--color-text-maxcontrast);
	white-space: normal;
}

.settings-facts__row dd {
	min-width: 0;
	margin: 0;
}

.settings-facts__value--muted {
	color: var(--color-text-maxcontrast);
}

.settings-facts__value--strong {
	font-weight: 600;
}

.settings-facts__hint {
	display: block;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}
</style>
