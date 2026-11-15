<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  The case type's field rights as a matrix of fields by role, on the Fields
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
					<th scope="col" :aria-sort="sortDir === 'asc' ? 'ascending' : (sortDir === 'desc' ? 'descending' : undefined)">
						<span class="case-type-field-rights__head">
							<button type="button" class="case-type-field-rights__sort" data-testid="case-type-field-rights-sort" @click="toggleSort">
								{{ t('dossiq', 'Field') }}
								<ChevronUp v-if="sortDir === 'asc'" :size="14" />
								<ChevronDown v-else :size="14" />
							</button>
						</span>
					</th>
					<th v-for="(column, index) in matrix.columns" :key="column.id" scope="col">
						<span class="case-type-field-rights__head">
							{{ column.label }}
							<NcActions class="case-type-field-rights__filter"
								:aria-label="t('dossiq', 'Filter by {column}', { column: column.label })"
								:data-testid="`case-type-field-rights-filter-${index}`">
								<template #icon>
									<FilterOutline :size="14" />
								</template>
								<NcActionCheckbox v-for="state in statesIn(index)"
									:key="state"
									:modelValue="(filters[index] || []).includes(state)"
									@update:modelValue="toggleFilter(index, state)">
									{{ labelOf(state) }}
								</NcActionCheckbox>
							</NcActions>
						</span>
					</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="row in shownRows" :key="row.field" data-testid="case-type-field-rights-row">
					<th scope="row" class="case-type-field-rights__field">
						{{ row.label }}
					</th>
					<td v-for="(cell, index) in row.cells" :key="matrix.columns[index].id" data-testid="case-type-field-rights-cell">
						<CnStatusBadge :label="labelOf(cell.state)" :variant="variantOf(cell.state)" size="small" />
					</td>
				</tr>
			</tbody>
		</table>
	</div>
</template>

<script>
import { CnStatusBadge } from '@conduction/nextcloud-vue'
import { subscribe, unsubscribe } from '@nextcloud/event-bus'
import { translate as t } from '@nextcloud/l10n'
import NcActionCheckbox from '@nextcloud/vue/components/NcActionCheckbox'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import ChevronDown from 'vue-material-design-icons/ChevronDown.vue'
import ChevronUp from 'vue-material-design-icons/ChevronUp.vue'
import FilterOutline from 'vue-material-design-icons/FilterOutline.vue'
import { fetchBlueprintCatalogue, fetchRoleNamesByGroup } from '../../services/caseTypeRules.js'
import { useObjectStore } from '../../store/modules/object.js'
import { fieldRightsMatrix } from '../../utils/fieldRightsMatrix.js'

const PAGE_REFRESH = 'cn:page:refresh'

