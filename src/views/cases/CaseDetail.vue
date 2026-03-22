<template>
	<div>
		<CnDetailPage
			:title="caseData.title || t('procest', 'Case')"
			:subtitle="caseData.identifier ? `${t('procest', 'Case')} — ${caseData.identifier}` : t('procest', 'Case')"
			:back-route="{ name: 'Cases' }"
			:back-label="t('procest', 'Back to list')"
			:loading="loading"
			:sidebar="!isNew && !loading"
			object-type="procest_case"
			:object-id="caseId"
			:sidebar-props="sidebarProps">
			<template #header-actions>
				<NcButton
					v-if="!isReadOnly"
					type="primary"
					:disabled="saving"
					@click="save">
					<template v-if="saving">
						<NcLoadingIcon :size="20" />
					</template>
					{{ t('procest', 'Save') }}
				</NcButton>
				<NcButton type="error" @click="confirmDelete">
					{{ t('procest', 'Delete') }}
				</NcButton>
			</template>

			<!-- Status card -->
			<CnDetailCard :title="t('procest', 'Status')">
				<div class="status-section">
					<span class="status-badge" :class="currentStatusBadgeClass">
						{{ currentStatusName }}
					</span>

					<!-- Status change dropdown -->
					<div v-if="!isReadOnly && orderedStatusTypes.length > 0" class="status-section__change">
						<NcSelect
							v-model="selectedStatus"
							:options="orderedStatusTypes"
							label="name"
							track-by="id"
							:placeholder="t('procest', 'Change status...')"
							@input="onStatusSelected" />
					</div>

					<span v-if="caseData.endDate" class="status-section__closed-info">
						{{ t('procest', 'Closed on {date}', { date: formatDate(caseData.endDate) }) }}
					</span>
				</div>

				<!-- Result prompt (shown when final status selected) -->
				<div v-if="showResultPrompt" class="result-prompt">
					<template v-if="resultTypes.length > 0">
						<NcSelect
							v-model="selectedResultType"
							:options="resultTypes"
							label="name"
							track-by="id"
							:placeholder="t('procest', 'Select result type...')" />
					</template>
					<template v-else>
						<NcTextField
							:value="resultText"
							:label="t('procest', 'Result (required)')"
							:error="!!resultError"
							@update:value="v => { resultText = v; resultError = '' }" />
					</template>
					<p v-if="resultError" class="form-error">
						{{ resultError }}
					</p>
					<div class="result-prompt__actions">
						<NcButton type="primary" @click="confirmStatusChange">
							{{ t('procest', 'Confirm') }}
						</NcButton>
						<NcButton @click="cancelStatusChange">
							{{ t('procest', 'Cancel') }}
						</NcButton>
					</div>
				</div>
			</CnDetailCard>

			<!-- Status Timeline card -->
			<CnDetailCard v-if="orderedStatusTypes.length > 0" :title="t('procest', 'Status Timeline')">
				<StatusTimeline
					:status-types="orderedStatusTypes"
					:current-status-id="caseData.status"
					:status-history="caseData.statusHistory || []" />
			</CnDetailCard>

			<!-- Case Information card -->
			<CnDetailCard :title="t('procest', 'Case Information')">
				<div class="form-group">
					<label>{{ t('procest', 'Title') }} *</label>
					<NcTextField
						:value="form.title"
						:disabled="isReadOnly"
						:error="!!validationErrors.title"
						@update:value="v => { form.title = v; validationErrors.title = '' }" />
					<p v-if="validationErrors.title" class="form-error">
						{{ validationErrors.title }}
					</p>
				</div>

				<div class="form-group">
					<label>{{ t('procest', 'Description') }}</label>
					<textarea
						v-model="form.description"
						:disabled="isReadOnly"
						rows="3" />
				</div>

				<div class="form-row">
					<div class="form-group">
						<label>{{ t('procest', 'Case type') }}</label>
						<span class="form-value">{{ caseTypeName }}</span>
					</div>
					<div class="form-group">
						<label>{{ t('procest', 'Identifier') }}</label>
						<span class="form-value">{{ caseData.identifier || '—' }}</span>
					</div>
				</div>

				<div class="form-row">
					<div class="form-group">
						<label>{{ t('procest', 'Priority') }}</label>
						<NcSelect
							v-model="form.priority"
							:options="priorityOptions"
							:disabled="isReadOnly" />
					</div>
					<div class="form-group">
						<label>{{ t('procest', 'Confidentiality') }}</label>
						<span class="form-value">{{ caseData.confidentiality || '—' }}</span>
					</div>
				</div>

				<div class="form-row">
					<div class="form-group">
						<label>{{ t('procest', 'Handler') }}</label>
						<NcTextField
							:value="form.assignee"
							:disabled="isReadOnly"
							:placeholder="t('procest', 'Assign handler...')"
							@update:value="v => form.assignee = v" />
					</div>
					<div class="form-group">
						<label>{{ t('procest', 'Start date') }}</label>
						<span class="form-value">{{ formatDate(caseData.startDate) }}</span>
					</div>
				</div>

				<ResultSection
					:result="caseResult"
					:result-types="resultTypes"
					:show-empty="isAtFinalStatus && !caseResult" />

				<div v-if="!caseResult && caseData.result" class="form-group">
					<label>{{ t('procest', 'Result') }}</label>
					<span class="form-value">{{ caseData.result }}</span>
				</div>
			</CnDetailCard>

			<!-- Deadline & Timing card -->
			<CnDetailCard v-if="caseTypeData" :title="t('procest', 'Deadline & Timing')">
				<DeadlinePanel
					:start-date="caseData.startDate"
					:deadline="caseData.deadline"
					:processing-deadline="caseTypeData.processingDeadline"
					:extension-allowed="caseTypeData.extensionAllowed === true || caseTypeData.extensionAllowed === 'true'"
					:extension-period="caseTypeData.extensionPeriod"
					:extension-count="caseData.extensionCount || 0"
					:is-final="isAtFinalStatus"
					@extend="showExtensionDialog" />
			</CnDetailCard>

			<!-- Participants card -->
			<CnDetailCard :title="t('procest', 'Participants')">
				<ParticipantsSection
					:case-id="caseId"
					:is-read-only="isReadOnly"
					@handler-changed="onHandlerChanged" />
			</CnDetailCard>

			<!-- Tasks card -->
			<CnDetailCard :title="`${t('procest', 'Tasks')} (${completedTaskCount}/${tasks.length})`">
				<template #actions>
					<NcButton v-if="!isReadOnly" @click="$router.push({ name: 'TaskNew', query: { caseId } })">
						{{ t('procest', 'New task') }}
					</NcButton>
				</template>

				<div v-if="tasks.length === 0" class="section-empty">
					{{ t('procest', 'No tasks yet') }}
				</div>
				<div v-else class="viewTableContainer">
					<table class="viewTable">
						<thead>
							<tr>
								<th>{{ t('procest', 'Title') }}</th>
								<th>{{ t('procest', 'Status') }}</th>
								<th>{{ t('procest', 'Assignee') }}</th>
								<th>{{ t('procest', 'Due date') }}</th>
								<th>{{ t('procest', 'Priority') }}</th>
							</tr>
						</thead>
						<tbody>
							<tr
								v-for="task in sortedTasks"
								:key="task.id"
								class="viewTableRow"
								:class="{ 'viewTableRow--overdue': isOverdue(task) }"
								@click="$router.push({ name: 'TaskDetail', params: { id: task.id } })">
								<td>{{ task.title || '—' }}</td>
								<td>
									<span class="status-badge" :class="'status-badge--' + task.status">
										{{ getTaskStatusLabel(task.status) }}
									</span>
								</td>
								<td>{{ task.assignee || '—' }}</td>
								<td :class="dueDateClass(task)">
									<template v-if="isOverdue(task)">
										{{ getOverdueText(task) }}
									</template>
									<template v-else-if="isDueToday(task)">
										{{ t('procest', 'Due today') }}
									</template>
									<template v-else>
										{{ formatDueDate(task.dueDate) }}
									</template>
								</td>
								<td>
									<span
										v-if="task.priority && task.priority !== 'normal'"
										class="priority-badge"
										:class="'priority-badge--' + task.priority">
										{{ getTaskPriorityLabel(task.priority) }}
									</span>
									<span v-else>—</span>
								</td>
							</tr>
						</tbody>
					</table>
				</div>
			</CnDetailCard>

			<!-- VTH Panels (conditionally shown based on case type) -->
			<InspectionPanel
				v-if="isToezichtCase"
				:case-id="caseId"
				:case-type-id="caseData.caseType"
				:can-inspect="!isReadOnly" />

			<EnforcementPanel
				v-if="isHandhavingCase"
				:case-id="caseId"
				:case-type-id="caseData.caseType"
				:is-read-only="isReadOnly" />

			<AdvicePanel
				v-if="isVthCase"
				:case-id="caseId"
				:is-read-only="isReadOnly" />

			<!-- Activity card -->
			<CnDetailCard :title="t('procest', 'Activity')">
				<ActivityTimeline
					:activity="caseData.activity || []"
					:is-read-only="isReadOnly"
					@add-note="onAddNote" />
			</CnDetailCard>
		</CnDetailPage>

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
import { NcButton, NcLoadingIcon, NcTextField, NcSelect } from '@nextcloud/vue'
import { CnDetailPage, CnDetailCard } from '@conduction/nextcloud-vue'
import { useObjectStore } from '../../store/modules/object.js'
import { getStatusLabel as getTaskStatusLabel } from '../../utils/taskLifecycle.js'
import { isOverdue, isDueToday, getOverdueText, formatDueDate, sortTasks, getPriorityLevels } from '../../utils/taskHelpers.js'
import { calculateDeadline, formatDate, formatDuration } from '../../utils/caseHelpers.js'
import { validateCaseUpdate } from '../../utils/caseValidation.js'
import StatusTimeline from './components/StatusTimeline.vue'
import DeadlinePanel from './components/DeadlinePanel.vue'
import ActivityTimeline from './components/ActivityTimeline.vue'
import ParticipantsSection from './components/ParticipantsSection.vue'
import ResultSection from './components/ResultSection.vue'
import InspectionPanel from './components/InspectionPanel.vue'
import EnforcementPanel from './components/EnforcementPanel.vue'
import AdvicePanel from './components/AdvicePanel.vue'

