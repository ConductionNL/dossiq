<template>
	<div class="workflow-palette">
		<h4 class="workflow-palette__title">
			{{ t('dossiq', 'Elements') }}
		</h4>

		<div
			class="workflow-palette__item"
			draggable="true"
			@dragstart="onDragStart('status', $event)">
			<span>{{ t('dossiq', 'Status node') }}</span>
		</div>

		<!-- Keyboard alternative to dragging the item above onto the canvas
			(drag-and-drop has no keyboard equivalent). -->
		<NcButton
			type="secondary"
			class="workflow-palette__add-button"
			@click="$emit('add-status')">
			<template #icon>
				<Plus :size="18" />
			</template>
			{{ t('dossiq', 'Add status node') }}
		</NcButton>

		<div class="workflow-palette__help">
			<p>
				{{ t('dossiq', 'Drag a status node onto the canvas, or use the button. Connect nodes by dragging from one port to the other.') }}
			</p>
			<p>
				{{ t('dossiq', "A node's actions menu does the same from the keyboard; double-click a transition to edit it.") }}
			</p>
		</div>

		<h4 class="workflow-palette__title">
			{{ t('dossiq', 'Controls') }}
		</h4>
		<div class="workflow-palette__help">
			<p>
				<strong>{{ t('dossiq', 'Pan') }}:</strong>
				{{ t('dossiq', 'drag on an empty canvas.') }}
				<strong>{{ t('dossiq', 'Zoom') }}:</strong>
				{{ t('dossiq', 'scroll wheel.') }}
			</p>
		</div>
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import Plus from 'vue-material-design-icons/Plus.vue'

export default {
	name: 'WorkflowPalette',
	components: {
		NcButton,
		Plus,
	},

	emits: ['drag-start', 'add-status'],
	methods: {
		/**
		 * @param {string} type The type.
		 * @param {Event} event The originating DOM event.
		 * @spec openspec/specs/workflow-definition-model/spec.md
		 */
		onDragStart(type, event) {
			event.dataTransfer.setData('text/plain', type)
			event.dataTransfer.effectAllowed = 'copy'
			this.$emit('drag-start', type)
		},
	},
}
</script>

<style scoped>
.workflow-palette {
	padding: 16px;
	background: var(--color-main-background);
}

.workflow-palette__title {
	font-size: 12px;
	font-weight: 600;
	letter-spacing: 0.06em;
	text-transform: uppercase;
	color: var(--color-text-maxcontrast);
	margin: 0 0 10px 0;
}

.workflow-palette__item {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 8px 10px;
	border: 1px solid var(--color-border);
	border-radius: 8px;
	cursor: grab;
	font-size: 14px;
	margin-bottom: 10px;
}

.workflow-palette__item:hover {
	background: var(--color-background-hover);
	border-color: var(--color-primary);
}

.workflow-palette__item:active {
	cursor: grabbing;
}

.workflow-palette__add-button {
	width: 100%;
	margin-bottom: 12px;
}

.workflow-palette__help {
	font-size: 14px;
	color: var(--color-main-text);
	line-height: 1.25;
	margin-bottom: 16px;
}

.workflow-palette__help p {
	margin: 4px 0;
}
</style>