export default {
	name: 'CaseTypeFieldRightsWidget',

	components: { ChevronDown, ChevronUp, CnStatusBadge, FilterOutline, NcActionCheckbox, NcActions, NcLoadingIcon },

	data() {
		return {
			loading: true,
			rules: [],
			statuses: [],
			properties: [],
			roleNames: {},
			/** The Field head's sort: '' (as declared), 'asc' or 'desc'. */
			sortDir: '',
			/** The role heads' filters: the states each keeps, by column index. */
			filters: {},
		}
	},

	computed: {
		/**
		 * The rows the heads leave: those whose cells pass every role filter, in
		 * the Field head's order.
		 *
		 * @return {Array<object>} The rows.
		 * @spec openspec/specs/case-types/spec.md
		 */
		shownRows() {
			const kept = this.matrix.rows.filter((row) => Object.entries(this.filters)
				.every(([index, states]) => states.length === 0 || states.includes(row.cells[Number(index)]?.state)))
			if (this.sortDir === '') {
				return kept
			}
			const sign = this.sortDir === 'desc' ? -1 : 1
			return [...kept].sort((a, b) => sign * a.label.localeCompare(b.label, undefined, { sensitivity: 'base' }))
		},

		/**
		 * The case type this page is bound to.
		 *
		 * @return {string} The route's id.
		 * @spec openspec/specs/case-types/spec.md
		 */
		caseTypeId() {
			return String(this.$route?.params?.id ?? '')
		},

		/**
		 * The matrix of fields by status.
		 *
		 * @return {object} The columns and rows.
		 * @spec openspec/specs/case-types/spec.md
		 */
		matrix() {
			return fieldRightsMatrix({ rules: this.rules, statuses: this.statuses, properties: this.properties, roleNames: this.roleNames })
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

		/** Sort by field: ascending first, then descending. */
		toggleSort() {
			this.sortDir = this.sortDir === 'asc' ? 'desc' : 'asc'
		},

		/**
		 * The states a role column holds, once each, in the matrix's order of states.
		 *
		 * @param {number} index The column.
		 * @return {Array<string>} The states.
		 */
		statesIn(index) {
			const present = new Set(this.matrix.rows.map((row) => row.cells[index]?.state).filter(Boolean))
			return ['required', 'edit', 'readOnly', 'hidden'].filter((state) => present.has(state))
		},

		/**
		 * Keep or drop one state in a role column's filter.
		 *
		 * @param {number} index The column.
		 * @param {string} state The state.
		 */
		toggleFilter(index, state) {
			const current = this.filters[index] || []
			this.filters = { ...this.filters, [index]: current.includes(state) ? current.filter((s) => s !== state) : [...current, state] }
		},

		/**
		 * What one cell says, in words.
		 *
		 * @param {string} state hidden, readOnly, required or edit.
		 * @return {string} The pill's label.
		 * @spec openspec/specs/case-types/spec.md
		 */
		labelOf(state) {
			return {
				hidden: t('dossiq', 'Hidden'),
				readOnly: t('dossiq', 'Read only'),
				required: t('dossiq', 'Required'),
			}[state] || t('dossiq', 'Edit')
		},

		/**
		 * The pill's tone for a cell: required info, edit success, hidden error, read only grey.
		 *
		 * @param {string} state hidden, readOnly, required or edit.
		 * @return {string} A CnStatusBadge variant.
		 * @spec openspec/specs/case-types/spec.md
		 */
		variantOf(state) {
			return { hidden: 'error', readOnly: 'default', required: 'info' }[state] || 'success'
		},

		/**
		 * Read the case type's rules, statuses and fields.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/case-types/spec.md
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
			this.roleNames = await fetchRoleNamesByGroup(this.caseTypeId)
			this.loading = false
		},
	},
}
</script>

<style scoped>
.case-type-field-rights__table {
	width: 100%;
	/* The columns share the card's width and their heads wrap, as
	   DqZaaktypeVelden draws them, rather than running the table past the card. */
	table-layout: fixed;
	border-collapse: collapse;
	font-size: 14px;
}

.case-type-field-rights__table th,
.case-type-field-rights__table td {
	padding: 10px 12px;
	border-top: 1px solid var(--color-border);
	text-align: start;
	vertical-align: middle;
	line-height: normal;
}

.case-type-field-rights__table tbody th,
.case-type-field-rights__table tbody td {
	padding: 11px 12px;
}

.case-type-field-rights__table thead th {
	border-top: 0;
	/* The boards' near-white table head (#fafbfc), as CnDataTable's in the board look. */
	background: var(--cn-board-table-head-bg, color-mix(in srgb, var(--color-background-hover) 40%, var(--color-main-background)));
	color: var(--color-text-maxcontrast);
	font-weight: 600;
	white-space: normal;
}

.case-type-field-rights__head {
	display: flex;
	gap: 4px;
	align-items: center;
}

.case-type-field-rights__filter {
	flex: none;
}

.case-type-field-rights__sort {
	display: inline-flex;
	gap: 4px;
	align-items: center;
	min-height: 0;
	margin: 0;
	padding: 0;
	border: 0;
	border-radius: 0;
	background: transparent;
	color: inherit;
	font: inherit;
	line-height: normal;
	cursor: pointer;
}

/* The funnel is the boards' 22px control beside a head. */
.case-type-field-rights__filter :deep(.button-vue) {
	--button-size: 22px;
	width: 22px;
	min-width: 22px;
	height: 22px;
	min-height: 22px;
	border-radius: 4px;
}

/* The field name reads as plain text on the card, as the board draws it,
   not as the theme's grey table head. */
.case-type-field-rights__field {
	font-weight: 400;
	background: transparent;
}

.case-type-field-rights__empty {
	margin: 0;
}
</style>
