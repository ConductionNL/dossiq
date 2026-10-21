<template>
	<div class="workflow-tab">
		<!-- Version selector and actions -->
		<div class="workflow-tab__toolbar">
			<div class="workflow-tab__version-selector">
				<label for="workflow-tab-version">{{ t('dossiq', 'Version:') }}</label>
				<select
					id="workflow-tab-version"
					v-model="selectedVersionId"
					class="workflow-tab__select"
					@change="onVersionChange">
					<option v-for="v in versions" :key="v.id" :value="v.id">
						v{{ v.version }}
						{{ v.isActive ? '(active)' : '' }}
						{{ v.isDraft ? '(draft)' : '' }}
					</option>
				</select>
			</div>

			<div class="workflow-tab__actions">
				<!-- Create new workflow if none exists -->
				<NcButton
					v-if="versions.length === 0"
					variant="primary"
					@click="createWorkflow">
					{{ t('dossiq', 'Create workflow') }}
				</NcButton>

				<!-- Publish draft -->
				<NcButton
					v-if="currentIsDraft"
					variant="secondary"
					:disabled="publishing"
					@click="publish">
					<template v-if="publishing" #icon>
						<NcLoadingIcon :size="20" />
					</template>
					{{ t('dossiq', 'Publish') }}
				</NcButton>

				<!-- Edit published (create draft copy) -->
				<NcButton
					v-if="currentIsPublished && !hasDraft"
					variant="secondary"
					@click="editPublished">
					{{ t('dossiq', 'Edit') }}
				</NcButton>

				<!-- Save -->
				<NcButton
					v-if="currentIsDraft && dirty"
					variant="primary"
					:disabled="saving"
					@click="save">
					<template v-if="saving" #icon>
						<NcLoadingIcon :size="20" />
					</template>
					{{ t('dossiq', 'Save') }}
				</NcButton>

				<!-- Import / Export -->
				<NcButton variant="tertiary" @click="exportWorkflow">
					{{ t('dossiq', 'Export') }}
				</NcButton>
				<NcButton variant="tertiary" @click="triggerImport">
					{{ t('dossiq', 'Import') }}
				</NcButton>
				<input
					ref="importInput"
					type="file"
					accept=".json"
					style="display: none"
					@change="handleImport" />
			</div>
		</div>

		<!-- Publish errors -->
		<div v-if="publishErrors.length > 0" class="workflow-tab__errors">
			<p>
				<strong>{{ t('dossiq', 'Cannot publish:') }}</strong>
			</p>
			<ul>
				<li v-for="(err, i) in publishErrors" :key="i">
					{{ err.message }}
				</li>
			</ul>
		</div>

		<!-- Import report -->
		<div v-if="importReport" class="workflow-tab__import-report">
			<p>
				<strong>{{ t('dossiq', 'Import validation:') }}</strong>
			</p>
			<ul>
				<li v-for="(type, i) in importReport.statusTypes" :key="'s' + i">
					{{ t('dossiq', 'Missing status type: {name}', { name: type }) }}
				</li>
				<li v-for="(type, i) in importReport.roleTypes" :key="'r' + i">
					{{ t('dossiq', 'Missing role type: {name}', { name: type }) }}
				</li>
			</ul>
			<NcButton variant="secondary" @click="importReport = null">
				{{ t('dossiq', 'Cancel import') }}
			</NcButton>
		</div>

		<!-- Version notice -->
		<div v-if="versionNotice" class="workflow-tab__notice">
			{{ versionNotice }}
		</div>

		<!-- Editor: the library's state machine editor, configured by the
		     manifest widget's `content.editor`. Statuses are the states,
		     the template's transitions and steps the rest. -->
		<CnStateMachineEditor
			v-if="selectedVersionId && typesLoaded"
			ref="editor"
			class="workflow-tab__editor"
			:states="statusNodes"
			:transitions="workflowStore.parsedTransitions"
			:steps="workflowStore.parsedSteps"
			:positions="workflowStore.parsedNodePositions"
			:config="editorConfig"
			:optionSets="optionSets"
			:locked="isPublished"
			:translate="translateLabel"
			@stateAdd="onStateAdd"
			@stateMove="onStateMove"
			@stateRemove="onStateRemove"
			@transitionAdd="onTransitionAdd"
			@transitionUpdate="onTransitionUpdate"
			@transitionRemove="onTransitionRemove"
			@stepAdd="onStepAdd"
			@stepUpdate="onStepUpdate"
			@stepRemove="onStepRemove"
			@stepReorder="onStepReorder" />

		<!-- Empty state -->
		<div v-if="versions.length === 0 && !loading" class="workflow-tab__empty">
			<p>{{ t('dossiq', 'No workflow defined for this case type yet.') }}</p>
			<p>
				{{
					t(
						'dossiq',
						'Create a workflow to define process steps and status transitions.',
					)
				}}
			</p>
		</div>

		<NcLoadingIcon v-if="loading" />
	</div>
