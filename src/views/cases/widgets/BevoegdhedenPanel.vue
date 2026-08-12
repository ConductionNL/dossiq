<template>
	<div class="bevoegdheden-panel">
		<header class="bevoegdheden-panel__header">
			<h3>{{ t('procest', 'My authorities') }}</h3>
			<div class="bevoegdheden-panel__controls">
				<label class="bevoegdheden-panel__toggle">
					<input type="checkbox" :checked="onlyUnilateral" @change="onToggleFilter">
					<span>{{ t('procest', 'Only what I can do unilaterally') }}</span>
				</label>
			</div>
		</header>

		<div v-if="loading" class="bevoegdheden-panel__empty">
			{{ t('procest', 'Loading authorities…') }}
		</div>
		<div v-else-if="filteredRows.length === 0" class="bevoegdheden-panel__empty">
			{{ t('procest', 'No applicable mandates for this case.') }}
		</div>
		<table v-else class="bevoegdheden-panel__table">
			<thead>
				<tr>
					<th scope="col">{{ t('procest', 'Mandate #') }}</th>
					<th scope="col">{{ t('procest', 'Description') }}</th>
					<th scope="col">{{ t('procest', 'Type') }}</th>
					<th scope="col">{{ t('procest', 'Ceiling') }}</th>
					<th scope="col">{{ t('procest', 'Subdelegation') }}</th>
					<th scope="col">{{ t('procest', 'Valid') }}</th>
					<th scope="col">{{ t('procest', 'Details') }}</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="row in filteredRows" :key="row.id" class="bevoegdheden-panel__row">
					<td>{{ row.mandateNumber }}</td>
					<td>{{ row.description }}</td>
					<td>{{ row.bevoegdheidType || '-' }}</td>
					<td>{{ formatCeiling(row) }}</td>
					<td>{{ row.subdelegatie ? t('procest', 'Yes') : t('procest', 'No') }}</td>
					<td>{{ formatValidity(row) }}</td>
					<td>
						<button
							type="button"
							class="bevoegdheden-panel__details-btn"
							@click="expand(row)">
							{{ expandedId === row.id ? t('procest', 'Hide') : t('procest', 'Show') }}
						</button>
					</td>
				</tr>
			</tbody>
		</table>

		<MandaatMatrixWidget
			v-if="expandedRow"
			:mandaat="expandedRow"
			:role-holders="holdersFor(expandedRow)"
			@close="expandedId = null" />
	</div>
</template>

<script>
import MandaatMatrixWidget from './MandaatMatrixWidget.vue'

/**
 * Bevoegdheden panel.
 *
 * Renders the case-scoped applicable Mandaat list for the current user.
 * Data shape mirrors what `MandaatCheckService::getApplicableMandaten`
 * returns from `/api/mandate/cases/{caseId}/applicable`.
 *
 * @spec openspec/changes/mandaat-matrix-08-user-ui/tasks.md
 */
export default {
	name: 'BevoegdhedenPanel',

	components: {
		MandaatMatrixWidget,
	},

	props: {
		caseId: {
			type: String,
			required: true,
		},
		caseType: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			loading: true,
			rows: [],
			onlyUnilateral: false,
			expandedId: null,
		}
	},

	computed: {
		/**
		 * Apply the "only unilateral" filter.
		 *
		 * @return {Array} Filtered mandates.
		 *
		 * @spec openspec/changes/mandaat-matrix-08-user-ui/tasks.md
		 */
		filteredRows() {
			if (!this.onlyUnilateral) {
				return this.rows
			}
			return this.rows.filter((r) => r.unilateral === true)
		},

		/**
		 * The expanded row object.
		 *
		 * @return {object | null} Expanded row.
		 *
		 * @spec openspec/changes/mandaat-matrix-08-user-ui/tasks.md
		 */
		expandedRow() {
			if (this.expandedId === null) {
				return null
			}
			return this.rows.find((r) => r.id === this.expandedId) || null
		},
	},

	mounted() {
		this.fetch()
	},

	methods: {
		/**
		 * Fetch the applicable mandates for this case.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/mandaat-matrix-08-user-ui/tasks.md
		 */
		async fetch() {
			this.loading = true
			try {
				const res = await fetch(
					OC.generateUrl('/apps/procest/api/mandate/cases/' + this.caseId + '/applicable'),
					{ headers: { requesttoken: OC.requestToken } },
				)
				if (res.ok) {
					const body = await res.json()
					this.rows = Array.isArray(body) ? body : (body.items || [])
				} else {
					this.rows = []
				}
			} catch (e) {
				this.rows = []
			} finally {
				this.loading = false
			}
		},

		/**
		 * Toggle the unilateral filter.
		 *
		 * @param {Event} event Toggle event.
		 *
		 * @spec openspec/changes/mandaat-matrix-08-user-ui/tasks.md
		 */
		onToggleFilter(event) {
			this.onlyUnilateral = event.target.checked
		},

		/**
		 * Expand or collapse a row.
		 *
		 * @param {object} row Row.
		 *
		 * @spec openspec/changes/mandaat-matrix-08-user-ui/tasks.md
		 */
		expand(row) {
			this.expandedId = (this.expandedId === row.id) ? null : row.id
		},

		/**
		 * Format the validity window.
		 *
		 * @param {object} row Row.
		 * @return {string} Formatted range.
		 *
		 * @spec openspec/changes/mandaat-matrix-08-user-ui/tasks.md
		 */
		formatValidity(row) {
			const from = row.inWerkingtreding || '?'
			const to = row.vervalDatum || t('procest', 'indefinite')
			return `${from} → ${to}`
		},

		/**
		 * Format the ceiling.
		 *
		 * @param {object} row Row.
		 * @return {string} Formatted ceiling.
		 *
		 * @spec openspec/changes/mandaat-matrix-08-user-ui/tasks.md
		 */
		formatCeiling(row) {
			if (row.voorwaarden && typeof row.voorwaarden.plafond === 'number') {
				return '€ ' + row.voorwaarden.plafond.toLocaleString('nl-NL')
			}
			return '-'
		},

		/**
		 * Look up role holders for a mandate.
		 *
		 * @param {object} row Row.
		 * @return {Array} Holders.
		 *
		 * @spec openspec/changes/mandaat-matrix-08-user-ui/tasks.md
		 */
		holdersFor(row) {
			return row.roleHolders || []
		},
	},
}
</script>

<style scoped>
.bevoegdheden-panel {
	padding: var(--default-grid-baseline, 8px);
	background: var(--color-main-background);
	border-radius: var(--border-radius);
}

.bevoegdheden-panel__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	margin-bottom: var(--default-grid-baseline, 8px);
}

.bevoegdheden-panel__empty {
	padding: calc(var(--default-grid-baseline, 8px) * 2);
	color: var(--color-text-maxcontrast);
	text-align: center;
}

.bevoegdheden-panel__table {
	width: 100%;
	border-collapse: collapse;
}

.bevoegdheden-panel__table th,
.bevoegdheden-panel__table td {
	padding: var(--default-grid-baseline, 8px);
	border-bottom: 1px solid var(--color-border);
	text-align: left;
}

.bevoegdheden-panel__details-btn {
	background: transparent;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	cursor: pointer;
	padding: 4px 10px;
}
</style>
