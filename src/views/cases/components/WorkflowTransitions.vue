<template>
	<div class="workflow-transitions">
		<!-- Version notice -->
		<div v-if="versionNotice" class="workflow-transitions__notice">
			{{ versionNotice }}
		</div>

		<!-- Required steps status -->
		<div v-if="requiredStepsInfo.length > 0" class="workflow-transitions__steps-info">
			<p class="workflow-transitions__steps-label">
				{{ t('procest', 'Required steps:') }}
			</p>
			<ul>
				<li
					v-for="info in requiredStepsInfo"
					:key="info.stepId"
					:class="{ 'workflow-transitions__step--done': info.completed }">
					{{ info.completed ? '✓' : '○' }} {{ info.title }}
				</li>
			</ul>
		</div>

		<!-- Transition buttons -->
		<div v-if="availableTransitions.length > 0" class="workflow-transitions__buttons">
			<NcButton
				v-for="tr in availableTransitions"
				:key="tr.id"
				:type="tr.available ? 'secondary' : 'tertiary'"
				:disabled="!tr.available || executing"
				:title="tr.available ? '' : tr.unmetConditions.join('; ')"
				@click="executeTransition(tr)">
				{{ tr.label || t('procest', 'Transition') }}
			</NcButton>
		</div>

		<!-- No transitions (final status) -->
		<div v-else-if="loaded && !loading" class="workflow-transitions__final">
			{{ t('procest', 'No transitions available') }}
		</div>
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import { useWorkflowStore } from '../../../store/modules/workflow.js'
import { useObjectStore } from '../../../store/modules/object.js'
import { useI18n } from 'vue-i18n'

