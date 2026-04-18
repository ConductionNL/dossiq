<template>
	<div>
		<CaseCreateDialog
			v-if="showCreateDialog"
			@created="onCaseCreated"
			@close="showCreateDialog = false" />

		<!-- Filter and Search Bar -->
		<div class="case-list-controls">
			<div class="search-group">
				<input
					v-model="searchQuery"
					type="text"
					:placeholder="t('procest', 'Search cases...')"
					class="search-input"
					@input="onSearchInput" />
			</div>

			<div class="filter-group">
				<div class="filter-item">
					<label>{{ t('procest', 'Priority') }}</label>
					<select v-model="filterPriority" class="filter-select" @change="onFilterChange">
						<option value="">{{ t('procest', 'All priorities') }}</option>
						<option value="low">{{ t('procest', 'Low') }}</option>
						<option value="normal">{{ t('procest', 'Normal') }}</option>
						<option value="high">{{ t('procest', 'High') }}</option>
						<option value="urgent">{{ t('procest', 'Urgent') }}</option>
					</select>
				</div>

				<div class="filter-item">
					<label>{{ t('procest', 'Handler') }}</label>
					<input
						v-model="filterHandler"
						type="text"
						:placeholder="t('procest', 'Filter by handler...')"
						class="filter-input"
						@input="onFilterChange" />
				</div>

				<div class="filter-item checkbox-filter">
					<label>
						<input
							v-model="filterOverdue"
							type="checkbox"
							@change="onFilterChange" />
						{{ t('procest', 'Show overdue only') }}
					</label>
				</div>

				<button v-if="hasActiveFilters" class="clear-filters-btn" @click="clearFilters">
					{{ t('procest', 'Clear filters') }}
				</button>
			</div>
		</div>

		<CnIndexPage
			:title="t('procest', 'Cases')"
			:description="t('procest', 'Manage cases and workflows')"
			:schema="schema"
			:objects="filteredObjects"
			:pagination="pagination"
			:loading="loading"
			:sort-key="sortKey"
			:sort-order="sortOrder"
			:row-class="getRowClass"
			:selectable="true"
			:include-columns="visibleColumns"
			@add="showCreateDialog = true"
			@refresh="refresh"
			@sort="onSort"
			@row-click="openCase"
			@page-changed="onPageChange">
			<template #column-identifier="{ value }">
				<span class="case-id">{{ value || '\u2014' }}</span>
			</template>

			<template #column-caseType="{ value }">
				{{ getCaseTypeName(value) }}
			</template>

			<template #column-status="{ row }">
				<div @click.stop>
					<QuickStatusDropdown
						v-if="getStatusTypesForCaseType(row.caseType).length > 0"
						:case-obj="row"
						:status-types="getStatusTypesForCaseType(row.caseType)"
						@changed="onQuickStatusChanged" />
					<span v-else class="status-badge">
						{{ getStatusName(row) }}
					</span>
				</div>
			</template>

			<template #column-deadline="{ row }">
				<span :class="getDeadlineClass(row)">
					{{ getDeadlineText(row) }}
				</span>
			</template>

			<template #column-title="{ row, value }">
				<span>{{ value || '\u2014' }}</span>
				<span v-if="getSubCaseCount(row.id) > 0" class="sub-case-badge">
					{{ t('procest', '{count} sub-cases', { count: getSubCaseCount(row.id) }) }}
				</span>
			</template>
		</CnIndexPage>
	</div>
</template>

<script>
import { inject } from 'vue'
import { CnIndexPage, useListView } from '@conduction/nextcloud-vue'
import { useObjectStore } from '../../store/modules/object.js'
import { formatDeadlineCountdown, isCaseOverdue } from '../../utils/caseHelpers.js'
import CaseCreateDialog from './CaseCreateDialog.vue'
import QuickStatusDropdown from './components/QuickStatusDropdown.vue'

