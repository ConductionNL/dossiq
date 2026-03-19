<template>
	<div v-if="editing || isNew" class="task-detail">
		<div class="task-detail__header">
			<NcButton @click="onCancel">
				{{ t('procest', 'Back to list') }}
			</NcButton>
			<h2 v-if="isNew">
				{{ t('procest', 'New task') }}
			</h2>
			<h2 v-else>
				{{ taskData.title || t('procest', 'Task') }}
			</h2>
		</div>

		<!-- Form -->
		<div class="task-detail__form">
			<div class="form-group">
				<label>{{ t('procest', 'Title') }} *</label>
				<NcTextField
					:value="form.title"
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
					rows="4" />
			</div>

			<div class="form-row">
				<div class="form-group">
					<label>{{ t('procest', 'Assignee') }}</label>
					<NcTextField
						:value="form.assignee"
						:placeholder="t('procest', 'Username')"
						@update:value="v => form.assignee = v" />
				</div>
				<div class="form-group">
					<label>{{ t('procest', 'Due date') }}</label>
					<NcTextField
						:value="form.dueDate"
						type="date"
						@update:value="v => form.dueDate = v" />
				</div>
			</div>

			<div class="form-row">
				<div class="form-group">
					<label>{{ t('procest', 'Priority') }}</label>
					<NcSelect
						v-model="form.priority"
						:options="priorityOptions" />
				</div>
			</div>

			<!-- Save / Cancel actions -->
			<div class="task-detail__form-actions">
				<NcButton
					type="primary"
					:disabled="saving"
					@click="save">
					<template v-if="saving">
						<NcLoadingIcon :size="20" />
					</template>
					{{ t('procest', 'Save') }}
				</NcButton>
				<NcButton
					v-if="!isNew"
					@click="editing = false">
					{{ t('procest', 'Cancel') }}
				</NcButton>
			</div>
		</div>
	</div>

	<CnDetailPage
		v-else
		:title="taskData.title || t('procest', 'Task')"
		:subtitle="t('procest', 'Task')"
		:back-route="{ name: 'Tasks' }"
		:back-label="t('procest', 'Back to list')"
		:loading="loading"
		:sidebar="!loading"
		object-type="procest_task"
		:object-id="taskId"
		:sidebar-props="sidebarProps">
		<template #header-actions>
			<NcButton
				v-if="!isReadOnly"
				type="primary"
				@click="startEditing">
				{{ t('procest', 'Edit') }}
			</NcButton>
			<NcButton type="error" @click="confirmDelete">
				{{ t('procest', 'Delete') }}
			</NcButton>
		</template>

		<!-- Status card -->
		<CnDetailCard :title="t('procest', 'Status')">
			<div class="status-section">
				<span class="status-badge" :class="'status-badge--' + taskData.status">
					{{ getStatusLabel(taskData.status) }}
				</span>

				<div v-if="allowedTransitions.length > 0" class="status-section__actions">
					<NcButton
						v-for="target in allowedTransitions"
						:key="target"
						:type="target === 'completed' ? 'primary' : 'secondary'"
						@click="transitionTo(target)">
						{{ getTransitionLabel(target) }}
					</NcButton>
				</div>

				<div v-if="taskData.completedDate" class="status-section__completed-date">
					{{ t('procest', 'Completed on {date}', { date: formatDueDate(taskData.completedDate) }) }}
				</div>
			</div>
		</CnDetailCard>

		<!-- Task Information card -->
		<CnDetailCard :title="t('procest', 'Task Information')">
			<div class="info-grid">
				<div class="info-field">
					<label>{{ t('procest', 'Title') }}</label>
					<span>{{ taskData.title || '-' }}</span>
				</div>
				<div class="info-field">
					<label>{{ t('procest', 'Priority') }}</label>
					<span>{{ taskData.priority || '-' }}</span>
				</div>
				<div class="info-field">
					<label>{{ t('procest', 'Assignee') }}</label>
					<span>{{ taskData.assignee || '-' }}</span>
				</div>
				<div class="info-field">
					<label>{{ t('procest', 'Due date') }}</label>
					<span>{{ taskData.dueDate ? formatDueDate(taskData.dueDate) : '-' }}</span>
				</div>
				<div v-if="taskData.description" class="info-field info-field--full">
					<label>{{ t('procest', 'Description') }}</label>
					<span>{{ taskData.description }}</span>
				</div>
			</div>
		</CnDetailCard>

		<!-- Linked Case card -->
		<CnDetailCard v-if="caseId" :title="t('procest', 'Linked Case')">
			<a class="case-link" @click="openCase">
				{{ t('procest', 'Case: {id}', { id: caseTitle }) }}
			</a>
		</CnDetailCard>
	</CnDetailPage>
</template>

<script>
import { NcButton, NcLoadingIcon, NcTextField, NcSelect } from '@nextcloud/vue'
import { CnDetailPage, CnDetailCard } from '@conduction/nextcloud-vue'
import { useObjectStore } from '../../store/modules/object.js'
import { getAllowedTransitions, getStatusLabel, getTransitionLabel, isTerminalStatus } from '../../utils/taskLifecycle.js'
import { formatDueDate } from '../../utils/taskHelpers.js'

