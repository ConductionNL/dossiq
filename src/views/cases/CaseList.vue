<template>
	<div>
		<CaseCreateDialog
			v-if="showCreateDialog"
			@created="onCaseCreated"
			@close="showCreateDialog = false" />

		<!-- Filters bar -->
		<div class="case-list-filters">
			<div class="case-list-filters__search">
				<NcTextField
					:value="searchQuery"
					:placeholder="t('procest', 'Search cases...')"
					@update:value="onSearchInput" />
			</div>
			<div class="case-list-filters__dropdowns">
				<NcSelect
					v-model="filterPriority"
					:options="priorityFilterOptions"
					:placeholder="t('procest', 'Priority')"
					:clearable="true"
					@input="onFilterChange" />
				<NcSelect
					v-model="filterHandler"
					:options="handlerFilterOptions"
					:placeholder="t('procest', 'Handler')"
					:clearable="true"
					@input="onFilterChange" />
				<NcSelect
					v-model="filterOverdue"
					:options="overdueFilterOptions"
					:placeholder="t('procest', 'Overdue')"
					:clearable="true"
					@input="onFilterChange" />
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
		</CnIndexPage>
	</div>
</template>

<script>
import { inject } from 'vue'
import { NcTextField, NcSelect } from '@nextcloud/vue'
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
		NcTextField,
		NcSelect,
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
			searchQuery: '',
			searchDebounceTimer: null,
			filterPriority: null,
			filterHandler: null,
			filterOverdue: null,
		}
	},

	computed: {
		priorityFilterOptions() {
			return [
				{ id: 'urgent', label: t('procest', 'Urgent') },
				{ id: 'high', label: t('procest', 'High') },
				{ id: 'normal', label: t('procest', 'Normal') },
				{ id: 'low', label: t('procest', 'Low') },
			]
		},
		handlerFilterOptions() {
			const handlers = new Set()
			if (this.objects) {
				for (const obj of this.objects) {
					if (obj.assignee) {
						handlers.add(obj.assignee)
					}
				}
			}
			return Array.from(handlers).map(h => ({ id: h, label: h }))
		},
		overdueFilterOptions() {
			return [
				{ id: 'yes', label: t('procest', 'Overdue') },
				{ id: 'no', label: t('procest', 'On track') },
			]
		},
		filteredObjects() {
			let result = this.objects || []

			// Search filter
			if (this.searchQuery && this.searchQuery.trim()) {
				const query = this.searchQuery.trim().toLowerCase()
				result = result.filter(obj => {
					const title = (obj.title || '').toLowerCase()
					const description = (obj.description || '').toLowerCase()
					const identifier = (obj.identifier || '').toLowerCase()
					return title.includes(query) || description.includes(query) || identifier.includes(query)
				})
			}

			// Priority filter
			if (this.filterPriority) {
				const priorityId = this.filterPriority.id || this.filterPriority
				result = result.filter(obj => obj.priority === priorityId)
			}

			// Handler filter
			if (this.filterHandler) {
				const handlerId = this.filterHandler.id || this.filterHandler
				result = result.filter(obj => obj.assignee === handlerId)
			}

			// Overdue filter
			if (this.filterOverdue) {
				const overdueId = this.filterOverdue.id || this.filterOverdue
				result = result.filter(obj => {
					const isFinal = this.isAtFinalStatus(obj)
					const overdue = isCaseOverdue(obj, isFinal)
					return overdueId === 'yes' ? overdue : !overdue
				})
			}

			return result
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

		onSearchInput(value) {
			this.searchQuery = value
			// Debounce search to avoid excessive filtering
			if (this.searchDebounceTimer) {
				clearTimeout(this.searchDebounceTimer)
			}
			this.searchDebounceTimer = setTimeout(() => {
				// Filtering is reactive via computed property
			}, 300)
		},

		onFilterChange() {
			// Filtering is reactive via computed property, no action needed
		},
	},
}
</script>

<style scoped>
.case-list-filters {
	display: flex;
	align-items: center;
	gap: 12px;
	margin-bottom: 16px;
	flex-wrap: wrap;
}

.case-list-filters__search {
	flex: 1;
	min-width: 200px;
}

.case-list-filters__dropdowns {
	display: flex;
	gap: 8px;
	flex-wrap: wrap;
}

.case-list-filters__dropdowns .v-select {
	min-width: 140px;
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
</style>

<style scoped>
/* rowClass applies to CnDataTable's <tr> elements */
:deep(.row--overdue) {
	border-left: 3px solid var(--color-error);
}
</style>
