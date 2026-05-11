<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  - SPDX-License-Identifier: EUPL-1.2
  -->
<template>
	<div class="visual-workflow-editor">
		<!-- Toolbar -->
		<header class="visual-workflow-editor__toolbar">
			<div class="visual-workflow-editor__title">
				<h2>
					{{ template ? (template.title || template.name || t('procest', 'Workflow template')) : t('procest', 'Workflow editor') }}
				</h2>
				<span v-if="template" class="visual-workflow-editor__meta">
					{{ t('procest', 'version {v}', { v: template.version || 1 }) }}
					·
					{{ template.isDraft ? t('procest', 'Draft') : t('procest', 'Published') }}
				</span>
			</div>
			<div class="visual-workflow-editor__actions">
				<NcButton :disabled="!template || saving" @click="onExport">
					{{ t('procest', 'Export JSON') }}
				</NcButton>
				<NcButton :disabled="!template || saving" @click="onImport">
					{{ t('procest', 'Import JSON') }}
				</NcButton>
				<NcButton
					type="secondary"
					:disabled="!isDirty || saving || readOnly"
					@click="onSaveDraft">
					{{ saving ? t('procest', 'Saving…') : t('procest', 'Save draft') }}
				</NcButton>
				<NcButton
					type="primary"
					:disabled="!template || saving || hasErrors || readOnly"
					@click="onPublish">
					{{ t('procest', 'Publish') }}
				</NcButton>
			</div>
		</header>

		<!-- Loading / Error -->
		<div v-if="loading" class="visual-workflow-editor__loading">
			<NcLoadingIcon :size="32" />
			<p>{{ t('procest', 'Loading workflow…') }}</p>
		</div>
		<div v-else-if="loadError" class="visual-workflow-editor__error">
			<p>{{ loadError }}</p>
		</div>

		<!-- Workspace: palette | canvas | properties -->
		<div v-else class="visual-workflow-editor__workspace">
			<NodePalette v-if="!readOnly" />

			<div class="visual-workflow-editor__main">
				<WorkflowCanvas
					:nodes="nodes"
					:edges="edges"
					:issues="issues"
					:read-only="readOnly"
					@update:nodes="onNodesUpdate"
					@update:edges="onEdgesUpdate"
					@select-node="onSelectNode"
					@select-edge="onSelectEdge"
					@drop-node="onDropNode"
					@connect="onConnect" />

				<!-- Validation overlay -->
				<div v-if="issues.length" class="visual-workflow-editor__problems">
					<h4>
						{{ t('procest', 'Problems') }}
						<span class="visual-workflow-editor__problems-count">({{ issues.length }})</span>
					</h4>
					<ul>
						<li
							v-for="(issue, i) in issues"
							:key="i"
							:class="`visual-workflow-editor__problem visual-workflow-editor__problem--${issue.level}`">
							<strong>{{ issue.code }}</strong>: {{ issue.message }}
						</li>
					</ul>
				</div>
			</div>

			<NodeProperties
				v-if="selectedNode"
				:node="selectedNode"
				:issues="issues"
				:read-only="readOnly"
				@update="onNodePropsUpdate" />
			<EdgeProperties
				v-else-if="selectedEdge"
				:edge="selectedEdge"
				:issues="issues"
				:read-only="readOnly"
				@update="onEdgePropsUpdate" />
			<NodeProperties v-else :node="null" :issues="issues" :read-only="readOnly" />
		</div>

		<!-- Hidden file input for import -->
		<input
			ref="importInput"
			type="file"
			accept="application/json"
			style="display: none"
			@change="onImportFile">
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import NodePalette from './NodePalette.vue'
import WorkflowCanvas from './WorkflowCanvas.vue'
import NodeProperties from './NodeProperties.vue'
import EdgeProperties from './EdgeProperties.vue'
import { useWorkflowStore } from '../../store/modules/workflow.js'
import { validateGraph } from './validator.js'

/**
 * VisualWorkflowEditor — manifest-mounted custom page for graph-based
 * workflowTemplate authoring.
 *
 * The page mounts at `/settings/workflow-templates/:id/edit` and receives
 * the workflow template id as a route param (prop `id`).
 *
 * Data flow:
 *   1. mount → workflowStore.loadTemplate(id)
 *   2. local working copy is split into vue-flow nodes + edges for rendering
 *   3. Each mutation rebuilds the working copy and runs validateGraph()
 *   4. Save → workflowStore.saveDraft() (PUT through createObjectStore)
 *   5. Publish → workflowStore.publishVersion(id) (gated by hasErrors)
 *
 * NB: this component is the ONLY accepted custom page added by the
 * visual-workflow-editor change. All other settings continue to flow
 * through manifest index/detail types.
 */
