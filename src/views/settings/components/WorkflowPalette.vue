<template>
	<div class="workflow-palette">
		<h4 class="workflow-palette__title">
			{{ t('procest', 'Elements') }}
		</h4>

		<div
			class="workflow-palette__item"
			draggable="true"
			@dragstart="onDragStart('status', $event)">
			<span class="workflow-palette__icon">
				&#x25A1;
			</span>
			<span>{{ t('procest', 'Status node') }}</span>
		</div>

		<div class="workflow-palette__help">
			<p>{{ t('procest', 'Drag a status node onto the canvas to add it.') }}</p>
			<p>{{ t('procest', 'Connect nodes by dragging from one port to another.') }}</p>
			<p>{{ t('procest', 'Click a node to select it, double-click a transition to edit.') }}</p>
		</div>

		<h4 class="workflow-palette__title">
			{{ t('procest', 'Controls') }}
		</h4>
		<div class="workflow-palette__help">
			<p><strong>{{ t('procest', 'Pan') }}:</strong> {{ t('procest', 'Click and drag on empty canvas') }}</p>
			<p><strong>{{ t('procest', 'Zoom') }}:</strong> {{ t('procest', 'Scroll wheel') }}</p>
		</div>
	</div>
</template>

<script>
export default {
	name: 'WorkflowPalette',
	emits: ['drag-start'],
	methods: {
		/**
		 * @param type
		 * @param event
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
	padding: 12px;
	background: var(--color-main-background);
}

.workflow-palette__title {
	font-size: 12px;
	font-weight: 600;
	text-transform: uppercase;
	color: var(--color-text-maxcontrast);
	margin: 0 0 8px 0;
}

.workflow-palette__item {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 8px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	cursor: grab;
	font-size: 13px;
	margin-bottom: 8px;
}

.workflow-palette__item:hover {
	background: var(--color-background-hover);
	border-color: var(--color-primary);
}

.workflow-palette__item:active {
	cursor: grabbing;
}

.workflow-palette__icon {
	font-size: 18px;
	width: 24px;
	text-align: center;
}

.workflow-palette__help {
	font-size: 11px;
	color: var(--color-text-maxcontrast);
	line-height: 1.5;
	margin-bottom: 16px;
}

.workflow-palette__help p {
	margin: 4px 0;
}
</style>
