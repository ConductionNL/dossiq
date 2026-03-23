<template>
	<div class="step-config-panel">
		<div class="step-config-panel__header">
			<h4>{{ t('procest', 'Step Configuration') }}</h4>
			<NcButton type="tertiary" @click="$emit('close')">
				<template #icon>
					<CloseIcon :size="20" />
				</template>
			</NcButton>
		</div>

		<div class="step-config-panel__body">
			<!-- Title -->
			<div class="step-config-panel__field">
				<label>{{ t('procest', 'Title') }}</label>
				<input
					v-model="localStep.title"
					type="text"
					class="step-config-panel__input"
					@input="emitUpdate">
			</div>

			<!-- Description -->
			<div class="step-config-panel__field">
				<label>{{ t('procest', 'Description') }}</label>
				<textarea
					v-model="localStep.description"
					class="step-config-panel__textarea"
					rows="3"
					@input="emitUpdate" />
			</div>

			<!-- Required -->
			<div class="step-config-panel__field step-config-panel__field--row">
				<input
					id="step-required"
					v-model="localStep.isRequired"
					type="checkbox"
					@change="emitUpdate">
				<label for="step-required">{{ t('procest', 'Required step (blocks status transition)') }}</label>
			</div>

			<!-- Assignee Role -->
			<div class="step-config-panel__field">
				<label>{{ t('procest', 'Assignee role') }}</label>
				<select
					v-model="localStep.assigneeRole"
					class="step-config-panel__select"
					@change="emitUpdate">
					<option :value="null">
						{{ t('procest', 'Any role') }}
					</option>
					<option
						v-for="role in roleTypes"
						:key="role.id"
						:value="role.id">
						{{ role.name }}
					</option>
				</select>
			</div>

			<!-- Checklist -->
			<div class="step-config-panel__section">
				<h5>{{ t('procest', 'Checklist') }}</h5>
				<div
					v-for="(item, index) in localChecklist"
					:key="item.id"
					class="step-config-panel__checklist-item"
					draggable="true"
					@dragstart="onCheckDragStart(index, $event)"
					@dragover.prevent
					@drop="onCheckDrop(index, $event)">
					<span class="step-config-panel__drag-handle">&#x2630;</span>
					<input
						v-model="item.label"
						type="text"
						class="step-config-panel__input"
						:placeholder="t('procest', 'Checklist item')"
						@input="emitUpdate">
					<NcButton
						type="tertiary"
						@click="removeChecklistItem(index)">
						<template #icon>
							<CloseIcon :size="16" />
						</template>
					</NcButton>
				</div>
				<NcButton
					type="secondary"
					@click="addChecklistItem">
					{{ t('procest', 'Add checklist item') }}
				</NcButton>
			</div>

			<!-- Automatic Actions -->
			<div class="step-config-panel__section">
				<h5>{{ t('procest', 'Automatic actions on completion') }}</h5>
				<div
					v-for="(action, index) in localActions"
					:key="index"
					class="step-config-panel__action">
					<select
						v-model="action.type"
						class="step-config-panel__select"
						@change="emitUpdate">
						<option value="createTask">
							{{ t('procest', 'Create task') }}
						</option>
						<option value="notify">
							{{ t('procest', 'Send notification') }}
						</option>
						<option value="webhook">
							{{ t('procest', 'Call webhook') }}
						</option>
					</select>
					<input
						v-if="action.type === 'createTask'"
						v-model="action.title"
						type="text"
						:placeholder="t('procest', 'Task title')"
						class="step-config-panel__input"
						@input="emitUpdate">
					<input
						v-if="action.type === 'notify'"
						v-model="action.message"
						type="text"
						:placeholder="t('procest', 'Notification message')"
						class="step-config-panel__input"
						@input="emitUpdate">
					<input
						v-if="action.type === 'webhook'"
						v-model="action.url"
						type="url"
						:placeholder="t('procest', 'Webhook URL')"
						class="step-config-panel__input"
						@input="emitUpdate">
					<NcButton
						type="tertiary"
						@click="removeAction(index)">
						<template #icon>
							<CloseIcon :size="16" />
						</template>
					</NcButton>
				</div>
				<NcButton
					type="secondary"
					@click="addAction">
					{{ t('procest', 'Add action') }}
				</NcButton>
			</div>
		</div>
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import CloseIcon from 'vue-material-design-icons/Close.vue'

let nextCheckId = 1