export default {
	name: 'VisualWorkflowEditor',
	components: {
		NcButton,
		NcLoadingIcon,
		NodePalette,
		WorkflowCanvas,
		NodeProperties,
		EdgeProperties,
	},
	props: {
		id: {
			type: String,
			default: '',
		},
	},
	data() {
		return {
			loading: true,
			loadError: '',
			saving: false,
			isDirty: false,
			template: null,
			workingCopy: {
				steps: [],
				transitions: [],
				layout: {},
			},
			selectedNode: null,
			selectedEdge: null,
			issues: [],
			autosaveTimer: null,
		}
	},
	computed: {
		readOnly() {
			return !!this.template && this.template.isDraft === false
		},
		hasErrors() {
			return this.issues.some((i) => i.level === 'error')
		},
		nodes() {
			const layout = this.workingCopy.layout || {}
			const stepNodes = (this.workingCopy.steps || []).map((step, idx) => {
				const id = step.id || step.status || `step-${idx}`
				return {
					id,
					type: step.isFinal ? 'end' : (step.kind || 'status'),
					position: layout[id] || { x: 80 + (idx * 220), y: 80 },
					data: {
						label: step.title || step.label || step.status || id,
						status: step.status || '',
						isFinal: !!step.isFinal,
						stepCount: Array.isArray(step.steps) ? step.steps.length : undefined,
						guard: step.guard || '',
					},
				}
			})
			return stepNodes
		},
		edges() {
			return (this.workingCopy.transitions || []).map((tr, idx) => {
				const id = tr.id || `edge-${idx}`
				return {
					id,
					source: tr.fromStatus,
					target: tr.toStatus,
					label: tr.label || '',
					data: {
						label: tr.label || '',
						guards: tr.guards || [],
						allowedRoles: tr.allowedRoles || [],
					},
				}
			})
		},
	},
	watch: {
		workingCopy: {
			handler() {
				this.issues = validateGraph(this.workingCopy)
				this.scheduleAutosave()
			},
			deep: true,
		},
	},
	async mounted() {
		await this.load()
	},
	beforeDestroy() {
		if (this.autosaveTimer) {
			clearTimeout(this.autosaveTimer)
		}
	},
	methods: {
		async load() {
			this.loading = true
			this.loadError = ''
			try {
				const store = useWorkflowStore()
				const template = await store.getTemplate(this.id)
				if (!template) {
					this.loadError = t('procest', 'Workflow template not found.')
					return
				}
				this.template = template
				this.workingCopy = this.parseTemplate(template)
				// Validation runs in workingCopy watcher.
			} catch (err) {
				this.loadError = err && err.message ? err.message : t('procest', 'Failed to load workflow.')
			} finally {
				this.loading = false
			}
		},
		parseTemplate(template) {
			const parseField = (raw, fallback) => {
				if (Array.isArray(raw) || typeof raw === 'object') {
					return raw
				}
				if (typeof raw === 'string' && raw.length) {
					try {
						return JSON.parse(raw)
					} catch (e) {
						return fallback
					}
				}
				return fallback
			}
			return {
				steps: parseField(template.steps, []),
				transitions: parseField(template.transitions, []),
				layout: parseField(template.layout, {}),
			}
		},
		serializeWorkingCopy() {
			return {
				steps: this.workingCopy.steps,
				transitions: this.workingCopy.transitions,
				layout: this.workingCopy.layout,
			}
		},
		onNodesUpdate(nodes) {
			// Capture position changes back into the layout block.
			const layout = { ...(this.workingCopy.layout || {}) }
			let dirty = false
			for (const node of nodes) {
				if (!node.position) continue
				const prev = layout[node.id]
				if (!prev || prev.x !== node.position.x || prev.y !== node.position.y) {
					layout[node.id] = { x: node.position.x, y: node.position.y }
					dirty = true
				}
			}
			if (dirty) {
				this.workingCopy = { ...this.workingCopy, layout }
				this.isDirty = true
			}
		},
		onEdgesUpdate(edges) {
			// Keep transitions in working copy aligned with rendered edges
			// (e.g. when vue-flow signals deletes from the user).
			const ids = new Set(edges.map((e) => e.id))
			const next = (this.workingCopy.transitions || []).filter((tr, idx) => {
				const id = tr.id || `edge-${idx}`
				return ids.has(id)
			})
			if (next.length !== (this.workingCopy.transitions || []).length) {
				this.workingCopy = { ...this.workingCopy, transitions: next }
				this.isDirty = true
			}
		},
		onSelectNode(node) {
			this.selectedNode = node
			this.selectedEdge = null
		},
		onSelectEdge(edge) {
			this.selectedEdge = edge
			this.selectedNode = null
		},
		onDropNode({ type, position }) {
			const id = `step-${Date.now()}`
			const newStep = {
				id,
				title: type === 'end' ? t('procest', 'End') : t('procest', 'New status'),
				kind: type,
				status: '',
				isFinal: type === 'end',
				steps: [],
			}
			this.workingCopy = {
				...this.workingCopy,
				steps: [...(this.workingCopy.steps || []), newStep],
				layout: { ...(this.workingCopy.layout || {}), [id]: position },
			}
			this.isDirty = true
		},
		onConnect({ source, target }) {
			if (!source || !target) return
			const id = `tr-${Date.now()}`
			const next = [...(this.workingCopy.transitions || []), {
				id,
				fromStatus: source,
				toStatus: target,
				label: '',
				guards: [],
				allowedRoles: [],
			}]
			this.workingCopy = { ...this.workingCopy, transitions: next }
			this.isDirty = true
		},
		onNodePropsUpdate({ nodeId, patch }) {
			const steps = (this.workingCopy.steps || []).map((step, idx) => {
				const id = step.id || step.status || `step-${idx}`
				if (id !== nodeId) return step
				const next = { ...step, ...patch }
				if (Object.prototype.hasOwnProperty.call(patch, 'label')) {
					next.title = patch.label
				}
				return next
			})
			this.workingCopy = { ...this.workingCopy, steps }
			this.isDirty = true
		},
		onEdgePropsUpdate({ edgeId, patch }) {
			const transitions = (this.workingCopy.transitions || []).map((tr, idx) => {
				const id = tr.id || `edge-${idx}`
				if (id !== edgeId) return tr
				return { ...tr, ...patch }
			})
			this.workingCopy = { ...this.workingCopy, transitions }
			this.isDirty = true
		},
		scheduleAutosave() {
			if (!this.isDirty || !this.template || this.readOnly) return
			if (this.autosaveTimer) clearTimeout(this.autosaveTimer)
			this.autosaveTimer = setTimeout(() => this.onSaveDraft(true), 2000)
		},
		async onSaveDraft(silent = false) {
			if (!this.template || this.readOnly) return
			this.saving = true
			try {
				const store = useWorkflowStore()
				const payload = {
					id: this.template.id,
					...this.serializeWorkingCopy(),
				}
				const saved = await store.saveTemplate(payload)
				if (saved) {
					this.template = saved
					this.isDirty = false
				}
			} catch (err) {
				if (!silent) {
					this.loadError = err && err.message ? err.message : t('procest', 'Save failed.')
				}
			} finally {
				this.saving = false
			}
		},
		async onPublish() {
			if (!this.template || this.hasErrors || this.readOnly) return
			if (this.isDirty) {
				await this.onSaveDraft()
			}
			this.saving = true
			try {
				const store = useWorkflowStore()
				const published = await store.publishVersion(this.template.id)
				if (published) {
					this.template = published
				}
			} catch (err) {
				this.loadError = err && err.message ? err.message : t('procest', 'Publish failed.')
			} finally {
				this.saving = false
			}
		},
		onExport() {
			const data = JSON.stringify({
				title: this.template && this.template.title,
				version: this.template && this.template.version,
				...this.serializeWorkingCopy(),
			}, null, 2)
			const blob = new Blob([data], { type: 'application/json' })
			const url = URL.createObjectURL(blob)
			const a = document.createElement('a')
			a.href = url
			a.download = `workflow-${this.template ? this.template.id : 'export'}.json`
			a.click()
			URL.revokeObjectURL(url)
		},
		onImport() {
			this.$refs.importInput.click()
		},
		async onImportFile(event) {
			const file = event.target.files && event.target.files[0]
			if (!file) return
			try {
				const text = await file.text()
				const parsed = JSON.parse(text)
				this.workingCopy = {
					steps: parsed.steps || [],
					transitions: parsed.transitions || [],
					layout: parsed.layout || {},
				}
				this.isDirty = true
			} catch (err) {
				this.loadError = t('procest', 'Import failed: invalid JSON.')
			} finally {
				event.target.value = ''
			}
		},
	},
}
</script>

