<template>
	<div
		class="workflow-editor"
		@dragover.prevent
		@drop="onCanvasDrop">
		<!-- Validation banner -->
		<WorkflowValidationBanner
			:errors="validationErrors"
			@dismiss="validationErrors = []" />

		<div class="workflow-editor__layout">
			<!-- Palette -->
			<WorkflowPalette
				class="workflow-editor__palette"
				@drag-start="onPaletteDragStart" />

			<!-- Canvas -->
			<div
				ref="canvas"
				class="workflow-editor__canvas"
				:style="canvasStyle"
				@mousedown="onCanvasMouseDown"
				@mousemove="onCanvasMouseMove"
				@mouseup="onCanvasMouseUp"
				@wheel="onCanvasWheel">
				<!-- SVG layer for transitions -->
				<svg
					class="workflow-editor__svg"
					:viewBox="svgViewBox">
					<!-- Existing transitions -->
					<WorkflowTransitionArrow
						v-for="transition in transitions"
						:key="transition.id"
						:transition="transition"
						:from-pos="getNodeCenter(transition.fromStatus)"
						:to-pos="getNodeCenter(transition.toStatus)"
						:selected="selectedTransition === transition.id"
						@click="selectTransition(transition.id)"
						@dblclick="editTransition(transition.id)" />

					<!-- Connection being drawn -->
					<line
						v-if="drawingConnection"
						:x1="drawingConnection.startX"
						:y1="drawingConnection.startY"
						:x2="drawingConnection.currentX"
						:y2="drawingConnection.currentY"
						stroke="var(--color-primary)"
						stroke-width="2"
						stroke-dasharray="5,5" />
				</svg>

				<!-- Status nodes -->
				<WorkflowNode
					v-for="status in statusNodes"
					:key="status.id"
					:status="status"
					:steps="getStepsForStatus(status.id)"
					:position="nodePositions[status.id] || { x: 100, y: 100 }"
					:selected="selectedNode === status.id"
					@select="selectNode(status.id)"
					@drag-start="onNodeDragStart(status.id, $event)"
					@connection-start="onConnectionStart(status.id, $event)"
					@connection-end="onConnectionEnd(status.id)"
					@step-click="onStepClick"
					@add-step="onAddStep(status.id)" />
			</div>
		</div>

		<!-- Side panels -->
		<StepConfigPanel
			v-if="selectedStep"
			:step="selectedStep"
			:role-types="roleTypes"
			:read-only="isPublished"
			@update="onStepUpdate"
			@close="selectedStep = null" />

		<TransitionConfigPanel
			v-if="selectedTransitionData"
			:transition="selectedTransitionData"
			:role-types="roleTypes"
			:document-types="documentTypes"
			@update="onTransitionUpdate"
			@delete="onTransitionDelete"
			@close="selectedTransition = null" />
	</div>
</template>

<script>
import WorkflowNode from './components/WorkflowNode.vue'
import WorkflowTransitionArrow from './components/WorkflowTransitionArrow.vue'
import WorkflowPalette from './components/WorkflowPalette.vue'
import WorkflowValidationBanner from './components/WorkflowValidationBanner.vue'
import StepConfigPanel from './components/StepConfigPanel.vue'
import TransitionConfigPanel from './components/TransitionConfigPanel.vue'
import { useWorkflowStore } from '../../store/modules/workflow.js'
import { useObjectStore } from '../../store/modules/object.js'