export default {
	name: 'StepConfigPanel',
	components: {
		NcButton,
		CloseIcon,
	},
	props: {
		step: {
			type: Object,
			required: true,
		},
		roleTypes: {
			type: Array,
			default: () => [],
		},
	},
	emits: ['update', 'close'],
	data() {
		return {
			localStep: { ...this.step },
			localChecklist: this.parseChecklist(this.step.checklist),
			localActions: this.parseActions(this.step.automaticActions),
			dragCheckIndex: null,
		}
	},
	watch: {
		step: {
			handler(newStep) {
				this.localStep = { ...newStep }
				this.localChecklist = this.parseChecklist(newStep.checklist)
				this.localActions = this.parseActions(newStep.automaticActions)
			},
			deep: true,
		},
	},
	methods: {
		parseChecklist(checklist) {
			if (!checklist) return []
			if (typeof checklist === 'string') {
				try { return JSON.parse(checklist) } catch { return [] }
			}
			return [...checklist]
		},

		parseActions(actions) {
			if (!actions) return []
			if (typeof actions === 'string') {
				try { return JSON.parse(actions) } catch { return [] }
			}
			return [...actions]
		},

		emitUpdate() {
			this.$emit('update', {
				...this.localStep,
				checklist: this.localChecklist,
				automaticActions: this.localActions,
			})
		},

		addChecklistItem() {
			this.localChecklist.push({
				id: `check-${nextCheckId++}`,
				label: '',
				description: '',
			})
			this.emitUpdate()
		},

		removeChecklistItem(index) {
			this.localChecklist.splice(index, 1)
			this.emitUpdate()
		},

		onCheckDragStart(index, event) {
			this.dragCheckIndex = index
			event.dataTransfer.effectAllowed = 'move'
		},

		onCheckDrop(targetIndex, event) {
			if (this.dragCheckIndex !== null && this.dragCheckIndex !== targetIndex) {
				const item = this.localChecklist.splice(this.dragCheckIndex, 1)[0]
				this.localChecklist.splice(targetIndex, 0, item)
				this.emitUpdate()
			}
			this.dragCheckIndex = null
		},

		addAction() {
			this.localActions.push({ type: 'createTask', title: '' })
			this.emitUpdate()
		},

		removeAction(index) {
			this.localActions.splice(index, 1)
			this.emitUpdate()
		},
	},
}
</script>

<style scoped>
.step-config-panel {
	position: fixed;
	right: 0;
	top: 0;
	width: 360px;
	height: 100%;
	background: var(--color-main-background);
	border-left: 1px solid var(--color-border);
	box-shadow: -2px 0 8px rgba(0, 0, 0, 0.1);
	z-index: 100;
	display: flex;
	flex-direction: column;
	overflow-y: auto;
}

.step-config-panel__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 12px 16px;
	border-bottom: 1px solid var(--color-border);
}

.step-config-panel__header h4 {
	margin: 0;
}

.step-config-panel__body {
	padding: 16px;
	flex: 1;
	overflow-y: auto;
}

.step-config-panel__field {
	margin-bottom: 12px;
}

.step-config-panel__field label {
	display: block;
	font-size: 12px;
	font-weight: 600;
	margin-bottom: 4px;
	color: var(--color-text-maxcontrast);
}

.step-config-panel__field--row {
	display: flex;
	align-items: center;
	gap: 8px;
}

.step-config-panel__field--row label {
	display: inline;
	margin-bottom: 0;
}

.step-config-panel__input {
	width: 100%;
	padding: 6px 8px;
	border: 1px solid var(--color-border-dark);
	border-radius: var(--border-radius);
	font-size: 13px;
}

.step-config-panel__textarea {
	width: 100%;
	padding: 6px 8px;
	border: 1px solid var(--color-border-dark);
	border-radius: var(--border-radius);
	font-size: 13px;
	resize: vertical;
}

.step-config-panel__select {
	width: 100%;
	padding: 6px 8px;
	border: 1px solid var(--color-border-dark);
	border-radius: var(--border-radius);
	font-size: 13px;
}

.step-config-panel__section {
	margin-top: 20px;
	padding-top: 16px;
	border-top: 1px solid var(--color-border);
}

.step-config-panel__section h5 {
	font-size: 12px;
	font-weight: 600;
	text-transform: uppercase;
	color: var(--color-text-maxcontrast);
	margin: 0 0 8px 0;
}

.step-config-panel__checklist-item {
	display: flex;
	align-items: center;
	gap: 4px;
	margin-bottom: 4px;
}

.step-config-panel__drag-handle {
	cursor: grab;
	color: var(--color-text-maxcontrast);
	font-size: 12px;
}

.step-config-panel__action {
	display: flex;
	flex-direction: column;
	gap: 4px;
	margin-bottom: 8px;
	padding: 8px;
	background: var(--color-background-dark);
	border-radius: var(--border-radius);
}
</style>
