<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  The case type's field rights as a matrix of fields by status, on the Fields
  tab (DqZaaktypeVelden "Wie wat ziet en invult, per rol en status"). A view
  over the stored rules: fieldRoleRules (per role, a Nextcloud group,
  optionally limited to statuses) and what each status requires. The rules
  are enforced by OpenRegister; this card only reads them.
-->
<template>
	<div class="case-type-field-rights" data-testid="case-type-field-rights">
		<NcLoadingIcon v-if="loading" :size="24" />
		<p v-else-if="matrix.rows.length === 0 || matrix.columns.length === 0" class="case-type-field-rights__empty" data-testid="case-type-field-rights-empty">
			{{ t('dossiq', 'No field rights declared for this case type yet') }}
		</p>
		<table v-else class="case-type-field-rights__table">
			<thead>
				<tr>
					<th scope="col">
						{{ t('dossiq', 'Field') }}
					</th>
					<th v-for="column in matrix.columns" :key="column.id" scope="col">
						{{ column.label }}
					</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="row in matrix.rows" :key="row.field" data-testid="case-type-field-rights-row">
					<th scope="row" class="case-type-field-rights__field">
						{{ row.label }}
					</th>
					<td v-for="(cell, index) in row.cells" :key="matrix.columns[index].id" data-testid="case-type-field-rights-cell">
						<span v-for="line in linesOf(cell)" :key="line.text" :class="{ 'case-type-field-rights__open': line.open }">{{ line.text }}</span>
					</td>
				</tr>
			</tbody>
		</table>
	</div>
</template>

<script>
import { subscribe, unsubscribe } from '@nextcloud/event-bus'
import { translate as t } from '@nextcloud/l10n'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import { fetchBlueprintCatalogue } from '../../services/caseTypeRules.js'
import { useObjectStore } from '../../store/modules/object.js'
import { fieldRightsMatrix } from '../../utils/fieldRightsMatrix.js'

const PAGE_REFRESH = 'cn:page:refresh'

export default {
	name: 'CaseTypeFieldRightsWidget',

	components: { NcLoadingIcon },

	data() {
		return {
			loading: true,
			rules: [],
			statuses: [],
			properties: [],
		}
	},

	computed: {
		/**
		 * The case type this page is bound to.
		 *
		 * @return {string} The route's id.
		 * @spec openspec/changes/field-rights-matrix-by-status/specs/case-types/spec.md
		 */
		caseTypeId() {
			return String(this.$route?.params?.id ?? '')
		},

		/**
		 * The matrix of fields by status.
		 *
		 * @return {object} The columns and rows.
		 * @spec openspec/changes/field-rights-matrix-by-status/specs/case-types/spec.md
		 */
		matrix() {
			return fieldRightsMatrix({ rules: this.rules, statuses: this.statuses, properties: this.properties })
		},
	},

	async mounted() {
		subscribe(PAGE_REFRESH, this.load)
		await this.load()
	},

	beforeUnmount() {
		unsubscribe(PAGE_REFRESH, this.load)
	},

	methods: {
		t,

		/**
		 * What one cell says: required, then each role's restriction, else
		 * that everyone may change the field.
		 *
		 * @param {{required: boolean, roles: Array<{state: string, roles: Array<string>}>}} cell The cell.
		 * @return {Array<{text: string, open: boolean}>} The lines.
		 * @spec openspec/changes/field-rights-matrix-by-status/specs/case-types/spec.md
		 */
		linesOf(cell) {
			const lines = []
			if (cell.required) {
				lines.push({ text: t('dossiq', 'Required'), open: false })
			}
			for (const entry of cell.roles) {
				const roles = entry.roles.join(', ')
				const text = entry.state === 'hidden'
					? t('dossiq', 'Hidden from {roles}', { roles })
					: t('dossiq', 'Read only for {roles}', { roles })
				lines.push({ text, open: false })
			}
			if (lines.length === 0) {
				lines.push({ text: t('dossiq', 'Edit'), open: true })
			}
			return lines
		},

		/**
		 * Read the case type's rules, statuses and fields.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/field-rights-matrix-by-status/specs/case-types/spec.md
		 */
		async load() {
			if (!this.caseTypeId) {
				this.loading = false
				return
			}
			this.loading = true
			const store = useObjectStore()
			const [caseType, blueprint] = await Promise.all([
				store.fetchObject('caseType', this.caseTypeId).catch(() => null),
				fetchBlueprintCatalogue(this.caseTypeId),
			])
			this.rules = Array.isArray(caseType?.fieldRoleRules) ? caseType.fieldRoleRules : []
			this.statuses = blueprint ? blueprint.statusTypes : []
			this.properties = blueprint ? blueprint.propertyDefinitions : []
			this.loading = false
		},
	},
}
</script>

<style scoped>
.case-type-field-rights__table {
	width: 100%;
	border-collapse: collapse;
}

.case-type-field-rights__table th,
.case-type-field-rights__table td {
	padding: 10px 12px;
	border-top: 1px solid var(--color-border);
	text-align: start;
	vertical-align: top;
}

.case-type-field-rights__table thead th {
	border-top: 0;
	background: var(--color-background-hover);
	color: var(--color-text-maxcontrast);
	font-weight: 600;
}

.case-type-field-rights__table td span {
	display: block;
}

.case-type-field-rights__field {
	font-weight: 600;
}

.case-type-field-rights__open {
	color: var(--color-text-maxcontrast);
}

.case-type-field-rights__empty {
	margin: 0;
}
</style>
