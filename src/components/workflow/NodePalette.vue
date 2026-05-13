<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  - SPDX-License-Identifier: EUPL-1.2
  -->
<template>
	<aside class="node-palette" :aria-label="t('procest', 'Workflow node palette')">
		<h3 class="node-palette__title">
			{{ t('procest', 'Nodes') }}
		</h3>
		<p class="node-palette__hint">
			{{ t('procest', 'Drag a node onto the canvas') }}
		</p>
		<ul class="node-palette__list">
			<li
				v-for="tpl in templates"
				:key="tpl.type"
				class="node-palette__item"
				:class="`node-palette__item--${tpl.type}`"
				draggable="true"
				@dragstart="onDragStart($event, tpl.type)">
				<span class="node-palette__icon" aria-hidden="true">{{ tpl.icon }}</span>
				<span class="node-palette__label">{{ tpl.label }}</span>
			</li>
		</ul>
	</aside>
</template>

<script>
/**
 * NodePalette — left-rail draggable node templates.
 *
 * The visual editor mounts this once. Each <li> uses native HTML5
 * drag-and-drop; the canvas drop handler reads `application/vue-flow-type`.
 *
 * Type-specific styling per node maps to the workflow-definition-model:
 *   - status   → workflowTemplate.steps[].status
 *   - decision → guard-bearing transition
 *   - parallel → AND-split fork point
 *   - end      → status with isFinal === true
 */
export default {
	name: 'NodePalette',
	computed: {
		templates() {
			return [
				{ type: 'status', label: t('procest', 'Status'), icon: '▭' },
				{ type: 'decision', label: t('procest', 'Decision'), icon: '◇' },
				{ type: 'parallel', label: t('procest', 'Parallel'), icon: '⫸' },
				{ type: 'end', label: t('procest', 'End'), icon: '◉' },
			]
		},
	},
	methods: {
		onDragStart(event, type) {
			event.dataTransfer.setData('application/vue-flow-type', type)
			event.dataTransfer.effectAllowed = 'move'
		},
	},
}
</script>

<style scoped>
.node-palette {
	display: flex;
	flex-direction: column;
	width: 200px;
	padding: 12px;
	border-right: 1px solid var(--color-border, #ddd);
	background: var(--color-main-background, #fff);
	overflow-y: auto;
}

.node-palette__title {
	font-size: 14px;
	font-weight: 600;
	margin: 0 0 4px;
}

.node-palette__hint {
	font-size: 12px;
	color: var(--color-text-maxcontrast, #888);
	margin: 0 0 12px;
}

.node-palette__list {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.node-palette__item {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 10px 12px;
	border: 1px solid var(--color-border, #ddd);
	border-radius: var(--border-radius, 4px);
	background: var(--color-background-hover, #f5f5f5);
	cursor: grab;
	user-select: none;
}

.node-palette__item:hover {
	background: var(--color-primary-light, #e8f0fe);
}

.node-palette__item:active {
	cursor: grabbing;
}

.node-palette__item--end {
	border-style: double;
	border-width: 3px;
}

.node-palette__icon {
	font-size: 18px;
	width: 24px;
	text-align: center;
}

.node-palette__label {
	font-size: 13px;
}
</style>