export default {
	name: 'TaskDetail',
	components: {
		NcButton,
		NcLoadingIcon,
		NcTextField,
		NcSelect,
		CnDetailPage,
		CnDetailCard,
	},
	props: {
		taskId: {
			type: String,
			default: null,
		},
		caseIdProp: {
			type: String,
			default: null,
		},
	},
	data() {
		return {
			editing: false,
			form: {
				title: '',
				description: '',
				assignee: '',
				dueDate: '',
				priority: 'normal',
				status: 'available',
				completedDate: null,
			},
			saving: false,
			validationErrors: {
				title: '',
			},
			priorityOptions: ['low', 'normal', 'high', 'urgent'],
		}
	},
	computed: {
		objectStore() {
			return useObjectStore()
		},
		isNew() {
			return !this.taskId || this.taskId === 'new'
		},
		loading() {
			return this.objectStore.loading.task || false
		},
		taskData() {
			if (this.isNew) return {}
			return this.objectStore.getObject('task', this.taskId) || {}
		},
		caseId() {
			return this.taskData.case || this.caseIdProp || null
		},
		caseTitle() {
			if (!this.caseId) return ''
			const caseObj = this.objectStore.getObject('case', this.caseId)
			return caseObj?.title || caseObj?.identifier || this.caseId
		},
		isReadOnly() {
			return isTerminalStatus(this.taskData.status)
		},
		allowedTransitions() {
			if (this.isNew) return []
			return getAllowedTransitions(this.taskData.status)
		},
		sidebarProps() {
			const config = this.objectStore.objectTypeRegistry.task || {}
			return {
				register: config.register || '',
				schema: config.schema || '',
			}
		},
	},
	async mounted() {
		if (!this.isNew) {
			await this.objectStore.fetchObject('task', this.taskId)
			if (this.caseId) {
				this.objectStore.fetchObject('case', this.caseId)
			}
		} else if (this.caseIdProp) {
			this.form.case = this.caseIdProp
		}
	},
	methods: {
		getStatusLabel,
		getTransitionLabel,
		formatDueDate,

		startEditing() {
			this.populateForm()
			this.editing = true
		},

		populateForm() {
			const data = this.taskData
			this.form = {
				title: data.title || '',
				description: data.description || '',
				assignee: data.assignee || '',
				dueDate: data.dueDate ? data.dueDate.split('T')[0] : '',
				priority: data.priority || 'normal',
				status: data.status || 'available',
				completedDate: data.completedDate || null,
			}
		},

		validate() {
			this.validationErrors = { title: '' }
			if (!this.form.title.trim()) {
				this.validationErrors.title = t('procest', 'Title is required')
				return false
			}
			return true
		},

		async save() {
			if (!this.validate()) return

			this.saving = true
			const objectData = {
				title: this.form.title,
				description: this.form.description,
				assignee: this.form.assignee || null,
				dueDate: this.form.dueDate ? this.form.dueDate + 'T17:00:00Z' : null,
				priority: this.form.priority,
				status: this.form.status,
			}

			if (this.isNew) {
				objectData.case = this.caseIdProp || null
				objectData.status = 'available'
				objectData.priority = this.form.priority || 'normal'
			} else {
				objectData.id = this.taskId
				objectData.completedDate = this.form.completedDate
			}

			const result = await this.objectStore.saveObject('task', objectData)
			this.saving = false

			if (result) {
				if (this.isNew) {
					this.$router.push({ name: 'TaskDetail', params: { id: result.id } })
				} else {
					await this.objectStore.fetchObject('task', this.taskId)
					this.editing = false
				}
			}
		},

		async transitionTo(targetStatus) {
			const taskData = this.taskData
			const update = {
				id: this.taskId,
				title: taskData.title,
				description: taskData.description || '',
				assignee: taskData.assignee || null,
				dueDate: taskData.dueDate || null,
				priority: taskData.priority || 'normal',
				case: taskData.case || null,
				status: targetStatus,
				completedDate: taskData.completedDate || null,
			}

			if (targetStatus === 'completed') {
				update.completedDate = new Date().toISOString()
			}

			await this.objectStore.saveObject('task', update)
		},

		async confirmDelete() {
			if (confirm(t('procest', 'Are you sure you want to delete this task?'))) {
				const success = await this.objectStore.deleteObject('task', this.taskId)
				if (success) {
					this.$router.push({ name: 'Tasks' })
				}
			}
		},

		onCancel() {
			if (this.isNew) {
				this.$router.push({ name: 'Tasks' })
			} else {
				this.editing = false
			}
		},

		openCase() {
			if (this.caseId) {
				this.$router.push({ name: 'CaseDetail', params: { id: this.caseId } })
			}
		},
	},
}
</script>

<style scoped>
.task-detail {
	padding: 20px;
	max-width: 800px;
}

.task-detail__header {
	display: flex;
	align-items: center;
	gap: 16px;
	margin-bottom: 20px;
}

.task-detail__form {
	margin-top: 12px;
}

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

.task-detail__form-actions {
	display: flex;
	gap: 12px;
	margin-top: 20px;
}

/* View mode styles */
.status-section {
	display: flex;
	align-items: center;
	gap: 12px;
	flex-wrap: wrap;
}

.status-section__actions {
	display: flex;
	gap: 8px;
	margin-left: auto;
}

.status-section__completed-date {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.status-badge {
	display: inline-block;
	padding: 2px 8px;
	border-radius: var(--border-radius-pill);
	font-size: 12px;
	font-weight: 500;
}

.status-badge--available {
	background: var(--color-background-dark);
	color: var(--color-main-text);
}

.status-badge--active {
	background: var(--color-primary-light);
	color: var(--color-primary-text);
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

.info-grid {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 16px;
}

.info-field {
	margin-bottom: 8px;
}

.info-field--full {
	grid-column: 1 / -1;
}

.info-field label {
	display: block;
	font-weight: bold;
	margin-bottom: 2px;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.case-link {
	color: var(--color-primary);
	text-decoration: underline;
	cursor: pointer;
}

.case-link:hover {
	color: var(--color-primary-hover);
}
</style>