</template>

<script>
import { CnStateMachineEditor } from '@conduction/nextcloud-vue'
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import { useObjectStore } from '../../../store/modules/object.js'
import { useWorkflowStore } from '../../../store/modules/workflow.js'

/**
 * The workflow of a case type: its versions, publishing, import and export
 * around the library's state machine editor. Registered as the
 * `CaseTypeWorkflowEditorWidget` widget on CaseTypeDetail; the editor's fields
 * and texts come from the widget's `content.editor` in the manifest.
 */
export default {
	name: 'WorkflowTab',
	components: {
		CnStateMachineEditor,
		NcButton,
		NcLoadingIcon,
	},

	props: {
		/** The case type, from the widget host. */
		objectId: {
			type: String,
			default: '',
		},

		/** The manifest widget's content; `editor` configures the canvas. */
		content: {
			type: Object,
			default: () => ({}),
		},
	},

	data() {
		return {
			/** @type {Array} The case type's statusType objects, the machine's states. */
			statusNodes: [],
			/** @type {Array} The case type's roleType objects. */
			roleTypes: [],
			/** @type {Array} The case type's documentType objects. */
			documentTypes: [],
			typesLoaded: false,
			selectedVersionId: null,
			loading: false,
			saving: false,
			publishing: false,
			dirty: false,
			publishErrors: [],
			importReport: null,
		}
	},

	computed: {
		/**
		 * The case type this widget is bound to: the host's object, else the route's id.
		 *
		 * @return {string} The case type id.
		 * @spec openspec/changes/case-type-legacy-editor-retire/specs/case-types/spec.md
		 */
		caseTypeId() {
			return String(this.objectId || this.$route?.params?.id || '')
		},

		/**
		 * @return {object} The editor's config from the manifest widget.
		 * @spec openspec/specs/visual-workflow-editor/spec.md
		 */
		editorConfig() {
			return this.content?.editor || {}
		},

		/**
		 * The option lists the editor's fields point at.
		 *
		 * @return {object} Roles by id, document types by name.
		 * @spec openspec/specs/visual-workflow-editor/spec.md
		 */
		optionSets() {
			return {
				roles: this.roleTypes.map((role) => ({ value: role.id, label: role.name })),
				documentTypeNames: this.documentTypes.map((type) => ({ value: type.name, label: type.name })),
			}
		},

		/**
		 * A published (not draft) version: its step SLA, required fields and
		 * escalation are read-only (process-step-configuration REQ-PSC-7-002).
		 *
		 * @return {boolean} Whether the loaded template is published.
		 * @spec openspec/specs/workflow-definition-model/spec.md
		 */
		isPublished() {
			return this.workflowStore.currentTemplate?.isDraft === false
		},

		/** @spec openspec/specs/workflow-definition-model/spec.md */
		workflowStore() {
			return useWorkflowStore()
		},

		/** @spec openspec/specs/workflow-definition-model/spec.md */
		objectStore() {
			return useObjectStore()
		},

		/** @spec openspec/specs/workflow-definition-model/spec.md */
		versions() {
			return this.workflowStore.versions
		},

		/** @spec openspec/specs/workflow-definition-model/spec.md */
		currentTemplate() {
			return this.workflowStore.currentTemplate
		},

		/** @spec openspec/specs/workflow-definition-model/spec.md */
		currentIsDraft() {
			return this.currentTemplate?.isDraft === true
		},

		/** @spec openspec/specs/workflow-definition-model/spec.md */
		currentIsPublished() {
			return this.currentTemplate && !this.currentTemplate.isDraft
		},

		hasDraft() {
			return this.versions.some((v) => v.isDraft)
		},

		/** @spec openspec/specs/workflow-definition-model/spec.md */
		versionNotice() {
			if (!this.currentTemplate) return null
			const active = this.workflowStore.activeVersion
			if (
				active
				&& this.currentTemplate.id !== active.id
				&& !this.currentTemplate.isDraft
			) {
				return t(
					'dossiq',
					'Viewing version {version}. Active version is {active}.',
					{
						version: this.currentTemplate.version,
						active: active.version,
					},
				)
			}
			return null
		},
	},

	/** @spec openspec/specs/workflow-definition-model/spec.md */
	async mounted() {
		await Promise.all([this.loadTypes(), this.loadVersions()])
		this.ensureNodePositions()
	},

	methods: {
		/**
		 * Translate a text from the manifest in dossiq's domain.
		 *
		 * @param {string} text The English source text.
		 * @return {string} The translation.
		 * @spec openspec/specs/visual-workflow-editor/spec.md
		 */
		translateLabel(text) {
			return t('dossiq', text)
		},

		/**
		 * Load the case type's statuses, roles and document types.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/workflow-definition-model/spec.md
		 */
		async loadTypes() {
			const [statusNodes, roleTypes, documentTypes] = await Promise.all([
				this.objectStore.fetchCollection('statusType', {
					caseType: this.caseTypeId,
					_limit: 100,
					_order: { order: 'asc' },
				}),
				this.objectStore.fetchCollection('roleType', { caseType: this.caseTypeId, _limit: 100 }),
				this.objectStore.fetchCollection('documentType', { caseType: this.caseTypeId, _limit: 100 }),
			])
			this.statusNodes = statusNodes || []
			this.roleTypes = roleTypes || []
			this.documentTypes = documentTypes || []
			this.typesLoaded = true
		},

		/**
		 * Give every status without a stored position its grid slot, so the
		 * template saves the layout the canvas shows.
		 *
		 * @spec openspec/specs/workflow-definition-model/spec.md
		 */
		ensureNodePositions() {
			const template = this.workflowStore.currentTemplate
			if (!template) return
			const positions = { ...this.workflowStore.parsedNodePositions }
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
			if (changed) {
				template.nodePositions = JSON.stringify(positions)
			}
		},

		/**
		 * A status dropped on the canvas, or added from the keyboard.
		 *
		 * @param {{position: object, done: (id: string) => void}} payload Where, and the callback.
		 * @spec openspec/specs/visual-workflow-editor/spec.md#requirement-drag-and-drop-workflow-canvas
		 */
		async onStateAdd({ position, done }) {
			const statusType = await this.objectStore.saveObject('statusType', {
				name: t('dossiq', 'New status'),
				caseType: this.caseTypeId,
				order: this.statusNodes.length + 1,
				isFinal: false,
			})
			if (!statusType) return
			this.statusNodes.push(statusType)
			this.workflowStore.updateNodePosition(statusType.id, position.x, position.y)
			this.dirty = true
			done(statusType.id)
		},

		/**
		 * @param {{id: string, x: number, y: number}} move The status and its new place.
		 * @spec openspec/specs/visual-workflow-editor/spec.md#requirement-drag-and-drop-workflow-canvas
		 */
		onStateMove({ id, x, y }) {
			const current = this.workflowStore.parsedNodePositions[id]
			const next = { x: Math.max(0, x), y: Math.max(0, y) }
			if (current && current.x === next.x && current.y === next.y) return
			this.workflowStore.updateNodePosition(id, next.x, next.y)
			this.dirty = true
		},

		/**
		 * Delete a status the user confirmed: the statusType object, then its
		 * steps, transitions and position in the working copy. The editor has
		 * already refused to delete the last final status.
		 *
		 * @param {{id: string, done: (ok: boolean) => void}} payload The status and the callback.
		 * @spec openspec/specs/visual-workflow-editor/spec.md#requirement-drag-and-drop-workflow-canvas
		 */
		async onStateRemove({ id, done }) {
			const ok = await this.objectStore.deleteObject('statusType', id)
			if (!ok) {
				done(false)
				return
			}
			this.workflowStore.removeStatusNode(id)
			this.statusNodes = this.statusNodes.filter((status) => status.id !== id)
			this.dirty = true
			done(true)
		},

		/**
		 * @param {{from: string, to: string}} transition The new transition's ends.
		 * @spec openspec/specs/visual-workflow-editor/spec.md#requirement-keyboard-operable-canvas
		 */
		onTransitionAdd({ from, to }) {
			this.workflowStore.addTransition(from, to)
			this.dirty = true
		},

		/**
		 * @param {object} transition The edited transition, complete.
		 * @spec openspec/specs/status-transition-engine/spec.md
		 */
		onTransitionUpdate(transition) {
			const current = this.workflowStore.parsedTransitions.find((candidate) => candidate.id === transition.id)
			this.workflowStore.updateTransition(transition.id, this.replacing(current, transition))
			this.dirty = true
		},

		/**
		 * @param {string} id The transition.
		 * @spec openspec/specs/visual-workflow-editor/spec.md#requirement-keyboard-operable-canvas
		 */
		onTransitionRemove(id) {
			this.workflowStore.removeTransition(id)
			this.dirty = true
		},

		/**
		 * @param {{state: string, done: (id: string) => void}} payload The status and the callback.
		 * @spec openspec/specs/visual-workflow-editor/spec.md#requirement-step-configuration-panel
		 */
		onStepAdd({ state, done }) {
			const step = this.workflowStore.addStep(state)
			this.dirty = true
			done(step.id)
		},

		/**
		 * @param {object} step The edited step, complete.
		 * @spec openspec/specs/visual-workflow-editor/spec.md#requirement-step-configuration-panel
		 */
		onStepUpdate(step) {
			const current = this.workflowStore.parsedSteps.find((candidate) => candidate.id === step.id)
			this.workflowStore.updateStep(step.id, this.replacing(current, step))
			this.dirty = true
		},

		/**
		 * @param {string} id The step.
		 * @spec openspec/specs/visual-workflow-editor/spec.md#requirement-step-configuration-panel
		 */
		onStepRemove(id) {
			this.workflowStore.removeStep(id)
			this.dirty = true
		},

		/**
		 * @param {{state: string, ids: Array<string>}} payload A status's steps in their new order.
		 * @spec openspec/specs/visual-workflow-editor/spec.md#requirement-step-configuration-panel
		 */
		onStepReorder({ ids }) {
			ids.forEach((id, index) => this.workflowStore.updateStep(id, { order: index + 1 }))
			this.dirty = true
		},

		/**
		 * The update that makes a record equal `next`: the store merges, so a key
		 * the editor dropped (an emptied step config) is set to undefined, which
		 * the JSON the template stores leaves out.
		 *
		 * @param {object|undefined} current The stored record.
		 * @param {object} next The edited record.
		 * @return {object} The update.
		 * @spec openspec/specs/visual-workflow-editor/spec.md#requirement-step-configuration-panel
		 */
		replacing(current, next) {
			const update = { ...next }
			Object.keys(current || {}).forEach((key) => {
				if (!(key in update)) update[key] = undefined
			})
			return update
		},

		/** @spec openspec/specs/workflow-definition-model/spec.md */
		async loadVersions() {
			this.loading = true
			await this.workflowStore.listVersions(this.caseTypeId)

			// Auto-select: prefer draft, then active, then latest
			if (this.versions.length > 0) {
				const draft = this.workflowStore.draftVersion
				const active = this.workflowStore.activeVersion
				const target = draft || active || this.versions[0]
				this.selectedVersionId = target.id
				await this.workflowStore.getTemplate(target.id)
			}
			this.loading = false
		},

		/** @spec openspec/specs/workflow-definition-model/spec.md */
		async onVersionChange() {
			if (this.selectedVersionId) {
				await this.workflowStore.getTemplate(this.selectedVersionId)
				this.ensureNodePositions()
				this.dirty = false
				this.publishErrors = []
			}
		},

		/** @spec openspec/specs/workflow-definition-model/spec.md */
		async createWorkflow() {
			this.loading = true
			const template = await this.workflowStore.createTemplate(
				this.caseTypeId,
				t('dossiq', 'Workflow'),
			)
			if (template) {
				this.selectedVersionId = template.id
				await this.workflowStore.listVersions(this.caseTypeId)
			}
			this.loading = false
		},

		/** @spec openspec/specs/workflow-definition-model/spec.md */
		async save() {
			this.saving = true
			await this.workflowStore.saveTemplate(this.currentTemplate)
			this.dirty = false
			this.saving = false
		},

		/** @spec openspec/specs/workflow-definition-model/spec.md */
		async publish() {
			this.publishErrors = []
			this.publishing = true

			// Validate via editor
			if (this.$refs.editor && !this.$refs.editor.validate()) {
				this.publishErrors = this.workflowStore.validationErrors
				this.publishing = false
				return
			}

			// Save first if dirty
			if (this.dirty) {
				await this.save()
			}

			const result = await this.workflowStore.publishVersion(
				this.selectedVersionId,
				this.statusNodes,
			)
			if (!result) {
				this.publishErrors = this.workflowStore.validationErrors
			} else {
				await this.workflowStore.listVersions(this.caseTypeId)
			}
			this.publishing = false
		},

		/** @spec openspec/specs/workflow-definition-model/spec.md */
		async editPublished() {
			this.loading = true
			const draft = await this.workflowStore.createDraftFromVersion(
				this.selectedVersionId,
			)
			if (draft) {
				this.selectedVersionId = draft.id
				await this.workflowStore.listVersions(this.caseTypeId)
			}
			this.loading = false
		},

		/** @spec openspec/specs/workflow-definition-model/spec.md */
		async exportWorkflow() {
			if (!this.currentTemplate) return

			// Fetch type data for name mapping
			const statusTypes =
				(await this.objectStore.fetchCollection('statusType', {
					caseType: this.caseTypeId,
					_limit: 100,
				})) || []
			const roleTypes =
				(await this.objectStore.fetchCollection('roleType', {
					caseType: this.caseTypeId,
					_limit: 100,
				})) || []
			const docTypes =
				(await this.objectStore.fetchCollection('documentType', {
					caseType: this.caseTypeId,
					_limit: 100,
				})) || []

			const exportData = this.workflowStore.exportWorkflow(
				this.currentTemplate,
				statusTypes,
				roleTypes,
				docTypes,
			)

			const blob = new Blob([JSON.stringify(exportData, null, 2)], {
				type: 'application/json',
			})
			const url = URL.createObjectURL(blob)
			const a = document.createElement('a')
			a.href = url
			a.download = `${this.currentTemplate.title.toLowerCase().replace(/\s+/g, '-')}-v${this.currentTemplate.version}-workflow.json`
			a.click()
			URL.revokeObjectURL(url)
		},

		/** @spec openspec/specs/workflow-definition-model/spec.md */
		triggerImport() {
			this.$refs.importInput.click()
		},

		/**
		 * @param {Event} event The originating DOM event.
		 * @spec openspec/specs/workflow-definition-model/spec.md
		 */
		async handleImport(event) {
			const file = event.target.files[0]
			if (!file) return

			try {
				const text = await file.text()
				const importData = JSON.parse(text)

				// Fetch type data for UUID mapping
				const statusTypes =
					(await this.objectStore.fetchCollection('statusType', {
						caseType: this.caseTypeId,
						_limit: 100,
					})) || []
				const roleTypes =
					(await this.objectStore.fetchCollection('roleType', {
						caseType: this.caseTypeId,
						_limit: 100,
					})) || []
				const docTypes =
					(await this.objectStore.fetchCollection('documentType', {
						caseType: this.caseTypeId,
						_limit: 100,
					})) || []

				const result = await this.workflowStore.importWorkflow(
					importData,
					this.caseTypeId,
					statusTypes,
					roleTypes,
					docTypes,
				)

				if (!result.success) {
					this.importReport = result.missingTypes
				} else {
					this.selectedVersionId = result.template.id
					await this.workflowStore.listVersions(this.caseTypeId)
					this.importReport = null
				}
			} catch (err) {
				console.error('Import failed:', err)
			}

			// Reset file input
			event.target.value = ''
		},
	},
}
</script>

