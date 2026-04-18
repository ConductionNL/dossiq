<template>
	<div>
		<!-- Case not found state -->
		<div v-if="notFound" class="case-not-found">
			<NcEmptyContent :title="t('procest', 'Case not found')">
				<template #icon>
					<span class="icon-alert"></span>
				</template>
				<NcButton type="primary" @click="$router.push({ name: 'Cases' })">
					{{ t('procest', 'Back to list') }}
				</NcButton>
			</NcEmptyContent>
		</div>

		<!-- Parent case breadcrumb -->
		<div v-if="!notFound && parentCaseData" class="parent-breadcrumb">
			<router-link :to="{ name: 'CaseDetail', params: { id: caseData.parentCase } }" class="parent-breadcrumb__link">
				{{ parentCaseData.title }}
			</router-link>
			<span class="parent-breadcrumb__separator">&gt;</span>
			<span class="parent-breadcrumb__current">{{ caseData.title }}</span>
		</div>

		<CnDetailPage
			v-if="!notFound"
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

			<!-- Skeleton loading for Status card -->
			<div v-if="loading" class="skeleton-card">
				<div class="skeleton-bar skeleton-bar--title"></div>
				<div class="skeleton-group">
					<div class="skeleton-bar skeleton-bar--text"></div>
					<div class="skeleton-bar skeleton-bar--short"></div>
				</div>
			</div>

			<!-- Status card -->
			<CnDetailCard v-if="!loading" :title="t('procest', 'Status')">
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

			<!-- Skeleton loading for Status Timeline -->
			<div v-if="loading" class="skeleton-card">
				<div class="skeleton-bar skeleton-bar--title"></div>
				<div class="skeleton-group">
					<div class="skeleton-bar skeleton-bar--text"></div>
					<div class="skeleton-bar skeleton-bar--text"></div>
					<div class="skeleton-bar skeleton-bar--short"></div>
				</div>
			</div>

			<!-- Status Timeline card -->
			<CnDetailCard v-if="!loading && orderedStatusTypes.length > 0" :title="t('procest', 'Status Timeline')">
				<StatusTimeline
					:status-types="orderedStatusTypes"
					:current-status-id="caseData.status"
					:status-history="caseData.statusHistory || []" />
			</CnDetailCard>

			<!-- Workflow Transitions card -->
			<CnDetailCard v-if="!loading && hasWorkflow" :title="t('procest', 'Workflow Transitions')">
				<WorkflowTransitions
					:case-data="caseData"
					:tasks="tasks"
					:documents="caseDocuments"
					:user-roles="userRoleTypeIds"
					@transition-executed="onWorkflowTransition" />
			</CnDetailCard>

			<!-- Skeleton loading for Case Information -->
			<div v-if="loading" class="skeleton-card">
				<div class="skeleton-bar skeleton-bar--title"></div>
				<div class="skeleton-group">
					<div class="skeleton-bar skeleton-bar--text"></div>
					<div class="skeleton-bar skeleton-bar--text"></div>
				</div>
				<div class="skeleton-row">
					<div class="skeleton-group">
						<div class="skeleton-bar skeleton-bar--text"></div>
					</div>
					<div class="skeleton-group">
						<div class="skeleton-bar skeleton-bar--text"></div>
					</div>
				</div>
			</div>

			<!-- Case Information card -->
			<CnDetailCard v-if="!loading" :title="t('procest', 'Case Information')">
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

			<!-- Skeleton loading for Deadline & Timing -->
			<div v-if="loading" class="skeleton-card">
				<div class="skeleton-bar skeleton-bar--title"></div>
				<div class="skeleton-group">
					<div class="skeleton-bar skeleton-bar--text"></div>
					<div class="skeleton-bar skeleton-bar--text"></div>
				</div>
			</div>

			<!-- Deadline & Timing card -->
			<CnDetailCard v-if="!loading && caseTypeData" :title="t('procest', 'Deadline & Timing')">
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

			<!-- B&W Voorstellen card -->
			<CnDetailCard v-if="!loading" :title="t('procest', 'B&W Voorstellen')">
				<VoorstellenPanel
					:case-id="caseId"
					:case-title="caseData.title || ''"
					:is-read-only="isReadOnly" />
			</CnDetailCard>

			<!-- Participants card -->
			<CnDetailCard v-if="!loading" :title="t('procest', 'Participants')">
				<ParticipantsSection
					:case-id="caseId"
					:is-read-only="isReadOnly"
					@handler-changed="onHandlerChanged" />
			</CnDetailCard>

			<!-- Sub-cases card -->
			<CnDetailCard
				v-if="!loading && hasSubCaseTypes"
				:title="subCasesSectionTitle">
				<SubCasesSection
					:case-id="caseId"
					:parent-case="caseData.parentCase || null"
					:end-date="caseData.endDate || null"
					:sub-case-types="subCaseTypesArray"
					@create-sub-case="showSubCaseDialog = true"
					@sub-cases-loaded="onSubCasesLoaded" />
			</CnDetailCard>

			<!-- Sub-case creation dialog -->
			<CaseCreateDialog
				v-if="!loading && showSubCaseDialog"
				:parent-case="caseId"
				:parent-case-type="caseTypeData"
				@created="onSubCaseCreated"
				@close="showSubCaseDialog = false" />

			<!-- Tasks card -->
			<CnDetailCard v-if="!loading" :title="`${t('procest', 'Tasks')} (${completedTaskCount}/${tasks.length})`">
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
				v-if="!loading && isToezichtCase"
				:case-id="caseId"
				:case-type-id="caseData.caseType"
				:can-inspect="!isReadOnly" />

			<EnforcementPanel
				v-if="!loading && isHandhavingCase"
				:case-id="caseId"
				:case-type-id="caseData.caseType"
				:is-read-only="isReadOnly" />

			<AdvicePanel
				v-if="!loading && isVthCase"
				:case-id="caseId"
				:is-read-only="isReadOnly" />
			<!-- Location card -->
			<CnDetailCard v-if="!loading" :title="t('procest', 'Location')">
				<LocationTab
					:geometry="caseData.geometry || null"
					:is-read-only="isReadOnly"
					@update-geometry="onGeometryUpdate" />
			</CnDetailCard>
			<!-- Bezwaar-specific sections (shown when case type is Bezwaar) -->
			<template v-if="!loading && isBezwaarCase">
				<CnDetailCard :title="t('procest', 'Objection Details')">
					<BezwaarIntakeForm
						:case-id="caseId"
						:case-data="caseData"
						:is-read-only="isReadOnly"
						:besluit-date="contestedBesluitDate"
						@saved="onBezwaarDataChanged"
						@deadlines-calculated="onDeadlinesCalculated" />
				</CnDetailCard>

				<CnDetailCard :title="t('procest', 'Bezwaar Deadlines')">
					<div class="bezwaar-deadlines">
						<DeadlineIndicator
							v-if="bezwaarDeadlines.afhandelDeadline"
							:deadline="bezwaarDeadlines.afhandelDeadline"
							:label="t('procest', 'Processing deadline')" />
						<DeadlineIndicator
							v-if="bezwaarDeadlines.ontvangstbevestigingDeadline"
							:deadline="bezwaarDeadlines.ontvangstbevestigingDeadline"
							:label="t('procest', 'Acknowledgment deadline')" />
					</div>
				</CnDetailCard>

				<CnDetailCard :title="t('procest', 'Hearing (Hoorzitting)')">
					<HearingPanel
						:case-id="caseId"
						:is-read-only="isReadOnly"
						@hearing-scheduled="onBezwaarDataChanged"
						@hearing-completed="onBezwaarDataChanged"
						@hearing-waived="onBezwaarDataChanged" />
				</CnDetailCard>

				<CnDetailCard :title="t('procest', 'Advisory Committee')">
					<AdvisoryReportPanel
						:case-id="caseId"
						:is-read-only="isReadOnly"
						@saved="onBezwaarDataChanged" />
				</CnDetailCard>

				<CnDetailCard :title="t('procest', 'Decision on Objection')">
					<BezwaarDecisionForm
						:case-id="caseId"
						:is-read-only="isReadOnly"
						:contested-decision-id="contestedDecisionId"
						@saved="onBezwaarDataChanged" />
				</CnDetailCard>

				<CnDetailCard :title="t('procest', 'Bezwaar Timeline')">
					<BezwaarTimeline
						:case-data="caseData"
						:deadlines="bezwaarDeadlines" />
				</CnDetailCard>

				<CnDetailCard
					v-if="canEscalateToBeroep"
					:title="t('procest', 'Appeal (Beroep)')">
					<BeroepEscalationPanel
						:case-data="caseData"
						:can-escalate="canEscalateToBeroep"
						:is-read-only="isReadOnly"
						@escalated="onBeroepCreated" />
				</CnDetailCard>
			</template>

			<!-- Beroep-specific sections (shown when case type is Beroep) -->
			<template v-if="!loading && isBeroepCase">
				<CnDetailCard :title="t('procest', 'Court Proceedings')">
					<CourtProceedingsPanel
						:case-data="caseData"
						:parent-case="parentBezwaarCase"
						:is-read-only="isReadOnly"
						:show-ruling-form="showRulingForm"
						@ruling-recorded="onBezwaarDataChanged" />
				</CnDetailCard>
			</template>

			<!-- Activity card -->
			<CnDetailCard v-if="!loading" :title="t('procest', 'Activity')">
				<ActivityTimeline
					:activity="caseData.activity || []"
					:is-read-only="isReadOnly"
					@add-note="onAddNote" />
			</CnDetailCard>
		</CnDetailPage>

		<!-- Extension dialog -->
		<div v-if="!loading && showExtension" class="extension-overlay" @click.self="showExtension = false">
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
import { NcButton, NcLoadingIcon, NcTextField, NcSelect, NcEmptyContent } from '@nextcloud/vue'
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
import SubCasesSection from './components/SubCasesSection.vue'
import CaseCreateDialog from './CaseCreateDialog.vue'
import InspectionPanel from './components/InspectionPanel.vue'
import EnforcementPanel from './components/EnforcementPanel.vue'
import AdvicePanel from './components/AdvicePanel.vue'
import VoorstellenPanel from './components/VoorstellenPanel.vue'
import WorkflowTransitions from './components/WorkflowTransitions.vue'
import BezwaarIntakeForm from './components/bezwaar/BezwaarIntakeForm.vue'
import HearingPanel from './components/bezwaar/HearingPanel.vue'
import AdvisoryReportPanel from './components/bezwaar/AdvisoryReportPanel.vue'
import BezwaarDecisionForm from './components/bezwaar/BezwaarDecisionForm.vue'
import BezwaarTimeline from './components/bezwaar/BezwaarTimeline.vue'
import DeadlineIndicator from './components/bezwaar/DeadlineIndicator.vue'
import BeroepEscalationPanel from './components/beroep/BeroepEscalationPanel.vue'
import CourtProceedingsPanel from './components/beroep/CourtProceedingsPanel.vue'
import { useBezwaarStore } from '../../store/modules/bezwaar.js'

