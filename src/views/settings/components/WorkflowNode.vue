<template>
	<div
		class="workflow-node"
		:class="{
			'workflow-node--selected': selected,
			'workflow-node--final': status.isFinal,
		}"
		:style="nodeStyle"
		@mousedown.stop="onMouseDown"
		@click.stop="$emit('select')">
		<!-- Input port (top) -->
		<div
			class="workflow-node__port workflow-node__port--input"
			@mouseup.stop="$emit('connection-end')" />

		<!-- Header -->
		<div class="workflow-node__header">
			<span class="workflow-node__name">{{ status.name }}</span>
			<span
				v-if="steps.length > 0"
				class="workflow-node__badge">
				{{ steps.length }}
			</span>
			<span
				v-if="status.isFinal"
				class="workflow-node__final-badge">
				{{ t('procest', 'Final') }}
			</span>
		</div>

		<!-- Steps list -->
		<div
			v-if="steps.length > 0"
			class="workflow-node__steps">
			<div
				v-for="step in steps"
				:key="step.id"
				class="workflow-node__step"
				:class="{ 'workflow-node__step--required': step.isRequired }"
				draggable="true"
				@dragstart.stop="onStepDragStart(step, $event)"
				@dragover.prevent
				@drop.stop="onStepDrop(step, $event)"
				@click.stop="$emit('step-click', step)">
				<span class="workflow-node__step-name">{{ step.title }}</span>
				<span
					v-if="step.checklist && step.checklist.length > 0"
					class="workflow-node__step-checks">
					{{ step.checklist.length }}
				</span>
			</div>
		</div>

		<!-- Add step button -->
		<button
			class="workflow-node__add-step"
			@click.stop="$emit('add-step')">
			+ {{ t('procest', 'Add step') }}
		</button>

		<!-- Output port (bottom) -->
		<div
			class="workflow-node__port workflow-node__port--output"
			@mousedown.stop="onConnectionStartFromPort" />
	</div>
</template>

<script>
export default {
	name: 'WorkflowNode',
	props: {
		status: {
			type: Object,
			required: true,
		},
		steps: {
			type: Array,
			default: () => [],
		},
		position: {
			type: Object,
			default: () => ({ x: 0, y: 0 }),
		},
		selected: {
			type: Boolean,
			default: false,
		},
	},
	emits: ['select', 'drag-start', 'connection-start', 'connection-end', 'step-click', 'add-step'],
	data() {
		return {
			draggedStepId: null,
		}
	},
	computed: {
		/** @spec openspec/specs/workflow-definition-model/spec.md */
		nodeStyle() {
			return {
				position: 'absolute',
				left: `${this.position.x}px`,
				top: `${this.position.y}px`,
			}
		},
	},
	methods: {
		/** @spec openspec/specs/workflow-definition-model/spec.md */
		onMouseDown(event) {
			this.$emit('drag-start', {
				offsetX: event.offsetX,
				offsetY: event.offsetY,
			})
		},

		/** @spec openspec/specs/workflow-definition-model/spec.md */
		onConnectionStartFromPort(event) {
			this.$emit('connection-start', event)
		},

		/** @spec openspec/specs/workflow-definition-model/spec.md */
		onStepDragStart(step, event) {
			this.draggedStepId = step.id
			event.dataTransfer.setData('text/plain', step.id)
			event.dataTransfer.effectAllowed = 'move'
		},

		/** @spec openspec/specs/workflow-definition-model/spec.md */
		onStepDrop(targetStep, event) {
			const draggedId = event.dataTransfer.getData('text/plain')
			if (draggedId && draggedId !== targetStep.id) {
				this.$emit('step-reorder', {
					draggedId,
					targetId: targetStep.id,
				})
			}
			this.draggedStepId = null
		},
	},
}
</script>

<style scoped>
.workflow-node {
	width: 200px;
	min-height: 80px;
	background: var(--color-main-background);
	border: 2px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
	cursor: move;
	user-select: none;
}

.workflow-node--selected {
	border-color: var(--color-primary);
	box-shadow: 0 0 0 2px var(--color-primary-element-light);
}

.workflow-node--final {
	border-color: var(--color-success);
}

.workflow-node__port {
	position: absolute;
	width: 12px;
	height: 12px;
	background: var(--color-primary);
	border: 2px solid var(--color-main-background);
	border-radius: 50%;
	cursor: crosshair;
	z-index: 1;
}

.workflow-node__port--input {
	top: -6px;
	left: 50%;
	transform: translateX(-50%);
}

.workflow-node__port--output {
	bottom: -6px;
	left: 50%;
	transform: translateX(-50%);
}

.workflow-node__header {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 8px 12px;
	background: var(--color-background-dark);
	border-radius: var(--border-radius-large, 8px) var(--border-radius-large, 8px) 0 0;
}

.workflow-node__name {
	flex: 1;
	font-weight: 600;
	font-size: 13px;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.workflow-node__badge {
	background: var(--color-primary);
	color: var(--color-primary-text);
	border-radius: 10px;
	padding: 0 6px;
	font-size: 11px;
	min-width: 20px;
	text-align: center;
}

.workflow-node__final-badge {
	background: var(--color-success);
	color: white;
	border-radius: 4px;
	padding: 0 4px;
	font-size: 10px;
}

.workflow-node__steps {
	padding: 4px 0;
}

.workflow-node__step {
	display: flex;
	align-items: center;
	gap: 4px;
	padding: 4px 12px;
	cursor: pointer;
	font-size: 12px;
}

.workflow-node__step:hover {
	background: var(--color-background-hover);
}

.workflow-node__step--required {
	font-weight: 500;
}

.workflow-node__step--required::before {
	content: '*';
	color: var(--color-error);
}

.workflow-node__step-name {
	flex: 1;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.workflow-node__step-checks {
	background: var(--color-background-dark);
	border-radius: 4px;
	padding: 0 4px;
	font-size: 10px;
	color: var(--color-text-maxcontrast);
}

.workflow-node__add-step {
	display: block;
	width: 100%;
	padding: 4px 12px;
	border: none;
	background: none;
	cursor: pointer;
	font-size: 11px;
	color: var(--color-text-maxcontrast);
	text-align: left;
}

.workflow-node__add-step:hover {
	background: var(--color-background-hover);
	color: var(--color-primary);
}
</style>
