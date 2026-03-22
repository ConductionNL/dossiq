<template>
	<div class="case-detail-dashboard">
		<!-- Back navigation and header -->
		<div class="case-detail-header">
			<NcButton type="tertiary" @click="$router.push({ name: 'Cases' })">
				&larr; {{ t('procest', 'Back to list') }}
			</NcButton>
			<div class="case-detail-header__title">
				<h2>{{ caseData.title || t('procest', 'Case') }}</h2>
				<span v-if="caseData.identifier" class="case-detail-header__subtitle">
					{{ t('procest', 'Case') }} &mdash; {{ caseData.identifier }}
				</span>
			</div>
			<div class="case-detail-header__actions">
				<NcButton type="error" @click="confirmDelete">
					{{ t('procest', 'Delete') }}
				</NcButton>
			</div>
		</div>

		<NcLoadingIcon v-if="loading" :size="44" class="case-detail-loading" />

		<CnDashboardPage
			v-else-if="!isNew"
			:widgets="caseWidgets"
			:layout="caseLayout"
			:editable="false"
			title=""
			:loading="false"
			:empty-label="t('procest', 'No widgets configured')"
			:unavailable-label="t('procest', 'Widget not available')">
			<!-- Case Properties Widget -->
			<template #widget-case-properties>
				<CasePropertiesWidget
					:case-data="caseData"
					:case-type-name="caseTypeName"
					:status-name="currentStatusName"
					:status-badge-class="currentStatusBadgeClass"
					:is-read-only="isReadOnly"
					:is-at-final-status="isAtFinalStatus"
					:case-result="caseResult"
					:result-types="resultTypes"
					@save="onPropertiesSave" />
			</template>

			<!-- Case Timeline Widget -->
			<template #widget-case-timeline>
				<CaseTimelineWidget
					:ordered-status-types="orderedStatusTypes"
					:current-status-id="caseData.status"
					:status-history="caseData.statusHistory || []"
					:result-types="resultTypes"
					:is-read-only="isReadOnly"
					@status-change="onStatusChange"
					@status-change-with-result="onStatusChangeWithResult" />
			</template>

			<!-- Case Tasks Widget -->
			<template #widget-case-tasks>
				<CaseTasksWidget
					:case-id="caseId"
					:tasks="tasks"
					:is-read-only="isReadOnly" />
			</template>

			<!-- Case Documents Widget -->
			<template #widget-case-documents>
				<CaseDocumentsWidget
					:case-id="caseId"
					:documents="documents" />
			</template>

			<!-- Case Roles Widget -->
			<template #widget-case-roles>
				<CaseRolesWidget
					:case-id="caseId"
					:is-read-only="isReadOnly"
					@handler-changed="onHandlerChanged" />
			</template>

			<!-- Case Decisions Widget -->
			<template #widget-case-decisions>
				<CaseDecisionsWidget
					:case-id="caseId"
					:decisions="decisions"
					:case-result="caseResult"
					:result-types="resultTypes" />
			</template>

			<!-- Case Milestones Widget -->
			<template #widget-case-milestones>
				<CaseMilestonesWidget
					:case-data="caseData"
					:case-type-data="caseTypeData"
					:milestones="milestones"
					:is-final="isAtFinalStatus"
					@extend="showExtensionDialog" />
			</template>

			<!-- Case Notes Widget -->
			<template #widget-case-notes>
				<CaseNotesWidget
					:activity="caseData.activity || []"
					:is-read-only="isReadOnly"
					@add-note="onAddNote" />
			</template>
		</CnDashboardPage>

		<!-- Extension dialog -->
		<div v-if="showExtension" class="extension-overlay" @click.self="showExtension = false">
			<div class="extension-dialog">
				<h3>{{ t('procest', 'Extend Deadline') }}</h3>
				<p>{{ t('procest', 'This will extend the deadline by {period}.', { period: extensionPeriodText }) }}</p>
				<div class="form-group">
					<label>{{ t('procest', 'Reason') }}</label>
					<textarea
						v-model="extensionReason"
						:placeholder="t('procest', 'Why is an extension needed?')"
						rows="3" />
				</div>
				<div class="extension-dialog__actions">
					<NcButton @click="showExtension = false">
						{{ t('procest', 'Cancel') }}
					</NcButton>
					<NcButton type="primary" @click="confirmExtension">
						{{ t('procest', 'Extend deadline') }}
					</NcButton>
				</div>
			</div>
		</div>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import { CnDashboardPage } from '@conduction/nextcloud-vue'
