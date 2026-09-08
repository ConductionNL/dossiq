<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<template>
	<NcDialog
		v-if="open"
		:name="t('dossiq', 'Generate document')"
		size="normal"
		:canClose="!submitting"
		@closing="onClose">
		<div class="generate-document">
			<div v-if="!generated" class="generate-document__form">
				<p class="generate-document__intro">
					{{
						t(
							'dossiq',
							'Pick a template. The letter is rendered with this case and filed on the Documents tab as a draft.',
						)
					}}
				</p>
				<NcLoadingIcon v-if="loadingTemplates" :size="32" />
				<NcSelect
					v-else
					v-model="templateId"
					data-testid="generate-document-template"
					:options="templateOptions"
					:inputLabel="t('dossiq', 'Template')"
					label="label"
					:reduce="(option) => option.value"
					:placeholder="t('dossiq', 'Select a template')" />
				<NcNoteCard v-if="error" type="error">
					{{ error }}
				</NcNoteCard>
			</div>

			<NcNoteCard v-else type="success">
				{{ t('dossiq', 'Document added to the case.') }}
			</NcNoteCard>
		</div>

		<template #actions>
			<NcButton :disabled="submitting" @click="onClose">
				{{ t('dossiq', 'Cancel') }}
			</NcButton>
			<NcButton
				v-if="!generated"
				type="primary"
				data-testid="generate-document-confirm"
				:disabled="submitting || !templateId"
				@click="onGenerate">
				{{ t('dossiq', 'Generate') }}
			</NcButton>
			<NcButton v-else type="primary" @click="onClose">
				{{ t('dossiq', 'Close') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcDialog,
	NcLoadingIcon,
	NcNoteCard,
	NcSelect,
} from '@nextcloud/vue'

/**
 * Generate document — the case page's Generate document header action.
 *
 * The action is `type: open-modal` and NOT `run-action`, because
 * `actionsDispatcher.js` does not dispatch a `run-action` yet (design D4).
 * That has one consequence worth stating: the dispatcher forwards
 * `action.props` VERBATIM, resolving no `@`-tokens, so `caseId: "@objectId"`
 * arrives as the literal string. The dialog therefore never trusts the prop
 * on its own — it falls back to the route's own id, which is the same case in
 * every path that opens this dialog.
 *
 * The templates come from `TemplateController#index`, the single library, and
 * the chosen one is rendered and filed by `MergeTemplateHandler` with NO
 * `targetField` — the same branch `DossiqMergeTemplateNode` will run once the
 * library can dispatch the action directly.
 *
 * @spec openspec/specs/beschikking-generatie/spec.md
 * @spec openspec/specs/template-library/spec.md
 */
export default {
	name: 'BeschikkingComposerDialog',
	components: {
		NcButton,
		NcDialog,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
	},

	props: {
		open: {
			type: Boolean,
			default: false,
		},

		// May arrive as the unresolved `@objectId` token; see resolvedCaseId.
		caseId: {
			type: String,
			default: '',
		},
	},

	emits: ['close', 'generated'],
	data() {
		return {
			templates: [],
			templateId: null,
			loadingTemplates: false,
			generated: null,
			submitting: false,
			error: '',
		}
	},

	computed: {
		/**
		 * The case this dialog files a document on.
		 *
		 * An `open-modal` action's `props` are forwarded verbatim, so a prop
		 * still holding an `@` token is not a case id — the route is.
		 *
		 * @return {string} The case id, or empty string.
		 * @spec openspec/specs/beschikking-generatie/spec.md
		 */
		resolvedCaseId() {
			const fromProp = this.caseId || ''
			if (fromProp !== '' && !fromProp.startsWith('@')) {
				return fromProp
			}
			return (this.$route && this.$route.params && this.$route.params.id) || ''
		},

		/**
		 * The library templates, by name, for the picker.
		 *
		 * @return {Array} The picker options.
		 * @spec openspec/specs/template-library/spec.md
		 */
		templateOptions() {
			return this.templates.map((template) => ({
				value: template.id,
				label: template.title || template.id,
			}))
		},
	},

	watch: {
		open: {
			immediate: true,
			/**
			 * Load the library the moment the dialog opens.
			 *
			 * @param {boolean} isOpen Whether the dialog is showing.
			 * @spec openspec/specs/template-library/spec.md
			 */
			handler(isOpen) {
				if (isOpen) {
					this.fetchTemplates()
				}
			},
		},
	},

	methods: {
		/**
		 * Fetch the templates TemplateController#index returns.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/template-library/spec.md
		 */
		async fetchTemplates() {
			this.loadingTemplates = true
			this.error = ''
			try {
				const { data } = await axios.get(
					generateUrl('/apps/dossiq/api/templates'),
				)
				this.templates = data.results || []
			} catch {
				this.templates = []
				this.error = this.t('dossiq', 'The template library is unavailable.')
			} finally {
				this.loadingTemplates = false
			}
		},

		/**
		 * Render the chosen template over the case and file the result.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/beschikking-generatie/spec.md
		 */
		async onGenerate() {
			if (!this.templateId || this.resolvedCaseId === '') {
				return
			}
			this.submitting = true
			this.error = ''
			try {
				const { data } = await axios.post(
					generateUrl(
						`/apps/dossiq/api/cases/${encodeURIComponent(this.resolvedCaseId)}/dossier/generate`,
					),
					{ templateId: this.templateId },
				)
				this.generated = data
				this.$emit('generated', data)
			} catch {
				this.error = this.t('dossiq', 'The document could not be generated.')
			} finally {
				this.submitting = false
			}
		},

		/**
		 * Close the dialog.
		 *
		 * @spec openspec/specs/beschikking-generatie/spec.md
		 */
		onClose() {
			this.$emit('close')
		},
	},
}
</script>

<style scoped>
.generate-document {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding-block-end: 12px;
}

.generate-document__intro {
	color: var(--color-text-maxcontrast);
}
</style>