<style scoped>
.workflow-tab__toolbar {
	display: flex;
	align-items: center;
	justify-content: space-between;
	margin-bottom: 12px;
	flex-wrap: wrap;
	gap: 8px;
}

.workflow-tab__version-selector {
	display: flex;
	align-items: center;
	gap: 8px;
}

.workflow-tab__version-selector label {
	font-weight: 600;
	font-size: 13px;
}

.workflow-tab__select {
	padding: 6px 8px;
	border: 1px solid var(--color-border-dark);
	border-radius: var(--border-radius);
	font-size: 13px;
}

.workflow-tab__actions {
	display: flex;
	gap: 8px;
	flex-wrap: wrap;
}

.workflow-tab__errors {
	background: var(--color-error-light, rgba(var(--color-error-rgb), 0.1));
	border: 1px solid var(--color-error);
	border-radius: var(--border-radius);
	padding: 12px;
	margin-bottom: 12px;
}

.workflow-tab__errors ul {
	margin: 4px 0 0 16px;
}

.workflow-tab__import-report {
	background: var(--color-warning-light, rgba(var(--color-warning-rgb), 0.1));
	border: 1px solid var(--color-warning);
	border-radius: var(--border-radius);
	padding: 12px;
	margin-bottom: 12px;
}

.workflow-tab__notice {
	background: var(--color-primary-element-light);
	border-radius: var(--border-radius);
	padding: 8px 12px;
	margin-bottom: 12px;
	font-size: 13px;
}

.workflow-tab__empty {
	text-align: center;
	padding: 40px;
	color: var(--color-text-maxcontrast);
}
</style>