import { useObjectStore } from '../../store/modules/object.js'
import { calculateDeadline, formatDate, formatDuration } from '../../utils/caseHelpers.js'
import { validateCaseUpdate } from '../../utils/caseValidation.js'
import CasePropertiesWidget from './widgets/CasePropertiesWidget.vue'
import CaseTimelineWidget from './widgets/CaseTimelineWidget.vue'
import CaseTasksWidget from './widgets/CaseTasksWidget.vue'
import CaseDocumentsWidget from './widgets/CaseDocumentsWidget.vue'
import CaseRolesWidget from './widgets/CaseRolesWidget.vue'
import CaseDecisionsWidget from './widgets/CaseDecisionsWidget.vue'
import CaseMilestonesWidget from './widgets/CaseMilestonesWidget.vue'
import CaseNotesWidget from './widgets/CaseNotesWidget.vue'

/**
 * Default layout for the case detail dashboard.
 * 12-column grid: properties and timeline share the top row,
 * tasks and documents in the second row, roles and decisions
 * in the third, milestones and notes at the bottom.
 */
const DEFAULT_LAYOUT = [
	{ id: 1, widgetId: 'case-properties', gridX: 0, gridY: 0, gridWidth: 6, gridHeight: 6 },
	{ id: 2, widgetId: 'case-timeline', gridX: 6, gridY: 0, gridWidth: 6, gridHeight: 3 },
	{ id: 3, widgetId: 'case-milestones', gridX: 6, gridY: 3, gridWidth: 6, gridHeight: 3 },
	{ id: 4, widgetId: 'case-tasks', gridX: 0, gridY: 6, gridWidth: 6, gridHeight: 4 },
	{ id: 5, widgetId: 'case-documents', gridX: 6, gridY: 6, gridWidth: 6, gridHeight: 4 },
	{ id: 6, widgetId: 'case-roles', gridX: 0, gridY: 10, gridWidth: 4, gridHeight: 4 },
	{ id: 7, widgetId: 'case-decisions', gridX: 4, gridY: 10, gridWidth: 4, gridHeight: 4 },
	{ id: 8, widgetId: 'case-notes', gridX: 8, gridY: 10, gridWidth: 4, gridHeight: 4 },
]

