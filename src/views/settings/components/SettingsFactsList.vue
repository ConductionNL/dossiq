<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  Label and value rows for a settings card that reports rather than edits.
  `layout="inline"` puts the label beside its value; `layout="boxes"` puts
  each label above a read-only box, two columns wide.

  @spec openspec/specs/admin-settings/spec.md
-->
<template>
	<dl class="settings-facts" :class="`settings-facts--${layout}`">
		<div v-for="row in rows" :key="row.label" class="settings-facts__row">
			<dt>{{ row.label }}</dt>
			<dd :class="{ 'settings-facts__value--muted': row.muted, 'settings-facts__value--strong': row.strong }">
				<span v-if="layout === 'boxes'" class="settings-facts__box">{{ row.value }}</span>
				<code v-else-if="row.code">{{ row.value }}</code>
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

		/** `inline` (label beside value) or `boxes` (label above a read-only box, two columns). */
		layout: {
			type: String,
			default: 'inline',
			validator: (value) => ['inline', 'boxes'].includes(value),
		},
	},
}
</script>

<style scoped>
.settings-facts {
	display: grid;
	gap: 8px 16px;
	margin: 0;
	padding: 0;
	font-size: 14px;
}

.settings-facts--inline {
	grid-template-columns: max-content 1fr;
}

.settings-facts--inline .settings-facts__row {
	display: contents;
}

.settings-facts--boxes {
	grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
	gap: 14px 12px;
}

.settings-facts--boxes .settings-facts__row {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.settings-facts--boxes .settings-facts__row dt {
	color: var(--color-main-text);
	font-weight: 600;
}

.settings-facts__box {
	display: flex;
	align-items: center;
	box-sizing: border-box;
	min-height: 40px;
	padding: 0 12px;
	border: 1px solid var(--color-border-maxcontrast);
	border-radius: 8px;
	background: var(--color-main-background);
	font-size: 15px;
}

/* Nextcloud's server.css gives every dt and dd padding, and a dt a 130px
   right-aligned column. */
.settings-facts__row dt {
	width: auto;
	padding: 0;
	color: var(--color-text-maxcontrast);
	text-align: start;
	white-space: normal;
}

.settings-facts__row dd {
	min-width: 0;
	margin: 0;
	padding: 0;
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
