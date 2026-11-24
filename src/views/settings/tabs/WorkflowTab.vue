<template>
	<div class="workflow-tab">
		<!-- The flow page's header row (board DqZaaktypeFlow): case type, version, Check, More, Save -->
		<header v-if="boardHeader" class="workflow-tab__header" data-testid="workflow-flow-header">
			<h2 class="workflow-tab__title" data-testid="workflow-flow-title">
				{{ caseTypeTitle }}
			</h2>
			<CnStatusBadge
				v-if="currentTemplate"
				:label="t('dossiq', 'Version {version}', { version: currentTemplate.version })"
				size="small"
				data-testid="workflow-flow-version" />
			<CnStatusBadge
				v-if="currentTemplate"
				:label="stateLabel"
				:variant="currentTemplate.isDraft ? 'warning' : 'success'"
				size="small"
				data-testid="workflow-flow-state" />
			<span class="workflow-tab__header-actions">
				<NcButton
					variant="secondary"
					:disabled="!selectedVersionId"
					data-testid="workflow-flow-check"
					@click="check">
					{{ t('dossiq', 'Check') }}
				</NcButton>
				<span class="workflow-tab__more">
					<NcButton
						variant="tertiary"
						:aria-expanded="moreOpen ? 'true' : 'false'"
						data-testid="workflow-flow-more"
						@click="moreOpen = !moreOpen">
						{{ t('dossiq', 'More') }}
					</NcButton>
					<ul v-if="moreOpen" class="workflow-tab__more-menu" data-testid="workflow-flow-more-menu">
						<li v-if="versions.length === 0">
							<button type="button" @click="runMore(createWorkflow)">
								{{ t('dossiq', 'Create workflow') }}
							</button>
						</li>
						<li v-if="currentIsDraft">
							<button type="button" :disabled="publishing" @click="runMore(publish)">
								{{ t('dossiq', 'Publish') }}
							</button>
						</li>
						<li v-if="currentIsPublished && !hasDraft">
							<button type="button" @click="runMore(editPublished)">
								{{ t('dossiq', 'Edit') }}
							</button>
						</li>
						<li>
							<button type="button" @click="runMore(exportWorkflow)">
								{{ t('dossiq', 'Download') }}
							</button>
						</li>
						<li>
							<button type="button" @click="runMore(triggerImport)">
								{{ t('dossiq', 'Import') }}
							</button>
						</li>
					</ul>
				</span>
				<NcButton
					variant="primary"
					:disabled="!(currentIsDraft && dirty) || saving"
					data-testid="workflow-flow-save"
					@click="save">
					{{ t('dossiq', 'Save') }}
				</NcButton>
			</span>
		</header>
		<p v-if="checkNotice" class="workflow-tab__notice" data-testid="workflow-flow-check-notice">
			{{ checkNotice }}
		</p>

		<!-- Version selector and actions -->
		<div class="workflow-tab__toolbar">
			<div class="workflow-tab__version-selector">
				<label>{{ t('dossiq', 'Version:') }}</label>
				<select
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

			<div v-if="!boardHeader" class="workflow-tab__actions">
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
					{{ t('dossiq', 'Download') }}
				</NcButton>
				<NcButton variant="tertiary" @click="triggerImport">
					{{ t('dossiq', 'Import') }}
				</NcButton>
			</div>
		</div>

		<input
			ref="importInput"
			type="file"
			accept=".json"
			style="display: none"
			@change="handleImport">

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

		<!-- Canvas | List switch (board DqZaaktypeFlow) -->
		<div
			v-if="selectedVersionId"
			class="workflow-tab__views"
			data-testid="workflow-view-switch">
			<div class="workflow-tab__switch" role="group" :aria-label="t('dossiq', 'View')">
				<button
					v-for="option in viewOptions"
					:key="option.id"
					type="button"
					class="workflow-tab__switch-option" :class="[{ 'workflow-tab__switch-option--active': view === option.id }]"
					:aria-pressed="view === option.id ? 'true' : 'false'"
					:data-testid="'workflow-view-' + option.id"
					@click="view = option.id">
					{{ option.label }}
				</button>
			</div>
			<p class="workflow-tab__views-line">
				{{ t('dossiq', 'The same flow and the same steps as on the canvas; what you change here is there too.') }}
			</p>
		</div>

		<!-- Editor -->
		<WorkflowEditor
			v-if="selectedVersionId"
			ref="editor"
			:caseTypeId="caseTypeId"
			:templateId="selectedVersionId"
			:view="view"
			@dirty="dirty = true" />

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
import { CnStatusBadge } from '@conduction/nextcloud-vue'
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import WorkflowEditor from '../WorkflowEditor.vue'
import { objectStoreAccess } from '../../../mixins/objectStore.js'
import { useWorkflowStore } from '../../../store/modules/workflow.js'
import { caseTypeParts } from '../../../utils/caseTypeRows.js'