export default {
	name: 'WorkflowTransitions',
	components: {
		NcButton,
	},
	setup() {
		const { t } = useI18n()
		return { t }
	},
	props: {
		caseData: {
			type: Object,
			required: true,
		},
		tasks: {
			type: Array,
			default: () => [],
		},
		documents: {
			type: Array,
			default: () => [],
		},
		userRoles: {
			type: Array,
			default: () => [],
		},
	},
	emits: ['transition-executed'],
	data() {
		return {
			workflowTemplate: null,
			activeVersion: null,
			availableTransitions: [],
			loading: false,
			loaded: false,
			executing: false,
		}
	},
	computed: {
		workflowStore() {
			return useWorkflowStore()
		},
		objectStore() {
			return useObjectStore()
		},
		versionNotice() {
			if (!this.workflowTemplate || !this.activeVersion) return null
			if (this.caseData.workflowVersion
				&& this.activeVersion.version
				&& this.caseData.workflowVersion !== this.activeVersion.version) {
				return t('procest', 'This case uses workflow version {caseVersion}. Current version is {activeVersion}.', {
					caseVersion: this.caseData.workflowVersion,
					activeVersion: this.activeVersion.version,
				})
			}
			return null
		},
		requiredStepsInfo() {
			if (!this.workflowTemplate) return []
			const steps = typeof this.workflowTemplate.steps === 'string'
				? JSON.parse(this.workflowTemplate.steps || '[]')
				: (this.workflowTemplate.steps || [])
			const currentStatus = this.caseData.status
			return steps
				.filter((s) => s.status === currentStatus && s.isRequired)
				.map((step) => {
					const task = this.tasks.find((t) => t.workflowStepId === step.id)
					return {
						stepId: step.id,
						title: step.title,
						completed: task?.status === 'completed',
					}
				})
		},
	},
	watch: {
		'caseData.status': {
			handler() {
				this.computeTransitions()
			},
		},
		tasks: {
			handler() {
				this.computeTransitions()
			},
			deep: true,
		},
	},
	async mounted() {
		await this.loadWorkflow()
	},
	methods: {
		async loadWorkflow() {
			if (!this.caseData.caseType) return

			this.loading = true
			try {
				// Get the active workflow for the case type
				this.activeVersion = await this.workflowStore.getActiveVersion(this.caseData.caseType)

				// If the case has a specific workflow version binding, use that
				if (this.caseData.workflowTemplate) {
					this.workflowTemplate = await this.objectStore.fetchObject(
						'workflowTemplate',
						this.caseData.workflowTemplate,
					)
				} else if (this.activeVersion) {
					this.workflowTemplate = this.activeVersion
				}

				if (this.workflowTemplate) {
					this.computeTransitions()
				}
			} catch (err) {
				console.error('Failed to load workflow:', err)
			} finally {
				this.loading = false
				this.loaded = true
			}
		},

		async computeTransitions() {
			if (!this.workflowTemplate) {
				this.availableTransitions = []
				return
			}

			let transitions = this.workflowStore.computeAvailableTransitions(
				this.caseData,
				this.userRoles,
				this.workflowTemplate,
				this.tasks,
				this.documents,
			)

			// Check for advice guards asynchronously
			transitions = await Promise.all(
				transitions.map(async (tr) => {
					if (!tr.available) return tr

					const guardDefs = tr.guards || []
					const hasAdviceGuard = guardDefs.some((g) => g.type === 'advicesGuard')

					if (hasAdviceGuard) {
						try {
							const { getAdviceForCase } = await import('../../../services/adviceApi.js')
							const response = await getAdviceForCase(this.caseData.id)
							const advice = response.data || response || []
							const pendingAdvice = advice.filter((a) => a.status === 'aangevraagd')

							if (pendingAdvice.length > 0) {
								const advisors = pendingAdvice.map((a) => `${a.adviseur} (${a.deadline})`).join(', ')
								tr.available = false
								tr.unmetConditions.push(
									this.t('procest', 'Pending advice: {advisors}', { advisors }),
								)
							}
						} catch (err) {
							console.error('Failed to check advice guard:', err)
						}
					}

					return tr
				}),
			)

			this.availableTransitions = transitions
		},

		async executeTransition(transition) {
			if (!transition.available) return

			this.executing = true
			try {
				// Update case status
				const updatedCase = await this.objectStore.saveObject('case', {
					...this.caseData,
					status: transition.toStatus,
				})

				// Dispatch automatic actions (non-blocking)
				if (transition.automaticActions?.length > 0) {
					this.workflowStore.dispatchActions(
						transition.automaticActions,
						updatedCase || this.caseData,
						transition,
					).catch((err) => {
						console.error('Some workflow actions failed:', err)
					})
				}

				// Auto-create tasks for the new status's steps
				await this.createStepTasks(transition.toStatus)

				// Auto-terminate optional tasks from the previous status
				await this.terminateOptionalTasks(transition.fromStatus)

				this.$emit('transition-executed', {
					transition,
					newStatus: transition.toStatus,
				})
			} catch (err) {
				console.error('Transition execution failed:', err)
			} finally {
				this.executing = false
			}
		},

		async createStepTasks(statusId) {
			if (!this.workflowTemplate) return

			const steps = typeof this.workflowTemplate.steps === 'string'
				? JSON.parse(this.workflowTemplate.steps || '[]')
				: (this.workflowTemplate.steps || [])

			const stepsForStatus = steps.filter((s) => s.status === statusId)

			for (const step of stepsForStatus) {
				// Check if a task for this step already exists
				const existingTask = this.tasks.find((t) => t.workflowStepId === step.id)
				if (existingTask) continue

				await this.objectStore.saveObject('task', {
					title: step.title,
					description: step.description || '',
					case: this.caseData.id,
					status: 'available',
					priority: 'normal',
					workflowStepId: step.id,
					checklist: step.checklist
						? JSON.stringify(
							step.checklist.map((item) => ({
								...item,
								checked: false,
							})),
						)
						: null,
				})
			}
		},

		async terminateOptionalTasks(fromStatusId) {
			if (!this.workflowTemplate) return

			const steps = typeof this.workflowTemplate.steps === 'string'
				? JSON.parse(this.workflowTemplate.steps || '[]')
				: (this.workflowTemplate.steps || [])

			const optionalStepIds = steps
				.filter((s) => s.status === fromStatusId && !s.isRequired)
				.map((s) => s.id)

			for (const task of this.tasks) {
				if (optionalStepIds.includes(task.workflowStepId)
					&& task.status !== 'completed'
					&& task.status !== 'terminated') {
					await this.objectStore.saveObject('task', {
						...task,
						status: 'terminated',
					})
				}
			}
		},
	},
}
</script>

<style scoped>
.workflow-transitions__notice {
	background: var(--color-primary-element-light);
	border-radius: var(--border-radius);
	padding: 8px 12px;
	margin-bottom: 8px;
	font-size: 12px;
}

.workflow-transitions__steps-info {
	margin-bottom: 8px;
	font-size: 12px;
}

.workflow-transitions__steps-label {
	font-weight: 600;
	margin: 0 0 4px 0;
}

.workflow-transitions__steps-info ul {
	margin: 0;
	padding: 0 0 0 16px;
}

.workflow-transitions__step--done {
	color: var(--color-success);
}

.workflow-transitions__buttons {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	margin-top: 8px;
}

.workflow-transitions__final {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
	font-style: italic;
}
</style>