export default {
	name: 'CaseDetail',
	components: {
		NcButton,
		NcLoadingIcon,
		NcTextField,
		NcSelect,
		CnDetailPage,
		CnDetailCard,
		StatusTimeline,
		DeadlinePanel,
		ActivityTimeline,
		ParticipantsSection,
		ResultSection,
		InspectionPanel,
		EnforcementPanel,
		AdvicePanel,
	},
	props: {
		caseId: {
			type: String,
			default: null,
		},
	},
	data() {
		return {
			form: {
				title: '',
				description: '',
				assignee: '',
				priority: 'normal',
			},
			validationErrors: {},
			saving: false,
			tasks: [],
			statusTypes: [],
			caseTypeData: null,
			// Status change state
			selectedStatus: null,
			pendingStatusChange: null,
			showResultPrompt: false,
			resultText: '',
			resultError: '',
			resultTypes: [],
			selectedResultType: null,
			caseResult: null,
			// Extension state
			showExtension: false,
			extensionReason: '',
			priorityOptions: ['low', 'normal', 'high', 'urgent'],
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
			return this.caseTypeData?.title || '—'
		},
		orderedStatusTypes() {
			return [...this.statusTypes].sort((a, b) => (a.order || 0) - (b.order || 0))
		},
		currentStatusType() {
			if (!this.caseData.status) return null
			return this.statusTypes.find(st => st.id === this.caseData.status) || null
		},
		currentStatusName() {
			return this.currentStatusType?.name || '—'
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
		/**
		 * Detect if this is a VTH case type (Vergunningen, Toezicht, or Handhaving).
		 *
		 * @return {boolean} True if VTH case type
		 */
		isVthCase() {
			const title = (this.caseTypeData?.title || '').toLowerCase()
			return this.isToezichtCase
				|| this.isHandhavingCase
				|| title.includes('omgevingsvergunning')
				|| title.includes('sloopmelding')
				|| title.includes('milieumelding')
				|| title.includes('gebruiksmelding')
		},
		/**
		 * Detect if this is a Toezicht (supervision) case type.
		 *
		 * @return {boolean} True if Toezicht case type
		 */
		isToezichtCase() {
			const title = (this.caseTypeData?.title || '').toLowerCase()
			return title.includes('toezicht')
		},
		/**
		 * Detect if this is a Handhaving (enforcement) case type.
		 *
		 * @return {boolean} True if Handhaving case type
		 */
		isHandhavingCase() {
			const title = (this.caseTypeData?.title || '').toLowerCase()
			return title.includes('handhaving') || title.includes('invorderin')
		},
		sortedTasks() {
			return sortTasks(this.tasks)
		},
		completedTaskCount() {
			return this.tasks.filter(t => t.status === 'completed').length
		},
		extensionPeriodText() {
			if (!this.caseTypeData?.extensionPeriod) return ''
			return formatDuration(this.caseTypeData.extensionPeriod)
		},
		sidebarProps() {
			const config = this.objectStore.objectTypeRegistry.case || {}
			return {
				register: config.register || '',
				schema: config.schema || '',
			}
		},
	},
	async mounted() {
		if (!this.isNew) {
			await this.objectStore.fetchObject('case', this.caseId)
			this.populateForm()
			await Promise.all([
				this.loadCaseTypeData(),
				this.fetchTasks(),
				this.fetchCaseResult(),
			])
		}
	},
	methods: {
		isOverdue,
		isDueToday,
		getOverdueText,
		formatDueDate,
		formatDate,
		getTaskStatusLabel,

		getTaskPriorityLabel(priority) {
			return getPriorityLevels()[priority]?.label || priority
		},

		dueDateClass(task) {
			if (isOverdue(task)) return 'due-date--overdue'
			if (isDueToday(task)) return 'due-date--today'
			return ''
		},

		populateForm() {
			const data = this.caseData
			this.form = {
				title: data.title || '',
				description: data.description || '',
				assignee: data.assignee || '',
				priority: data.priority || 'normal',
			}
		},

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

		// --- Status Change ---
		onStatusSelected(status) {
			if (!status || status.id === this.caseData.status) {
				this.selectedStatus = null
				return
			}

			if (status.isFinal === true || status.isFinal === 'true') {
				this.pendingStatusChange = status
				this.showResultPrompt = true
				this.resultText = ''
				this.resultError = ''
			} else {
				this.executeStatusChange(status)
			}
		},

		async confirmStatusChange() {
			let resultName = ''

			if (this.resultTypes.length > 0) {
				if (!this.selectedResultType) {
					this.resultError = t('procest', 'Please select a result type')
					return
				}
				// Create a result object.
				const resultObj = await this.objectStore.saveObject('result', {
					name: this.selectedResultType.name,
					case: this.caseId,
					resultType: this.selectedResultType.id,
				})
				if (resultObj) {
					this.caseResult = resultObj
				}
				resultName = this.selectedResultType.name
			} else {
				if (!this.resultText.trim()) {
					this.resultError = t('procest', 'Result is required when closing a case')
					return
				}
				resultName = this.resultText.trim()
			}

			await this.executeStatusChange(this.pendingStatusChange, resultName)
			this.showResultPrompt = false
			this.pendingStatusChange = null
			this.resultText = ''
			this.selectedResultType = null
		},

		cancelStatusChange() {
			this.showResultPrompt = false
			this.pendingStatusChange = null
			this.selectedStatus = null
			this.resultText = ''
			this.resultError = ''
			this.selectedResultType = null
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

			const result = await this.objectStore.saveObject('case', updateData)
			if (result) {
				this.selectedStatus = null
				this.populateForm()
			}
		},

		// --- Save ---
		async save() {
			const validation = validateCaseUpdate(this.form)
			if (!validation.valid) {
				this.validationErrors = validation.errors
				return
			}

			this.saving = true
			const currentUser = OC?.currentUser || 'unknown'
			const now = new Date().toISOString()

			const activity = [...(this.caseData.activity || [])]

			// Track field changes
			const changes = []
			if (this.form.title !== this.caseData.title) changes.push('title')
			if (this.form.description !== (this.caseData.description || '')) changes.push('description')
			if (this.form.assignee !== (this.caseData.assignee || '')) changes.push('handler')
			if (this.form.priority !== (this.caseData.priority || 'normal')) changes.push('priority')

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
				title: this.form.title,
				description: this.form.description,
				assignee: this.form.assignee || null,
				priority: this.form.priority,
				activity,
			}

			const result = await this.objectStore.saveObject('case', updateData)
			this.saving = false

			if (result) {
				this.populateForm()
			}
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

		// --- Handler Changed ---
		async onHandlerChanged(newAssignee) {
			this.form.assignee = newAssignee
			// Persist the assignee to the backend.
			await this.objectStore.saveObject('case', { ...this.caseData, assignee: newAssignee })
			await this.objectStore.fetchObject('case', this.caseId)
		},

		// --- Activity ---
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
/* Status section */
.status-section {
	display: flex;
	align-items: center;
	gap: 12px;
	flex-wrap: wrap;
}

.status-section__change {
	min-width: 200px;
}

.status-section__closed-info {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
	margin-left: auto;
}

/* Result prompt */
.result-prompt {
	margin-top: 12px;
	padding-top: 12px;
	border-top: 1px solid var(--color-border);
}

.result-prompt__actions {
	display: flex;
	gap: 8px;
	margin-top: 8px;
}

/* Form styles */
.form-group {
	margin-bottom: 16px;
}

.form-group label {
	display: block;
	margin-bottom: 4px;
	font-weight: bold;
}

.form-group textarea {
	width: 100%;
	padding: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	resize: vertical;
}

.form-group textarea:disabled {
	opacity: 0.6;
	cursor: not-allowed;
}

.form-value {
	display: block;
	padding: 6px 0;
	color: var(--color-main-text);
}

.form-row {
	display: flex;
	gap: 16px;
}

.form-row .form-group {
	flex: 1;
}

.form-error {
	color: var(--color-error);
	font-size: 13px;
	margin-top: 4px;
}

/* Status badges */
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

.status-badge--available {
	background: var(--color-background-dark);
	color: var(--color-main-text);
}

.status-badge--completed {
	background: var(--color-success);
	color: white;
}

.status-badge--terminated {
	background: var(--color-error);
	color: white;
}

.status-badge--disabled {
	background: var(--color-text-maxcontrast);
	color: white;
}

/* Priority badges */
.priority-badge {
	display: inline-block;
	padding: 2px 8px;
	border-radius: var(--border-radius-pill);
	font-size: 12px;
	font-weight: 500;
}

.priority-badge--urgent {
	background: var(--color-error);
	color: white;
}

.priority-badge--high {
	background: var(--color-warning);
	color: var(--color-warning-text);
}

.priority-badge--low {
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}

/* Due date styles */
.due-date--overdue {
	color: var(--color-error);
	font-weight: 500;
}

.due-date--today {
	color: var(--color-warning);
	font-weight: 500;
}

/* Tasks table */
.section-empty {
	text-align: center;
	color: var(--color-text-maxcontrast);
	padding: 16px;
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

.viewTableRow--overdue {
	border-left: 3px solid var(--color-error);
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
</style>