export default {
	name: 'WorkflowTab',
	components: {
		CnStatusBadge,
		NcButton,
		NcLoadingIcon,
		WorkflowEditor,
	},

	mixins: [objectStoreAccess],

	props: {
		caseTypeId: {
			type: String,
			required: true,
		},

		/** Draw the flow page's header row (board DqZaaktypeFlow) instead of the toolbar buttons. */
		boardHeader: {
			type: Boolean,
			default: false,
		},
	},

	data() {
		return {
			selectedVersionId: null,
			loading: false,
			saving: false,
			publishing: false,
			dirty: false,
			publishErrors: [],
			importReport: null,
			view: 'canvas',
			caseTypeTitle: '',
			moreOpen: false,
			checkNotice: null,
		}
	},

	computed: {
		/**
		 * The two views of one flow definition.
		 *
		 * @return {Array<{id: string, label: string}>} Canvas and List.
		 * @spec openspec/changes/flow-page-list-beside-canvas/specs/visual-workflow-editor/spec.md#requirement-a-case-types-flow-reads-as-a-list-beside-the-canvas
		 */
		viewOptions() {
			return [
				{ id: 'canvas', label: t('dossiq', 'Canvas') },
				{ id: 'list', label: t('dossiq', 'List') },
			]
		},

		/** @spec openspec/specs/workflow-definition-model/spec.md */
		workflowStore() {
			return useWorkflowStore()
		},

		/** @spec openspec/specs/workflow-definition-model/spec.md */
		versions() {
			return this.workflowStore.versions
		},

		/** @spec openspec/specs/workflow-definition-model/spec.md */
		currentTemplate() {
			return this.workflowStore.currentTemplate
		},

		/**
		 * The state pill of the version shown: Draft, Active or Published.
		 *
		 * @return {string} The pill's word.
		 * @spec openspec/changes/flow-page-list-beside-canvas/specs/visual-workflow-editor/spec.md#requirement-a-case-types-flow-reads-as-a-list-beside-the-canvas
		 */
		stateLabel() {
			if (!this.currentTemplate) return ''
			if (this.currentTemplate.isDraft) return t('dossiq', 'Draft')
			return this.currentTemplate.isActive ? t('dossiq', 'Active') : t('dossiq', 'Published')
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

	async mounted() {
		if (this.boardHeader) {
			this.loadTitle()
		}
		await this.loadVersions()
	},

	methods: {
		/**
		 * Read the case type's title for the header row. A failed read leaves
		 * the generic word, so the page still names what it shows.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/flow-page-list-beside-canvas/specs/visual-workflow-editor/spec.md#requirement-a-case-types-flow-reads-as-a-list-beside-the-canvas
		 */
		async loadTitle() {
			this.caseTypeTitle = t('dossiq', 'Flow')
			try {
				const caseType = await this.objectStore.fetchObject('caseType', this.caseTypeId)
				const title = caseType?.title || caseType?.name
				if (title) {
					this.caseTypeTitle = String(title)
				}
			} catch {
				// The generic word stays: the page still names what it shows.
			}
		},

		/**
		 * Check the flow without publishing it: the editor's validation banner
		 * shows what is wrong, and a clean flow says so.
		 *
		 * @return {boolean} Whether the flow passed.
		 * @spec openspec/changes/flow-page-list-beside-canvas/specs/visual-workflow-editor/spec.md#requirement-a-case-types-flow-reads-as-a-list-beside-the-canvas
		 */
		check() {
			const valid = this.$refs.editor ? this.$refs.editor.validate() : false
			this.checkNotice = valid ? t('dossiq', 'No problems found in this flow.') : null
			return valid
		},

		/**
		 * Close the More menu and run what was chosen in it.
		 *
		 * @param {() => (void|Promise<void>)} action One of this tab's actions.
		 * @return {void|Promise<void>} What the action returns.
		 * @spec openspec/changes/flow-page-list-beside-canvas/specs/visual-workflow-editor/spec.md#requirement-a-case-types-flow-reads-as-a-list-beside-the-canvas
		 */
		runMore(action) {
			this.moreOpen = false
			return action()
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
				this.$refs.editor?.statusNodes || [],
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
			const [statusTypes, roleTypes, docTypes] = await caseTypeParts(
				this.objectStore,
				this.caseTypeId,
			)

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
				const [statusTypes, roleTypes, docTypes] = await caseTypeParts(
					this.objectStore,
					this.caseTypeId,
				)

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

.workflow-tab__header {
	align-items: center;
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	margin-bottom: 12px;
}

.workflow-tab__title {
	margin: 0;
}


.workflow-tab__header-actions {
	display: flex;
	gap: 8px;
	margin-inline-start: auto;
}

.workflow-tab__more {
	position: relative;
}

.workflow-tab__more-menu {
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	box-shadow: 0 2px 8px var(--color-box-shadow);
	inset-inline-end: 0;
	list-style: none;
	margin: 4px 0 0;
	min-width: 180px;
	padding: 4px 0;
	position: absolute;
	z-index: 10;
}

.workflow-tab__more-menu button {
	background: none;
	border: none;
	cursor: pointer;
	margin: 0;
	padding: 8px 16px;
	text-align: start;
	width: 100%;
}

.workflow-tab__views {
	margin-bottom: 16px;
}

.workflow-tab__switch {
	border: 1px solid var(--color-border-dark);
	border-radius: var(--border-radius-large);
	display: inline-flex;
	overflow: hidden;
}

.workflow-tab__switch-option {
	background: none;
	border: none;
	cursor: pointer;
	margin: 0;
	padding: 4px 16px;
}

.workflow-tab__switch-option--active {
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
}

.workflow-tab__views-line {
	color: var(--color-text-maxcontrast);
	margin: 6px 0 0;
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