export default {
	name: 'WorkflowEditor',
	components: {
		WorkflowNode,
		WorkflowTransitionArrow,
		WorkflowPalette,
		WorkflowValidationBanner,
		StepConfigPanel,
		TransitionConfigPanel,
	},
	props: {
		caseTypeId: {
			type: String,
			required: true,
		},
		templateId: {
			type: String,
			default: null,
		},
	},
	data() {
		return {
			/** @type {Array} Status type objects for the case type */
			statusNodes: [],
			/** @type {Array} Role type objects for the case type */
			roleTypes: [],
			/** @type {Array} Document type objects for the case type */
			documentTypes: [],
			/** @type {string|null} Selected node UUID */
			selectedNode: null,
			/** @type {string|null} Selected transition UUID */
			selectedTransition: null,
			/** @type {object|null} Selected step object */
			selectedStep: null,
			/** @type {Array} Validation errors */
			validationErrors: [],
			/** @type {object|null} Connection being drawn */
			drawingConnection: null,
			/** @type {object|null} Node being dragged */
			draggingNode: null,
			/** @type {boolean} Canvas is being panned */
			panning: false,
			/** @type {object} Pan offset */
			panOffset: { x: 0, y: 0 },
			/** @type {object} Pan start position */
			panStart: { x: 0, y: 0 },
			/** @type {number} Zoom level */
			zoom: 1,
			/** @type {string|null} Palette item being dragged */
			paletteDragType: null,
		}
	},
	computed: {
		workflowStore() {
			return useWorkflowStore()
		},
		objectStore() {
			return useObjectStore()
		},
		steps() {
			return this.workflowStore.parsedSteps
		},
		transitions() {
			return this.workflowStore.parsedTransitions
		},
		nodePositions() {
			return this.workflowStore.parsedNodePositions
		},
		selectedTransitionData() {
			if (!this.selectedTransition) return null
			return this.transitions.find((t) => t.id === this.selectedTransition) || null
		},
		/**
		 * True when the loaded workflow template is published (not a draft).
		 *
		 * Used to render the step `Geavanceerd` panel read-only per
		 * process-step-configuration REQ-PSC-7-002. Falls back to false
		 * (editable) when no template is loaded yet, preserving the
		 * pre-existing creation flow.
		 *
		 * @returns {boolean} Whether the current template is published.
		 */
		isPublished() {
			const tpl = this.workflowStore.currentTemplate || null
			if (!tpl) return false
			// isDraft true ⇒ editable; isDraft false ⇒ published/deprecated
			return tpl.isDraft === false
		},
		canvasStyle() {
			return {
				transform: `translate(${this.panOffset.x}px, ${this.panOffset.y}px) scale(${this.zoom})`,
				transformOrigin: '0 0',
			}
		},
		svgViewBox() {
			return '0 0 2000 1500'
		},
	},
	async mounted() {
		await this.loadData()
	},
	methods: {
		async loadData() {
			// Load status types for this case type
			this.statusNodes = await this.objectStore.fetchCollection('statusType', {
				'_filters[caseType]': this.caseTypeId,
				_limit: 100,
				_order: { order: 'asc' },
			}) || []

			// Load role types
			this.roleTypes = await this.objectStore.fetchCollection('roleType', {
				'_filters[caseType]': this.caseTypeId,
				_limit: 100,
			}) || []

			// Load document types
			this.documentTypes = await this.objectStore.fetchCollection('documentType', {
				'_filters[caseType]': this.caseTypeId,
				_limit: 100,
			}) || []

			// Load or create workflow template
			if (this.templateId) {
				await this.workflowStore.getTemplate(this.templateId)
			}

			// Assign default positions for nodes without positions
			this.ensureNodePositions()
		},

		ensureNodePositions() {
			const positions = { ...this.nodePositions }
			let changed = false
			this.statusNodes.forEach((status, index) => {
				if (!positions[status.id]) {
					positions[status.id] = {
						x: 100 + (index % 4) * 250,
						y: 100 + Math.floor(index / 4) * 200,
					}
					changed = true
				}
			})
			if (changed && this.workflowStore.currentTemplate) {
				this.workflowStore.currentTemplate.nodePositions = JSON.stringify(positions)
			}
		},

		getStepsForStatus(statusId) {
			return this.steps
				.filter((s) => s.status === statusId)
				.sort((a, b) => a.order - b.order)
		},

		getNodeCenter(statusId) {
			const pos = this.nodePositions[statusId]
			if (!pos) return { x: 0, y: 0 }
			return {
				x: pos.x + 100, // half of node width (200px)
				y: pos.y + 40, // half of node height (80px)
			}
		},

		// --- Selection ---
		selectNode(statusId) {
			this.selectedNode = statusId
			this.selectedTransition = null
			this.selectedStep = null
		},

		selectTransition(transitionId) {
			this.selectedTransition = transitionId
			this.selectedNode = null
			this.selectedStep = null
		},

		editTransition(transitionId) {
			this.selectTransition(transitionId)
		},

		onStepClick(step) {
			this.selectedStep = { ...step }
			this.selectedNode = null
			this.selectedTransition = null
		},

		// --- Node drag ---
		onNodeDragStart(statusId, event) {
			this.draggingNode = {
				statusId,
				offsetX: event.offsetX || 0,
				offsetY: event.offsetY || 0,
			}
		},

		onCanvasMouseMove(event) {
			if (this.draggingNode) {
				const rect = this.$refs.canvas.getBoundingClientRect()
				const x = (event.clientX - rect.left - this.panOffset.x) / this.zoom
					- this.draggingNode.offsetX
				const y = (event.clientY - rect.top - this.panOffset.y) / this.zoom
					- this.draggingNode.offsetY
				this.workflowStore.updateNodePosition(
					this.draggingNode.statusId,
					Math.max(0, x),
					Math.max(0, y),
				)
			} else if (this.drawingConnection) {
				const rect = this.$refs.canvas.getBoundingClientRect()
				this.drawingConnection.currentX = (event.clientX - rect.left - this.panOffset.x) / this.zoom
				this.drawingConnection.currentY = (event.clientY - rect.top - this.panOffset.y) / this.zoom
			} else if (this.panning) {
				this.panOffset.x = event.clientX - this.panStart.x
				this.panOffset.y = event.clientY - this.panStart.y
			}
		},

		onCanvasMouseUp() {
			if (this.draggingNode) {
				this.draggingNode = null
				this.$emit('dirty')
			}
			if (this.drawingConnection) {
				this.drawingConnection = null
			}
			this.panning = false
		},

		onCanvasMouseDown(event) {
			// Only pan if clicking empty canvas area
			if (event.target === this.$refs.canvas || event.target.classList.contains('workflow-editor__svg')) {
				this.panning = true
				this.panStart = {
					x: event.clientX - this.panOffset.x,
					y: event.clientY - this.panOffset.y,
				}
				this.selectedNode = null
				this.selectedTransition = null
				this.selectedStep = null
			}
		},

		onCanvasWheel(event) {
			event.preventDefault()
			const delta = event.deltaY > 0 ? -0.1 : 0.1
			this.zoom = Math.max(0.3, Math.min(2, this.zoom + delta))
		},

		// --- Connection drawing ---
		onConnectionStart(statusId, event) {
			const center = this.getNodeCenter(statusId)
			this.drawingConnection = {
				fromStatus: statusId,
				startX: center.x,
				startY: center.y,
				currentX: center.x,
				currentY: center.y,
			}
		},

		onConnectionEnd(statusId) {
			if (this.drawingConnection && this.drawingConnection.fromStatus !== statusId) {
				this.workflowStore.addTransition(
					this.drawingConnection.fromStatus,
					statusId,
				)
				this.$emit('dirty')
			}
			this.drawingConnection = null
		},

		// --- Palette drag & drop ---
		onPaletteDragStart(type) {
			this.paletteDragType = type
		},

		async onCanvasDrop(event) {
			if (this.paletteDragType === 'status') {
				const rect = this.$refs.canvas.getBoundingClientRect()
				const x = (event.clientX - rect.left - this.panOffset.x) / this.zoom
				const y = (event.clientY - rect.top - this.panOffset.y) / this.zoom

				// Create a new status type
				const statusType = await this.objectStore.saveObject('statusType', {
					name: t('procest', 'New status'),
					caseType: this.caseTypeId,
					order: this.statusNodes.length + 1,
					isFinal: false,
				})

				if (statusType) {
					this.statusNodes.push(statusType)
					this.workflowStore.updateNodePosition(statusType.id, x, y)
					this.$emit('dirty')
				}
			}
			this.paletteDragType = null
		},

		// --- Step management ---
		onAddStep(statusId) {
			const step = this.workflowStore.addStep(statusId)
			this.selectedStep = { ...step }
			this.$emit('dirty')
		},

		onStepUpdate(updatedStep) {
			this.workflowStore.updateStep(updatedStep.id, updatedStep)
			this.selectedStep = { ...updatedStep }
			this.$emit('dirty')
		},

		// --- Transition management ---
		onTransitionUpdate(updatedTransition) {
			this.workflowStore.updateTransition(updatedTransition.id, updatedTransition)
			this.$emit('dirty')
		},

		onTransitionDelete(transitionId) {
			this.workflowStore.removeTransition(transitionId)
			this.selectedTransition = null
			this.$emit('dirty')
		},

		// --- Public API ---
		validate() {
			this.validationErrors = this.workflowStore.validateWorkflow()
			return this.validationErrors.length === 0
		},
	},
}
</script>

<style scoped>
.workflow-editor {
	display: flex;
	flex-direction: column;
	height: 100%;
	min-height: 500px;
}

.workflow-editor__layout {
	display: flex;
	flex: 1;
	overflow: hidden;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
}

.workflow-editor__palette {
	width: 200px;
	flex-shrink: 0;
	border-right: 1px solid var(--color-border);
}

.workflow-editor__canvas {
	flex: 1;
	position: relative;
	overflow: hidden;
	background:
		linear-gradient(var(--color-border-dark) 1px, transparent 1px),
		linear-gradient(90deg, var(--color-border-dark) 1px, transparent 1px);
	background-size: 20px 20px;
	cursor: grab;
}

.workflow-editor__canvas:active {
	cursor: grabbing;
}

.workflow-editor__svg {
	position: absolute;
	top: 0;
	left: 0;
	width: 100%;
	height: 100%;
	pointer-events: none;
}

.workflow-editor__svg line,
.workflow-editor__svg path,
.workflow-editor__svg g {
	pointer-events: auto;
}
</style>