export default {
	name: 'CaseDetail',
	components: {
		NcButton,
		NcLoadingIcon,
		CnDashboardPage,
		CasePropertiesWidget,
		CaseTimelineWidget,
		CaseTasksWidget,
		CaseDocumentsWidget,
		CaseRolesWidget,
		CaseDecisionsWidget,
		CaseMilestonesWidget,
		CaseNotesWidget,
	},
	props: {
		caseId: {
			type: String,
			default: null,
		},
	},
	data() {
		return {
			tasks: [],
			documents: [],
			decisions: [],
			milestones: [],
			statusTypes: [],
			caseTypeData: null,
			resultTypes: [],
			caseResult: null,
			// Extension state
			showExtension: false,
			extensionReason: '',
			// Layout
			caseLayout: [...DEFAULT_LAYOUT],
		}
	},
	computed: {
		objectStore() {
			return useObjectStore()
		},
		loading() {
			return this.objectStore.loading.case || false
		},
		caseData() {
			return this.objectStore.getObject('case', this.caseId) || {}
		},
		caseTypeName() {
			return this.caseTypeData?.title || '---'
		},
		orderedStatusTypes() {
			return [...this.statusTypes].sort((a, b) => (a.order || 0) - (b.order || 0))
		},
		currentStatusType() {
			if (!this.caseData.status) return null
			return this.statusTypes.find(st => st.id === this.caseData.status) || null
		},
		currentStatusName() {
			return this.currentStatusType?.name || '---'
		},
		currentStatusBadgeClass() {
			if (this.isAtFinalStatus) return 'status-badge--final'
			return 'status-badge--active'
		},
		isAtFinalStatus() {
			return this.currentStatusType?.isFinal === true || this.currentStatusType?.isFinal === 'true'
		},
		isReadOnly() {
			return this.isAtFinalStatus
		},
		isNew() {
			return !this.caseId || this.caseId === 'new'
		},
		extensionPeriodText() {
			if (!this.caseTypeData?.extensionPeriod) return ''
			return formatDuration(this.caseTypeData.extensionPeriod)
		},
		caseWidgets() {
			return [
				{ id: 'case-properties', title: t('procest', 'Case Information'), type: 'custom' },
				{ id: 'case-timeline', title: t('procest', 'Status Timeline'), type: 'custom' },
				{ id: 'case-tasks', title: t('procest', 'Tasks'), type: 'custom' },
				{ id: 'case-documents', title: t('procest', 'Documents'), type: 'custom' },
				{ id: 'case-roles', title: t('procest', 'Participants'), type: 'custom' },
				{ id: 'case-decisions', title: t('procest', 'Decisions'), type: 'custom' },
				{ id: 'case-milestones', title: t('procest', 'Deadline & Milestones'), type: 'custom' },
				{ id: 'case-notes', title: t('procest', 'Activity & Notes'), type: 'custom' },
			]
		},
	},
	async mounted() {
		if (!this.isNew) {
			await this.objectStore.fetchObject('case', this.caseId)
			await Promise.all([
				this.loadCaseTypeData(),
				this.fetchTasks(),
				this.fetchCaseResult(),
				this.fetchDocuments(),
				this.fetchDecisions(),
				this.fetchMilestones(),
			])
		}
	},
	methods: {
		formatDate,

		// --- Data fetching ---
		async loadCaseTypeData() {
			const caseTypeId = this.caseData.caseType
			if (!caseTypeId) return

			const caseType = await this.objectStore.fetchObject('caseType', caseTypeId)
			this.caseTypeData = caseType

			if (caseType) {
				const [statusResults, resultTypeResults] = await Promise.all([
					this.objectStore.fetchCollection('statusType', {
						'_filters[caseType]': caseTypeId,
						_order: JSON.stringify({ order: 'asc' }),
						_limit: 100,
					}),
					this.objectStore.fetchCollection('resultType', {
						'_filters[caseType]': caseTypeId,
						_limit: 100,
					}),
				])
				this.statusTypes = statusResults || []
				this.resultTypes = resultTypeResults || []
			}
		},

		async fetchCaseResult() {
			const results = await this.objectStore.fetchCollection('result', {
				'_filters[case]': this.caseId,
				_limit: 1,
			})
			this.caseResult = (results && results.length > 0) ? results[0] : null
		},

		async fetchTasks() {
			const results = await this.objectStore.fetchCollection('task', {
				_limit: 50,
				'_filters[case]': this.caseId,
			})
			this.tasks = results || []
		},

		async fetchDocuments() {
			try {
				const results = await this.objectStore.fetchCollection('document', {
					'_filters[case]': this.caseId,
					_limit: 50,
				})
				this.documents = results || []
			} catch {
				// Documents collection may not exist yet
				this.documents = []
			}
		},

		async fetchDecisions() {
			try {
				const results = await this.objectStore.fetchCollection('decision', {
					'_filters[case]': this.caseId,
					_limit: 50,
				})
				this.decisions = results || []
			} catch {
				// Decisions collection may not exist yet
				this.decisions = []
			}
		},

		async fetchMilestones() {
			try {
				const results = await this.objectStore.fetchCollection('milestone', {
					'_filters[case]': this.caseId,
					_limit: 50,
				})
				this.milestones = results || []
			} catch {
				// Milestones collection may not exist yet
				this.milestones = []
			}
		},

		// --- Properties Save (from widget) ---
		async onPropertiesSave(formData) {
			const validation = validateCaseUpdate(formData)
			if (!validation.valid) return

			const currentUser = OC?.currentUser || 'unknown'
			const now = new Date().toISOString()

			const activity = [...(this.caseData.activity || [])]
			const changes = []
			if (formData.title !== this.caseData.title) changes.push('title')
			if (formData.description !== (this.caseData.description || '')) changes.push('description')
			if (formData.assignee !== (this.caseData.assignee || '')) changes.push('handler')
			if (formData.priority !== (this.caseData.priority || 'normal')) changes.push('priority')

			if (changes.length > 0) {
				activity.push({
					date: now,
					type: 'update',
					description: t('procest', 'Updated: {fields}', { fields: changes.join(', ') }),
					user: currentUser,
				})
			}

			const updateData = {
				...this.caseData,
				title: formData.title,
				description: formData.description,
				assignee: formData.assignee || null,
				priority: formData.priority,
				activity,
			}

			await this.objectStore.saveObject('case', updateData)
		},

		// --- Status Change (from timeline widget) ---
		async onStatusChange(targetStatus) {
			await this.executeStatusChange(targetStatus)
		},

		async onStatusChangeWithResult({ status, resultName, selectedResultType }) {
			// Create result object if result type was selected
			if (selectedResultType) {
				const resultObj = await this.objectStore.saveObject('result', {
					name: selectedResultType.name,
					case: this.caseId,
					resultType: selectedResultType.id,
				})
				if (resultObj) {
					this.caseResult = resultObj
				}
			}
			await this.executeStatusChange(status, resultName)
		},

		async executeStatusChange(targetStatus, resultText = null) {
			const now = new Date().toISOString()
			const currentUser = OC?.currentUser || 'unknown'

			const statusHistory = [...(this.caseData.statusHistory || [])]
			statusHistory.push({
				status: targetStatus.id,
				date: now,
				changedBy: currentUser,
			})

			const activity = [...(this.caseData.activity || [])]
			activity.push({
				date: now,
				type: 'status_change',
				description: t('procest', 'Status changed from \'{from}\' to \'{to}\'', {
					from: this.currentStatusName,
					to: targetStatus.name,
				}),
				user: currentUser,
			})

			const updateData = {
				...this.caseData,
				status: targetStatus.id,
				statusHistory,
				activity,
			}

			if (targetStatus.isFinal === true || targetStatus.isFinal === 'true') {
				updateData.endDate = now.split('T')[0] + 'T17:00:00Z'
				if (resultText) {
					updateData.result = resultText
				}
			}

			await this.objectStore.saveObject('case', updateData)
		},

		// --- Delete ---
		async confirmDelete() {
			let message = t('procest', 'Are you sure you want to delete this case?')
			if (this.tasks.length > 0) {
				message = t('procest', 'This case has {count} linked tasks. Are you sure you want to delete it?', { count: this.tasks.length })
			}
			if (confirm(message)) {
				const success = await this.objectStore.deleteObject('case', this.caseId)
				if (success) {
					this.$router.push({ name: 'Cases' })
				}
			}
		},

		// --- Extension ---
		showExtensionDialog() {
			this.extensionReason = ''
			this.showExtension = true
		},

		async confirmExtension() {
			const currentUser = OC?.currentUser || 'unknown'
			const now = new Date().toISOString()

			const newDeadline = calculateDeadline(
				this.caseData.deadline,
				this.caseTypeData.extensionPeriod,
			)

			if (!newDeadline) return

			const activity = [...(this.caseData.activity || [])]
			activity.push({
				date: now,
				type: 'extension',
				description: t('procest', 'Deadline extended from {old} to {new}. Reason: {reason}', {
					old: formatDate(this.caseData.deadline),
					new: formatDate(newDeadline.toISOString()),
					reason: this.extensionReason || t('procest', 'No reason provided'),
				}),
				user: currentUser,
			})

			const updateData = {
				...this.caseData,
				deadline: newDeadline.toISOString().split('T')[0] + 'T17:00:00Z',
				extensionCount: (this.caseData.extensionCount || 0) + 1,
				activity,
			}

			const result = await this.objectStore.saveObject('case', updateData)
			if (result) {
				this.showExtension = false
			}
		},

		// --- Handler Changed (from roles widget) ---
		async onHandlerChanged(newAssignee) {
			await this.objectStore.saveObject('case', { ...this.caseData, assignee: newAssignee })
			await this.objectStore.fetchObject('case', this.caseId)
		},

		// --- Activity (from notes widget) ---
		async onAddNote(text) {
			const currentUser = OC?.currentUser || 'unknown'
			const now = new Date().toISOString()

			const activity = [...(this.caseData.activity || [])]
			activity.push({
				date: now,
				type: 'note',
				description: text,
				user: currentUser,
			})

			const updateData = {
				...this.caseData,
				activity,
			}

			await this.objectStore.saveObject('case', updateData)
		},
	},
}
</script>

