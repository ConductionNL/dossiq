<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  - SPDX-License-Identifier: EUPL-1.2
  -->
<template>
	<div
		ref="canvasRef"
		class="workflow-canvas"
		@drop="onDrop"
		@dragover.prevent>
		<VueFlow
			:nodes.sync="localNodes"
			:edges.sync="localEdges"
			:default-edge-options="defaultEdgeOptions"
			:fit-view-on-init="true"
			:nodes-draggable="!readOnly"
			:nodes-connectable="!readOnly"
			:elements-selectable="!readOnly"
			@nodes-change="onNodesChange"
			@edges-change="onEdgesChange"
			@connect="onConnect"
			@node-click="onNodeClick"
			@edge-click="onEdgeClick"
			@pane-click="onPaneClick">
			<Background pattern-color="#aaa" :gap="16" />
			<Controls />
			<template #node-status="props">
				<div class="vf-node vf-node--status" :class="{ 'vf-node--error': hasIssue(props.id, 'error') }">
					<div class="vf-node__header">
						{{ props.data.label || t('procest', 'Status') }}
					</div>
					<div v-if="props.data.stepCount !== undefined" class="vf-node__body">
						{{ t('procest', '{n} steps', { n: props.data.stepCount }) }}
					</div>
				</div>
			</template>
			<template #node-decision="props">
				<div class="vf-node vf-node--decision" :class="{ 'vf-node--error': hasIssue(props.id, 'error') }">
					<div class="vf-node__diamond">
						{{ props.data.label || t('procest', 'Decision') }}
					</div>
				</div>
			</template>
			<template #node-parallel="props">
				<div class="vf-node vf-node--parallel" :class="{ 'vf-node--error': hasIssue(props.id, 'error') }">
					{{ props.data.label || t('procest', 'Parallel') }}
				</div>
			</template>
			<template #node-end="props">
				<div class="vf-node vf-node--end" :class="{ 'vf-node--error': hasIssue(props.id, 'error') }">
					<div class="vf-node__header">
						{{ props.data.label || t('procest', 'End') }}
					</div>
				</div>
			</template>
		</VueFlow>
	</div>
</template>

<script>
import { VueFlow } from '@vue-flow/core'
import { Background } from '@vue-flow/background'
import { Controls } from '@vue-flow/controls'

import '@vue-flow/core/dist/style.css'
import '@vue-flow/core/dist/theme-default.css'
import '@vue-flow/controls/dist/style.css'

/**
 * WorkflowCanvas — vue-flow wrapper that renders nodes + transitions.
 *
 * Props:
 *   - nodes    : array of vue-flow nodes (id, type, position, data)
 *   - edges    : array of vue-flow edges (id, source, target, label, data)
 *   - issues   : array of {nodeId|edgeId, level, code, message} from validator
 *   - readOnly : boolean — disables drag/connect/select interactions
 *
 * Events:
 *   - update:nodes / update:edges  — graph mutations (working copy)
 *   - select-node / select-edge    — selection changes
 *   - drop-node                    — palette drop with {type, position}
 */
export default {
	name: 'WorkflowCanvas',
	components: {
		VueFlow,
		Background,
		Controls,
	},
	props: {
		nodes: {
			type: Array,
			default: () => [],
		},
		edges: {
			type: Array,
			default: () => [],
		},
		issues: {
			type: Array,
			default: () => [],
		},
		readOnly: {
			type: Boolean,
			default: false,
		},
	},
	data() {
		return {
			localNodes: [...this.nodes],
			localEdges: [...this.edges],
			defaultEdgeOptions: {
				type: 'smoothstep',
				animated: false,
			},
		}
	},
	watch: {
		nodes: {
			/** @spec openspec/specs/workflow-definition-model/spec.md */
			handler(next) {
				this.localNodes = [...next]
			},
			deep: true,
		},
		edges: {
			/** @spec openspec/specs/workflow-definition-model/spec.md */
			handler(next) {
				this.localEdges = [...next]
			},
			deep: true,
		},
	},
	methods: {
		hasIssue(id, level) {
			return this.issues.some((issue) => (issue.nodeId === id || issue.edgeId === id) && issue.level === level)
		},
		/** @spec openspec/specs/workflow-definition-model/spec.md */
		onDrop(event) {
			if (this.readOnly) {
				return
			}
			const type = event.dataTransfer.getData('application/vue-flow-type')
			if (!type) {
				return
			}
			const bounds = this.$refs.canvasRef.getBoundingClientRect()
			const position = {
				x: event.clientX - bounds.left,
				y: event.clientY - bounds.top,
			}
			this.$emit('drop-node', { type, position })
		},
		/** @spec openspec/specs/workflow-definition-model/spec.md */
		onNodesChange() {
			this.$emit('update:nodes', this.localNodes)
		},
		/** @spec openspec/specs/workflow-definition-model/spec.md */
		onEdgesChange() {
			this.$emit('update:edges', this.localEdges)
		},
		/** @spec openspec/specs/workflow-definition-model/spec.md */
		onConnect(params) {
			if (this.readOnly) {
				return
			}
			this.$emit('connect', params)
		},
		/** @spec openspec/specs/workflow-definition-model/spec.md */
		onNodeClick({ node }) {
			this.$emit('select-node', node)
		},
		/** @spec openspec/specs/workflow-definition-model/spec.md */
		onEdgeClick({ edge }) {
			this.$emit('select-edge', edge)
		},
		/** @spec openspec/specs/workflow-definition-model/spec.md */
		onPaneClick() {
			this.$emit('select-node', null)
		},
	},
}
</script>

<style scoped>
.workflow-canvas {
	position: relative;
	flex: 1;
	min-width: 0;
	min-height: 480px;
	background: var(--color-background-dark, #fafafa);
}

.workflow-canvas :deep(.vue-flow__node) {
	font-family: var(--font-face, sans-serif);
}

.vf-node {
	background: var(--color-main-background, #fff);
	border: 1px solid var(--color-primary-element, #0080c2);
	border-radius: var(--border-radius, 4px);
	padding: 10px 16px;
	min-width: 120px;
	text-align: center;
	font-size: 13px;
}

.vf-node--end {
	border-width: 3px;
	border-style: double;
}

.vf-node--decision .vf-node__diamond {
	transform: rotate(45deg);
	display: inline-block;
	padding: 10px;
	border: 1px solid var(--color-primary-element, #0080c2);
	background: var(--color-main-background, #fff);
}

.vf-node--decision .vf-node__diamond > * {
	transform: rotate(-45deg);
}

.vf-node--parallel {
	border-left-width: 6px;
}

.vf-node--error {
	border-color: var(--color-error, #c33);
	box-shadow: 0 0 0 2px rgba(204, 51, 51, 0.2);
}

.vf-node__header {
	font-weight: 600;
}

.vf-node__body {
	font-size: 11px;
	color: var(--color-text-maxcontrast, #888);
	margin-top: 4px;
}
</style>