const LocationTab = () => import(/* webpackChunkName: "map" */ './components/LocationTab.vue')

export default {
	name: 'CaseDetail',
	components: {
		NcButton,
		NcLoadingIcon,
		NcTextField,
		NcSelect,
		NcEmptyContent,
		CnDetailPage,
		CnDetailCard,
		StatusTimeline,
		DeadlinePanel,
		ActivityTimeline,
		ParticipantsSection,
		ResultSection,
		SubCasesSection,
		CaseCreateDialog,
		InspectionPanel,
		EnforcementPanel,
		AdvicePanel,
		LocationTab,
		VoorstellenPanel,
		WorkflowTransitions,
		BezwaarIntakeForm,
		HearingPanel,
		AdvisoryReportPanel,
		BezwaarDecisionForm,
		BezwaarTimeline,
		DeadlineIndicator,
		BeroepEscalationPanel,
		CourtProceedingsPanel,
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
			notFound: false,
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
			caseRoles: [],
			caseDocuments: [],
			priorityOptions: ['low', 'normal', 'high', 'urgent'],
			// Sub-case state
			showSubCaseDialog: false,
			parentCaseData: null,
			subCases: [],
			// Bezwaar state
			bezwaarDeadlines: {},
			contestedBesluitDate: '',
			contestedDecisionId: '',
			parentBezwaarCase: null,
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
		hasSubCaseTypes() {
			return this.subCaseTypesArray.length > 0
		},
		subCaseTypesArray() {
			if (!this.caseTypeData) return []
			const types = this.caseTypeData.subCaseTypes
			if (!types || !Array.isArray(types) || types.length === 0) return []
			return types
		},
		subCasesSectionTitle() {
			if (this.subCases.length === 0) {
				return t('procest', 'Sub-cases')
			}
			const completed = this.subCases.filter(sc => sc.endDate).length
			return t('procest', 'Sub-cases ({completed}/{total} completed)', {
				completed,
				total: this.subCases.length,
			})
		},
		sidebarProps() {
			const config = this.objectStore.objectTypeRegistry.case || {}
			return {
				title: t('procest', 'Case'),
				register: config.register || '',
				schema: config.schema || '',
			}
		},
		hasWorkflow() {
			return !!(this.caseData.workflowTemplate || this.caseData.workflowVersion)
		},
		isBezwaarCase() {
			return this.caseTypeData?.identifier === 'bezwaar'
		},
		isBeroepCase() {
			return this.caseTypeData?.identifier === 'beroep'
		},
		canEscalateToBeroep() {
			if (!this.isBezwaarCase) return false
			const statusName = this.currentStatusType?.name || ''
			return statusName === 'Beslissing op bezwaar' || statusName === 'Afgehandeld'
		},
		showRulingForm() {
			if (!this.isBeroepCase) return false
			const statusName = this.currentStatusType?.name || ''
			return statusName === 'Zitting afgerond'
		},
		userRoleTypeIds() {
			return this.caseRoles ? this.caseRoles.map(r => r.roleType) : []
		},
	},
	async mounted() {
		if (!this.isNew) {
			await this.objectStore.fetchObject('case', this.caseId)

			// Check if case was found
			if (!this.caseData || !this.caseData.id) {
				this.notFound = true
				return
			}

			this.populateForm()
			await Promise.all([
				this.loadCaseTypeData(),
				this.fetchTasks(),
				this.fetchCaseResult(),
				this.fetchParentCase(),
			])

			// Load bezwaar/beroep data if applicable.
			if (this.isBezwaarCase || this.isBeroepCase) {
				await this.loadBezwaarBeroepData()
			}
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

		async loadBezwaarBeroepData() {
			const bezwaarStore = useBezwaarStore()
			await bezwaarStore.loadBezwaarData(this.caseId)

			// Load parent bezwaar case for beroep cases.
			if (this.isBeroepCase && this.caseData.parentCase) {
				this.parentBezwaarCase = await this.objectStore.fetchObject('case', this.caseData.parentCase)
			}
		},

		onDeadlinesCalculated(deadlines) {
			this.bezwaarDeadlines = deadlines
		},

		async onBezwaarDataChanged() {
			const bezwaarStore = useBezwaarStore()
			await bezwaarStore.loadBezwaarData(this.caseId)
		},

		async onBeroepCreated(beroepCase) {
			if (beroepCase) {
				this.$router.push({
					name: 'CaseDetail',
					params: { id: beroepCase.id },
				})
			}
		},

		async onWorkflowTransition({ transition, newStatus }) {
			await this.objectStore.fetchObject('case', this.caseId)
			this.populateForm()
			await this.fetchTasks()
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

		// --- Parent Case ---
		async fetchParentCase() {
			const parentCaseId = this.caseData.parentCase
			if (!parentCaseId) {
				this.parentCaseData = null
				return
			}
			try {
				const parentCase = await this.objectStore.fetchObject('case', parentCaseId)
				this.parentCaseData = parentCase || null
			} catch {
				this.parentCaseData = null
			}
		},

		// --- Sub-cases ---
		onSubCasesLoaded(subCases) {
			this.subCases = subCases || []
		},

		onSubCaseCreated(caseId) {
			this.showSubCaseDialog = false
			this.$router.push({ name: 'CaseDetail', params: { id: caseId } })
		},

		// --- Delete ---
		async confirmDelete() {
			let message = t('procest', 'Are you sure you want to delete this case?')

			if (this.subCases.length > 0) {
				message = t('procest', 'This case has {count} sub-cases. Deleting it will detach them from their parent. Continue?', { count: this.subCases.length })
			} else if (this.tasks.length > 0) {
				message = t('procest', 'This case has {count} linked tasks. Are you sure you want to delete it?', { count: this.tasks.length })
			}

			if (confirm(message)) {
				// Orphan cleanup: clear parentCase on all sub-cases before deleting
				if (this.subCases.length > 0) {
					for (const subCase of this.subCases) {
						await this.objectStore.saveObject('case', {
							...subCase,
							parentCase: null,
						})
					}
				}

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

		// --- Location ---
		async onGeometryUpdate(geometry) {
			const updateData = {
				...this.caseData,
				geometry: typeof geometry === 'string' ? geometry : JSON.stringify(geometry),
			}
			await this.objectStore.saveObject('case', updateData)
			this.caseData.geometry = updateData.geometry
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
/* Parent breadcrumb */
.parent-breadcrumb {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 8px 16px;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.parent-breadcrumb__link {
	color: var(--color-primary-element);
	text-decoration: none;
}

.parent-breadcrumb__link:hover {
	text-decoration: underline;
}

.parent-breadcrumb__separator {
	color: var(--color-text-maxcontrast);
}

.parent-breadcrumb__current {
	color: var(--color-main-text);
}

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

/* Case not found state */
.case-not-found {
	display: flex;
	align-items: center;
	justify-content: center;
	min-height: 500px;
	padding: 24px;
}

.case-not-found :deep(.nc-empty-content) {
	width: 100%;
}

/* Responsive layout — tablet and below */
@media screen and (max-width: 1200px) {
	/* CnDetailPage with sidebar becomes single column */
	:deep(.cn-detail-page) {
		display: flex;
		flex-direction: column;
	}

	:deep(.cn-detail-page__main) {
		flex: 1;
		width: 100%;
	}

	:deep(.cn-detail-page__sidebar) {
		width: 100%;
		border-left: none;
		border-top: 1px solid var(--color-border);
		margin-top: 24px;
	}

	/* Form row — stack on smaller screens */
	.form-row {
		flex-direction: column;
	}

	.form-row .form-group {
		flex: 1;
		margin-bottom: 12px;
	}

	/* Extension dialog — adjust for mobile */
	.extension-dialog {
		width: 95vw;
		max-width: 95vw;
		padding: 16px;
	}

	/* Table — horizontal scroll on mobile */
	.viewTableContainer {
		overflow-x: auto;
	}

	/* Touch targets — ensure 44x44px minimum */
	:deep(.nc-button) {
		min-height: 44px;
		min-width: 44px;
		padding: 6px 12px;
	}

	/* Status badge - ensure readability on mobile */
	.status-badge {
		display: inline-flex;
		align-items: center;
		height: 32px;
		padding: 0 12px;
		font-size: 13px;
	}
}

/* Print view */
@media print {
	/* Hide interactive elements */
	:deep(.cn-detail-page__header),
	:deep(.cn-detail-page__actions),
	.parent-breadcrumb,
	:deep(.nc-action-button),
	:deep(.nc-button),
	:deep(.nc-select),
	:deep(.nc-text-field),
	textarea,
	.result-prompt,
	.status-section__change,
	.extension-dialog,
	.extension-overlay,
	:deep(.cn-detail-page__sidebar) {
		display: none !important;
	}

	/* Print-safe colors and background */
	body {
		background: white;
		color: black;
	}

	:deep(.cn-detail-page),
	:deep(.cn-detail-card) {
		box-shadow: none;
		border: 1px solid #333;
		page-break-inside: avoid;
	}

	/* Print header with case info */
	:deep(.cn-detail-page) {
		padding: 24px;
	}

	/* Add case info header for print */
	:deep(.cn-detail-page)::before {
		content: attr(data-case-identifier);
		display: block;
		font-size: 14px;
		font-weight: bold;
		margin-bottom: 12px;
		border-bottom: 2px solid #333;
		padding-bottom: 12px;
	}

	/* Status timeline in print — text list instead of interactive */
	:deep(.status-timeline) {
		display: block;
	}

	:deep(.status-timeline__item) {
		page-break-inside: avoid;
		margin-bottom: 12px;
		padding-bottom: 12px;
		border-bottom: 1px solid #ccc;
	}

	/* Form values — make them print-safe */
	.form-value {
		color: black;
		font-weight: normal;
	}

	/* Remove interactive styling */
	:deep(.nc-select),
	:deep(.nc-text-field),
	textarea,
	input {
		display: none !important;
	}

	/* Show text-based content only */
	.status-badge {
		display: inline;
		padding: 0;
		background: none !important;
		color: black;
		border: 1px solid #333;
		padding: 2px 4px;
	}

	/* Table print styles */
	.viewTable {
		width: 100%;
		border-collapse: collapse;
		page-break-inside: avoid;
	}

	.viewTable th,
	.viewTable td {
		border: 1px solid #333;
		padding: 6px;
		page-break-inside: avoid;
	}

	.viewTable th {
		background: white;
		color: black;
		font-weight: bold;
	}

	/* Section spacing for print */
	:deep(.cn-detail-card) {
		margin-bottom: 24px;
		page-break-inside: avoid;
	}

	:deep(.cn-detail-card__title) {
		font-size: 16px;
		font-weight: bold;
		border-bottom: 2px solid #333;
		padding-bottom: 8px;
		margin-bottom: 12px;
	}

	/* Hide icons and complex interactions */
	:deep(.icon),
	:deep([class*="icon-"]),
	:deep(.nc-icon) {
		display: none;
	}
}

/* Skeleton loading animations */
@keyframes skeleton-loading {
	0% {
		background-color: var(--color-background-darker, #f0f0f0);
	}
	50% {
		background-color: var(--color-background-dark, #e8e8e8);
	}
	100% {
		background-color: var(--color-background-darker, #f0f0f0);
	}
}

/* Skeleton bar styles */
.skeleton-bar {
	display: inline-block;
	background-color: var(--color-background-darker, #f0f0f0);
	border-radius: var(--border-radius);
	animation: skeleton-loading 1.5s ease-in-out infinite;
	height: 12px;
	margin-bottom: 8px;
}

.skeleton-bar--title {
	width: 60%;
	height: 20px;
	margin-bottom: 12px;
}

.skeleton-bar--text {
	width: 100%;
	height: 12px;
	margin-bottom: 8px;
}

.skeleton-bar--short {
	width: 40%;
	height: 12px;
	margin-bottom: 8px;
}

.skeleton-card {
	padding: 16px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
}

.skeleton-group {
	margin-bottom: 16px;
}

.skeleton-group:last-child {
	margin-bottom: 0;
}

.skeleton-row {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 16px;
	margin-bottom: 16px;
}

.skeleton-row:last-child {
	margin-bottom: 0;
}

/* Touch target minimum size for WCAG AA compliance */
:deep(.nc-button),
:deep(.nc-button-group),
:deep(.nc-text-field),
:deep(.nc-select) {
	min-height: 44px;
}
</style>