export default {
	name: 'CaseList',
	components: {
		CnIndexPage,
		CaseCreateDialog,
		QuickStatusDropdown,
	},

	setup() {
		const sidebarState = inject('sidebarState', null)
		const objectStore = useObjectStore()
		return useListView('case', {
			sidebarState,
			objectStore,
			defaultSort: { key: 'deadline', order: 'asc' },
		})
	},

	data() {
		return {
			showCreateDialog: false,
			caseTypeCache: {},
			statusTypeCache: {},
			subCaseCountCache: {},
			searchQuery: '',
			filterPriority: '',
			filterHandler: '',
			filterOverdue: false,
		}
	},

	computed: {
		hasActiveFilters() {
			return this.searchQuery !== '' || this.filterPriority !== '' || this.filterHandler !== '' || this.filterOverdue
		},

		filteredObjects() {
			if (!this.objects) return []

			return this.objects.filter(caseObj => {
				// Search filter (title, description, identifier)
				if (this.searchQuery) {
					const query = this.searchQuery.toLowerCase()
					const title = (caseObj.title || '').toLowerCase()
					const description = (caseObj.description || '').toLowerCase()
					const identifier = (caseObj.identifier || '').toLowerCase()

					if (!title.includes(query) && !description.includes(query) && !identifier.includes(query)) {
						return false
					}
				}

				// Priority filter
				if (this.filterPriority && caseObj.priority !== this.filterPriority) {
					return false
				}

				// Handler filter
				if (this.filterHandler) {
					const handler = (caseObj.assignee || '').toLowerCase()
					if (!handler.includes(this.filterHandler.toLowerCase())) {
						return false
					}
				}

				// Overdue filter
				if (this.filterOverdue) {
					const isFinal = this.isAtFinalStatus(caseObj)
					if (!isCaseOverdue(caseObj, isFinal)) {
						return false
					}
				}

				return true
			})
		},
	},

	watch: {
		objects: {
			handler(newObjects) {
				if (newObjects && newObjects.length > 0) {
					this.loadSubCaseCounts(newObjects)
				}
			},
			immediate: true,
		},
	},

	mounted() {
		// Load supplementary reference data (composable already handles schema + fetch)
		this.loadCaseTypes()
		this.loadStatusTypes()
	},

	methods: {
		async loadCaseTypes() {
			const objectStore = useObjectStore()
			const results = await objectStore.fetchCollection('caseType', { _limit: 100 })
			if (results) {
				for (const ct of results) {
					this.$set(this.caseTypeCache, ct.id, ct)
				}
			}
		},

		async loadStatusTypes() {
			const objectStore = useObjectStore()
			const results = await objectStore.fetchCollection('statusType', { _limit: 200 })
			if (results) {
				for (const st of results) {
					this.$set(this.statusTypeCache, st.id, st)
				}
			}
		},

		getCaseTypeName(caseTypeId) {
			if (!caseTypeId) return '\u2014'
			return this.caseTypeCache[caseTypeId]?.title || '\u2014'
		},

		getStatusName(caseItem) {
			if (!caseItem.status) return '\u2014'
			return this.statusTypeCache[caseItem.status]?.name || '\u2014'
		},

		getStatusTypesForCaseType(caseTypeId) {
			if (!caseTypeId) return []
			return Object.values(this.statusTypeCache)
				.filter(st => st.caseType === caseTypeId)
				.sort((a, b) => (a.order || 0) - (b.order || 0))
		},

		getRowClass(row) {
			const isFinal = this.isAtFinalStatus(row)
			return isCaseOverdue(row, isFinal) ? 'row--overdue' : ''
		},

		isAtFinalStatus(caseItem) {
			if (!caseItem.status) return false
			const statusType = this.statusTypeCache[caseItem.status]
			return statusType?.isFinal === true || statusType?.isFinal === 'true'
		},

		getDeadlineText(caseItem) {
			const isFinal = this.isAtFinalStatus(caseItem)
			return formatDeadlineCountdown(caseItem, isFinal).text
		},

		getDeadlineClass(caseItem) {
			const isFinal = this.isAtFinalStatus(caseItem)
			return formatDeadlineCountdown(caseItem, isFinal).style
		},

		onQuickStatusChanged() {
			this.refresh()
		},

		onCaseCreated(caseId) {
			this.showCreateDialog = false
			this.$router.push({ name: 'CaseDetail', params: { id: caseId } })
		},

		openCase(row) {
			this.$router.push({ name: 'CaseDetail', params: { id: row.id } })
		},

		getSubCaseCount(caseId) {
			return this.subCaseCountCache[caseId] || 0
		},

		async loadSubCaseCounts(cases) {
			// Batch-load sub-case counts for the visible page
			// Fetch all cases that have a parentCase pointing to any case on this page
			const objectStore = useObjectStore()
			const caseIds = cases.map(c => c.id)

			// Query all cases that are sub-cases of visible cases
			const allSubCases = await objectStore.fetchCollection('case', {
				_limit: 500,
				_fields: 'id,parentCase',
			})

			if (allSubCases) {
				const counts = {}
				for (const sc of allSubCases) {
					if (sc.parentCase && caseIds.includes(sc.parentCase)) {
						counts[sc.parentCase] = (counts[sc.parentCase] || 0) + 1
					}
				}
				for (const id of Object.keys(counts)) {
					this.$set(this.subCaseCountCache, id, counts[id])
				}
			}
		},

		onSearchInput() {
			// Search is applied via the computed filteredObjects property
			// This method can be used for debounced API calls if needed in future
		},

		onFilterChange() {
			// Filters are applied via the computed filteredObjects property
			// This method can be extended for advanced filtering in future versions
		},

		clearFilters() {
			this.searchQuery = ''
			this.filterPriority = ''
			this.filterHandler = ''
			this.filterOverdue = false
		},
	},
}
</script>

