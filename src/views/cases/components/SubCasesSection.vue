<template>
	<div class="sub-cases-section">
		<NcLoadingIcon v-if="loading" />

		<template v-else-if="subCases.length === 0">
			<div class="sub-cases-section__empty">
				<p>{{ t('procest', 'No sub-cases yet') }}</p>
			</div>
		</template>

		<template v-else>
			<div class="viewTableContainer">
				<table class="viewTable">
					<thead>
						<tr>
							<th scope="col">{{ t('procest', 'Title') }}</th>
							<th scope="col">{{ t('procest', 'Status') }}</th>
							<th scope="col">{{ t('procest', 'Assignee') }}</th>
							<th scope="col">{{ t('procest', 'Deadline') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr
							v-for="subCase in subCases"
							:key="subCase.id"
							class="viewTableRow"
							@click="openSubCase(subCase)">
							<td>{{ subCase.title || '\u2014' }}</td>
							<td>
								<span
									class="status-badge"
									:class="getStatusClass(subCase)">
									{{ getStatusName(subCase) }}
								</span>
							</td>
							<td>{{ subCase.assignee || '\u2014' }}</td>
							<td>{{ formatDeadline(subCase.deadline) }}</td>
						</tr>
					</tbody>
				</table>
			</div>
		</template>

		<div v-if="canCreateSubCase" class="sub-cases-section__actions">
			<NcButton @click="$emit('create-sub-case')">
				{{ t('procest', 'Create Sub-case') }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import { useObjectStore } from '../../../store/modules/object.js'
import { formatDate } from '../../../utils/caseHelpers.js'

export default {
	name: 'SubCasesSection',
	components: {
		NcButton,
		NcLoadingIcon,
	},
	props: {
		caseId: {
			type: String,
			required: true,
		},
		parentCase: {
			type: String,
			default: null,
		},
		endDate: {
			type: String,
			default: null,
		},
		subCaseTypes: {
			type: Array,
			default: () => [],
		},
	},
	emits: ['create-sub-case', 'sub-cases-loaded'],
	data() {
		return {
			loading: true,
			subCases: [],
			statusTypeCache: {},
		}
	},
	computed: {
		/** @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md */
		objectStore() {
			return useObjectStore()
		},
		/** @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md */
		completedCount() {
			return this.subCases.filter((sc) => sc.endDate).length
		},
		/** @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md */
		totalCount() {
			return this.subCases.length
		},
		/** @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md */
		rollUpText() {
			return t('procest', 'Sub-cases ({completed}/{total} completed)', {
				completed: this.completedCount,
				total: this.totalCount,
			})
		},
		/** @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md */
		canCreateSubCase() {
			// Hide when: parent case is closed, current case is itself a sub-case,
			// or case type has empty subCaseTypes
			if (this.endDate) return false
			if (this.parentCase) return false
			if (!this.subCaseTypes || this.subCaseTypes.length === 0) return false
			return true
		},
	},
	async mounted() {
		await this.fetchSubCases()
	},
	methods: {
		/** @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md */
		async fetchSubCases() {
			this.loading = true
			try {
				const [subCases, statusTypes] = await Promise.all([
					this.objectStore.fetchCollection('case', {
						'_filters[parentCase]': this.caseId,
						_limit: 100,
					}),
					this.objectStore.fetchCollection('statusType', { _limit: 200 }),
				])
				this.subCases = subCases || []

				if (statusTypes) {
					for (const st of statusTypes) {
						this.statusTypeCache[st.id] = st
					}
				}

				this.$emit('sub-cases-loaded', this.subCases)
			} catch (err) {
				console.error('Failed to fetch sub-cases:', err)
			} finally {
				this.loading = false
			}
		},

		/**
		 * @param subCase
		 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
		 */
		getStatusName(subCase) {
			if (!subCase.status) return '\u2014'
			return this.statusTypeCache[subCase.status]?.name || '\u2014'
		},

		/**
		 * @param subCase
		 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
		 */
		getStatusClass(subCase) {
			if (!subCase.status) return ''
			const st = this.statusTypeCache[subCase.status]
			if (st?.isFinal === true || st?.isFinal === 'true')
				return 'status-badge--final'
			return 'status-badge--active'
		},

		/**
		 * @param deadline
		 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
		 */
		formatDeadline(deadline) {
			if (!deadline) return '\u2014'
			return formatDate(deadline)
		},

		/**
		 * @param subCase
		 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
		 */
		openSubCase(subCase) {
			this.$router.push({ name: 'CaseDetail', params: { id: subCase.id } })
		},
	},
}
</script>

<style scoped>
.sub-cases-section__empty {
	text-align: center;
	color: var(--color-text-maxcontrast);
	padding: 16px;
}

.sub-cases-section__actions {
	margin-top: 12px;
}

.viewTableContainer {
	background: var(--color-main-background);
	border-radius: var(--border-radius);
	overflow: hidden;
	box-shadow: 0 2px 4px var(--color-box-shadow);
	border: 1px solid var(--color-border);
}

.viewTable {
	width: 100%;
	border-collapse: collapse;
	background-color: var(--color-main-background);
}

.viewTable th,
.viewTable td {
	padding: 12px;
	text-align: left;
	border-bottom: 1px solid var(--color-border);
	vertical-align: middle;
}

.viewTable th {
	background-color: var(--color-background-dark);
	font-weight: 500;
	color: var(--color-text-maxcontrast);
}

.viewTableRow {
	cursor: pointer;
	transition: background-color 0.2s ease;
}

.viewTableRow:hover {
	background: var(--color-background-hover);
}

.status-badge {
	display: inline-block;
	padding: 2px 8px;
	border-radius: var(--border-radius-pill);
	font-size: 12px;
	font-weight: 500;
}

.status-badge--active {
	background: var(--color-primary-light);
	color: var(--color-primary-text);
}

.status-badge--final {
	background: var(--color-success);
	color: white;
}

@media (prefers-reduced-motion: reduce) {
	.viewTableRow {
		transition: none;
	}
}
</style>