<style scoped>
.case-detail-dashboard {
	height: 100%;
	display: flex;
	flex-direction: column;
}

.case-detail-header {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 12px 16px;
	border-bottom: 1px solid var(--color-border);
}

.case-detail-header__title {
	flex: 1;
}

.case-detail-header__title h2 {
	margin: 0;
	font-size: 18px;
	line-height: 1.3;
}

.case-detail-header__subtitle {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.case-detail-header__actions {
	display: flex;
	gap: 8px;
}

.case-detail-loading {
	margin: 48px auto;
}

/* Extension dialog */
.extension-overlay {
	position: fixed;
	top: 0;
	left: 0;
	right: 0;
	bottom: 0;
	background: rgba(0, 0, 0, 0.5);
	display: flex;
	align-items: center;
	justify-content: center;
	z-index: 10000;
}

.extension-dialog {
	background: var(--color-main-background);
	border-radius: var(--border-radius-large);
	box-shadow: 0 4px 24px rgba(0, 0, 0, 0.2);
	padding: 24px;
	width: 440px;
	max-width: 90vw;
}

.extension-dialog h3 {
	margin: 0 0 12px;
}

.extension-dialog p {
	color: var(--color-text-maxcontrast);
	margin-bottom: 16px;
}

.extension-dialog textarea {
	width: 100%;
	padding: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	resize: vertical;
}

.extension-dialog__actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 16px;
}

.form-group {
	margin-bottom: 16px;
}

.form-group label {
	display: block;
	margin-bottom: 4px;
	font-weight: bold;
}
</style>