<style scoped>
.visual-workflow-editor {
	display: flex;
	flex-direction: column;
	height: 100%;
	min-height: 600px;
}

.visual-workflow-editor__toolbar {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 10px 16px;
	border-bottom: 1px solid var(--color-border, #ddd);
	background: var(--color-main-background, #fff);
	gap: 16px;
}

.visual-workflow-editor__title h2 {
	margin: 0;
	font-size: 16px;
}

.visual-workflow-editor__meta {
	font-size: 12px;
	color: var(--color-text-maxcontrast, #888);
}

.visual-workflow-editor__actions {
	display: flex;
	gap: 8px;
}

.visual-workflow-editor__workspace {
	display: flex;
	flex: 1;
	min-height: 0;
}

.visual-workflow-editor__main {
	display: flex;
	flex-direction: column;
	flex: 1;
	min-width: 0;
	position: relative;
}

.visual-workflow-editor__loading,
.visual-workflow-editor__error {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	padding: 48px;
	color: var(--color-text-maxcontrast, #888);
}

.visual-workflow-editor__problems {
	max-height: 180px;
	overflow-y: auto;
	border-top: 1px solid var(--color-border, #ddd);
	background: var(--color-background-dark, #fafafa);
	padding: 8px 12px;
}

.visual-workflow-editor__problems h4 {
	margin: 0 0 4px;
	font-size: 13px;
}

.visual-workflow-editor__problems-count {
	font-weight: 400;
	color: var(--color-text-maxcontrast, #888);
	margin-left: 4px;
}

.visual-workflow-editor__problems ul {
	list-style: none;
	margin: 0;
	padding: 0;
}

.visual-workflow-editor__problem {
	padding: 4px 0;
	font-size: 12px;
}

.visual-workflow-editor__problem--error {
	color: var(--color-error, #c33);
}

.visual-workflow-editor__problem--warning {
	color: var(--color-warning, #c80);
}
</style>
