<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<template>
	<CnDialog
		v-if="open"
		:name="t('dossiq', 'Generate document')"
		:eyebrow="eyebrow"
		:subtitle="t('dossiq', 'A letter or decision from a template, filled in with the details of this case.')"
		width="form"
		:noClose="submitting"
		@closing="onClose">
		<div class="generate-document">
			<div v-if="!generated" class="generate-document__form">
				<CnChoiceCards
					v-model="templateId"
					layout="rows"
					data-testid="generate-document-template"
					:label="t('dossiq', 'Template')"
					:loading="loadingTemplates"
					:options="templateOptions"
					:emptyText="t('dossiq', 'The template library has no templates yet.')"
					:descriptionLines="1" />

				<section v-if="kase" class="generate-document__fills">
					<h3 class="generate-document__fills-title">
						{{ t('dossiq', 'What the template fills in') }}
					</h3>
					<dl class="generate-document__fills-grid">
						<div v-if="kase.initiatorDisplayName">
							<dt>{{ t('dossiq', 'To') }}</dt>
							<dd>{{ kase.initiatorDisplayName }}</dd>
						</div>
						<div>
							<dt>{{ t('dossiq', 'Case') }}</dt>
							<dd>{{ [kase.identifier, kase.title].filter(Boolean).join(', ') }}</dd>
						</div>
						<div v-if="kase.resultPublicLabel">
							<dt>{{ t('dossiq', 'Decision') }}</dt>
							<dd>{{ kase.resultPublicLabel }}</dd>
						</div>
						<div v-for="fill in chosenTemplateFills" :key="fill.label">
							<dt>{{ t('dossiq', fill.label) }}</dt>
							<dd>{{ fill.value }}</dd>
						</div>
					</dl>
				</section>

				<NcNoteCard type="info">
					<template #icon>
						<FileDocumentCheckOutline :size="18" />
					</template>
					{{ t('dossiq', 'The document is added to the case as a draft, under Documents. You sign and send it afterwards, from Decisions.') }}
				</NcNoteCard>
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
				variant="primary"
				data-testid="generate-document-confirm"
				:disabled="submitting || !templateId"
				@click="onGenerate">
				<template #icon>
					<FileDocumentEditOutline :size="20" />
				</template>
				{{ t('dossiq', 'Generate') }}
			</NcButton>
			<NcButton v-else variant="primary" @click="onClose">
				{{ t('dossiq', 'Close') }}
			</NcButton>
		</template>
	</CnDialog>
</template>

<script>
import { CnChoiceCards, CnDialog } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcNoteCard,
} from '@nextcloud/vue'
import FileDocumentCheckOutline from 'vue-material-design-icons/FileDocumentCheckOutline.vue'
import FileDocumentEditOutline from 'vue-material-design-icons/FileDocumentEditOutline.vue'
import { caseEyebrow, fetchCaseSummary } from '../services/caseSummary.js'
import { templateFillRows } from '../utils/templateFills.js'

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
 * the chosen one is rendered by Filinq and filed on the case by
 * `CaseDocumentGenerationService`, the same filing a flow's
 * `filinq.generate-document` step gets.
 *
 * @spec openspec/specs/beschikking-generatie/spec.md
 * @spec openspec/specs/template-library/spec.md
 */
export default {
	name: 'BeschikkingComposerDialog',
	components: {
		CnChoiceCards,
		CnDialog,
		FileDocumentCheckOutline,
		FileDocumentEditOutline,
		NcButton,
		NcNoteCard,
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
			kase: null,
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
		 * The library templates that render a document, by name, for the picker.
		 *
		 * The library also holds case-type templates; only a `document`
		 * template carries the body CaseDocumentGenerationService renders.
		 *
		 * @return {Array} The picker options.
		 * @spec openspec/specs/template-library/spec.md
		 */
		/**
		 * What the chosen template fills in beyond the addressee and the case,
		 * as its library entry names it (`fills`).
		 *
		 * @return {Array<{label: string, value: string}>} The rows.
		 * @spec openspec/specs/generate-fills-per-template/spec.md#requirement-the-generate-dialog-shows-what-the-chosen-template-fills-in
		 */
		chosenTemplateFills() {
			const chosen = this.templates.find((template) => template.id === this.templateId)
			return templateFillRows(chosen?.fills, this.kase)
		},

		templateOptions() {
			return this.templates.filter((template) => template.category === 'document').map((template) => ({
				value: template.id,
				label: template.title || template.id,
				description: [
					template.version ? t('dossiq', 'Version {version}', { version: template.version }) : '',
					template.description || '',
				].filter(Boolean).join(' · '),
			}))
		},

		/**
		 * @return {string} The context line above the title.
		 * @spec openspec/specs/beschikking-generatie/spec.md
		 */
		eyebrow() {
			return caseEyebrow(t('dossiq', 'Document'), this.kase)
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
					fetchCaseSummary(this.resolvedCaseId).then((kase) => {
						this.kase = kase
					})
				}
			},
		},
	},

	methods: {
		t,
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
				if (!this.templateId && this.templateOptions.length > 0) {
					this.templateId = this.templateOptions[0].value
				}
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

.generate-document__form {
	display: flex;
	flex-direction: column;
	gap: 18px;
}

.generate-document__fills {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 14px 16px;
	border-radius: var(--border-radius-large);
	background: color-mix(in srgb, var(--color-background-hover) 60%, var(--color-main-background));
}

.generate-document__fills-title {
	margin: 0;
	font-size: 14px;
	font-weight: 700;
	line-height: 1.2;
}

.generate-document__fills-grid {
	display: grid;
	grid-template-columns: repeat(2, minmax(0, 1fr));
	gap: 12px 24px;
	margin: 0;
	/* Core gives a dl 12px of padding at the top. */
	padding: 0;
}

.generate-document__fills-grid > div {
	padding-block-end: 10px;
	border-block-end: 1px solid var(--color-border);
}

/* Core's apps.css draws every dt/dd inline-block with a fixed, end-aligned dt. */
.generate-document__fills-grid dt,
.generate-document__fills-grid dd {
	display: block;
	width: auto;
	padding: 0;
	line-height: normal;
	text-align: start;
	white-space: normal;
}

.generate-document__fills-grid dt {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.generate-document__fills-grid dd {
	margin: 3px 0 0;
	font-size: 15px;
	font-weight: 500;
}

.generate-document__intro {
	color: var(--color-text-maxcontrast);
}
</style>