<style scoped>
/* Filter and search controls */
.case-list-controls {
	padding: 16px;
	background: var(--color-background-secondary);
	border-bottom: 1px solid var(--color-border);
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.search-group {
	flex: 1;
}

.search-input {
	width: 100%;
	max-width: 400px;
	padding: 8px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	font-size: 13px;
}

.search-input:focus {
	outline: none;
	border-color: var(--color-primary-element);
	box-shadow: 0 0 0 2px rgba(0, 0, 0, 0.1);
}

.filter-group {
	display: flex;
	gap: 12px;
	flex-wrap: wrap;
	align-items: flex-end;
}

.filter-item {
	display: flex;
	flex-direction: column;
	gap: 4px;
	min-width: 150px;
}

.filter-item.checkbox-filter {
	flex-direction: row;
	align-items: center;
	min-width: auto;
}

.filter-item.checkbox-filter label {
	display: flex;
	align-items: center;
	gap: 6px;
	margin: 0;
}

.filter-item label {
	font-size: 12px;
	font-weight: 500;
	color: var(--color-text-maxcontrast);
	text-transform: uppercase;
	letter-spacing: 0.5px;
}

.filter-select,
.filter-input {
	padding: 6px 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	font-size: 13px;
	background: white;
}

.filter-select:focus,
.filter-input:focus {
	outline: none;
	border-color: var(--color-primary-element);
	box-shadow: 0 0 0 2px rgba(0, 0, 0, 0.1);
}

.clear-filters-btn {
	padding: 6px 12px;
	background: var(--color-background-dark);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	font-size: 12px;
	color: var(--color-main-text);
	cursor: pointer;
	transition: background-color 0.2s;
}

.clear-filters-btn:hover {
	background: var(--color-border);
}

.case-id {
	font-family: monospace;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
}

.status-badge {
	display: inline-block;
	padding: 2px 8px;
	border-radius: var(--border-radius-pill);
	font-size: 12px;
	font-weight: 500;
	background: var(--color-background-dark);
}

.deadline--overdue {
	color: var(--color-error);
	font-weight: 500;
}

.deadline--today,
.deadline--tomorrow {
	color: var(--color-warning);
	font-weight: 500;
}

.deadline--ok {
	color: var(--color-success);
}

.deadline--final {
	color: var(--color-text-maxcontrast);
}

.sub-case-badge {
	display: inline-block;
	margin-left: 8px;
	padding: 1px 6px;
	border-radius: var(--border-radius-pill);
	font-size: 11px;
	font-weight: 500;
	background: var(--color-primary-element-light);
	color: var(--color-primary-element-light-text);
}
</style>

<style scoped>
/* rowClass applies to CnDataTable's <tr> elements */
:deep(.row--overdue) {
	border-left: 3px solid var(--color-error);
}
</style>
