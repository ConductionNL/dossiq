<template>
	<div class="email-composer">
		<h4 class="email-composer__title">
			{{ t('procest', 'Send Email') }}
		</h4>

		<!-- Template selector -->
		<div v-if="templates.length > 0" class="email-composer__template-select">
			<label>{{ t('procest', 'Template') }}</label>
			<NcSelect
				v-model="selectedTemplate"
				:options="templates"
				:aria-label-combobox="t('procest', 'Template')"
				label="name"
				trackBy="id"
				:placeholder="t('procest', 'Select template or compose ad-hoc...')"
				@update:modelValue="onTemplateSelected" />
		</div>

		<!-- Email form -->
		<div class="email-composer__form">
			<div class="form-group">
				<label for="email-composer-to">{{ t('procest', 'To') }} *</label>
				<NcTextField
					id="email-composer-to"
					:modelValue="form.to"
					type="email"
					:placeholder="t('procest', 'recipient@example.nl')"
					@update:modelValue="(v) => (form.to = v)" />
			</div>

			<div class="form-group">
				<label for="email-composer-subject"
					>{{ t('procest', 'Subject') }} *</label
				>
				<NcTextField
					id="email-composer-subject"
					:modelValue="form.subject"
					@update:modelValue="(v) => (form.subject = v)" />
			</div>

			<div class="form-group">
				<label for="email-composer-body">{{ t('procest', 'Body') }} *</label>
				<textarea
					id="email-composer-body"
					v-model="form.body"
					rows="8"
					:placeholder="
						t(
							'procest',
							'Email body... Use {{variableName}} for template variables.',
						)
					" />
			</div>

			<!-- Unresolved variables warning -->
			<div v-if="unresolvedVars.length > 0" class="email-composer__warning">
				{{ t('procest', 'Unresolved variables:') }}
				<span
					v-for="v in unresolvedVars"
					:key="v"
					class="email-composer__unresolved">
					{{ formatVariable(v) }}
				</span>
			</div>

			<!-- Available variables -->
			<div class="email-composer__variables">
				<details>
					<summary>{{ t('procest', 'Available variables') }}</summary>
					<div class="email-composer__variable-list">
						<code
							v-for="v in availableVariables"
							:key="v"
							@click="insertVariable(v)">
							{{ formatVariable(v) }}
						</code>
					</div>
				</details>
			</div>
		</div>

		<div class="email-composer__actions">
			<NcButton @click="$emit('cancel')">
				{{ t('procest', 'Cancel') }}
			</NcButton>
			<NcButton type="secondary" @click="previewEmail">
				{{ t('procest', 'Preview') }}
			</NcButton>
			<NcButton
				type="primary"
				:disabled="!isValid || sending"
				@click="sendEmail">
				<template v-if="sending">
					<NcLoadingIcon :size="20" />
				</template>
				{{ t('procest', 'Send') }}
			</NcButton>
		</div>

		<!-- Preview dialog -->
		<div
			v-if="showPreview"
			class="email-composer__preview-overlay"
			role="button"
			tabindex="0"
			@click.self="showPreview = false"
			@keydown.enter.self="showPreview = false"
			@keydown.space.self.prevent="showPreview = false">
			<div class="email-composer__preview">
				<h5>{{ t('procest', 'Email Preview') }}</h5>
				<p>
					<strong>{{ t('procest', 'To:') }}</strong> {{ form.to }}
				</p>
				<p>
					<strong>{{ t('procest', 'Subject:') }}</strong>
					{{ previewSubject }}
				</p>
				<div class="email-composer__preview-body" v-html="previewBody" />
				<NcButton @click="showPreview = false">
					{{ t('procest', 'Close') }}
				</NcButton>
			</div>
		</div>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon, NcSelect, NcTextField } from '@nextcloud/vue'

export default {
	name: 'EmailComposer',
	components: {
		NcButton,
		NcTextField,
		NcSelect,
		NcLoadingIcon,
	},

	props: {
		caseId: {
			type: String,
			required: true,
		},

		caseData: {
			type: Object,
			default: () => ({}),
		},

		templates: {
			type: Array,
			default: () => [],
		},

		defaultTo: {
			type: String,
			default: '',
		},
	},

	data() {
		return {
			form: {
				to: this.defaultTo,
				subject: '',
				body: '',
			},

			selectedTemplate: null,
			sending: false,
			showPreview: false,
			previewSubject: '',
			previewBody: '',
			availableVariables: [
				'zaakNummer',
				'titel',
				'startdatum',
				'deadline',
				'status',
				'behandelaar',
				'aanvragerNaam',
			],
		}
	},

	computed: {
		/** @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md */
		isValid() {
			return (
				this.form.to.trim() !== ''
				&& this.form.subject.trim() !== ''
				&& this.form.body.trim() !== ''
			)
		},

		/** @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md */
		unresolvedVars() {
			const pattern = /\{\{(\w+)\}\}/g
			const vars = []
			let match = pattern.exec(this.form.body + ' ' + this.form.subject)
			while (match) {
				if (!this.caseData[match[1]]) {
					vars.push(match[1])
				}
				match = pattern.exec(this.form.body + ' ' + this.form.subject)
			}
			return [...new Set(vars)]
		},
	},

	methods: {
		/**
		 * @param varName
		 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
		 */
		formatVariable(varName) {
			return '{{' + varName + '}}'
		},

		/**
		 * @param template
		 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
		 */
		onTemplateSelected(template) {
			if (!template) return
			this.form.subject = template.subjectPattern || ''
			this.form.body = template.body || ''
		},

		/**
		 * @param varName
		 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
		 */
		insertVariable(varName) {
			this.form.body += '{{' + varName + '}}'
		},

		/** @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md */
		previewEmail() {
			this.previewSubject = this.resolveVars(this.form.subject)
			this.previewBody = this.resolveVars(this.form.body)
			this.showPreview = true
		},

		/**
		 * @param text
		 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
		 */
		resolveVars(text) {
			return text.replace(/\{\{(\w+)\}\}/g, (match, key) => {
				return this.caseData[key] || match
			})
		},

		/** @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md */
		sendEmail() {
			this.sending = true
			this.$emit('send', {
				caseId: this.caseId,
				to: this.form.to,
				subject: this.resolveVars(this.form.subject),
				body: this.resolveVars(this.form.body),
				templateId: this.selectedTemplate?.id || null,
			})
			// Parent handles actual send and resets
		},
	},
}
</script>

<style scoped>
.email-composer__title {
	margin-bottom: 12px;
}

.email-composer__template-select {
	margin-bottom: 16px;
}

.email-composer__template-select label {
	display: block;
	font-weight: 600;
	font-size: 0.875rem;
	margin-bottom: 4px;
}

.form-group {
	margin-bottom: 12px;
}

.form-group label {
	display: block;
	font-weight: 600;
	font-size: 0.875rem;
	margin-bottom: 4px;
}

.form-group textarea {
	width: 100%;
	font-family: inherit;
	padding: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
}

.email-composer__warning {
	padding: 8px 12px;
	background: var(--color-warning-light, #fff3e0);
	border-radius: var(--border-radius);
	margin-bottom: 12px;
	font-size: 0.875rem;
}

.email-composer__unresolved {
	color: var(--color-error);
	font-family: monospace;
	margin-left: 4px;
}

.email-composer__variables {
	margin-bottom: 12px;
}

.email-composer__variable-list {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	padding: 8px 0;
}

.email-composer__variable-list code {
	cursor: pointer;
	padding: 2px 8px;
	background: var(--color-background-dark);
	border-radius: var(--border-radius);
	font-size: 0.8125rem;
}

.email-composer__variable-list code:hover {
	background: var(--color-primary-element-light);
}

.email-composer__actions {
	display: flex;
	gap: 8px;
	justify-content: flex-end;
}

.email-composer__preview-overlay {
	position: fixed;
	top: 0;
	left: 0;
	right: 0;
	bottom: 0;
	background: rgba(0, 0, 0, 0.5);
	display: flex;
	align-items: center;
	justify-content: center;
	z-index: 100;
}

.email-composer__preview {
	background: var(--color-main-background);
	border-radius: var(--border-radius-large);
	padding: 24px;
	max-width: 600px;
	width: 90%;
	max-height: 80vh;
	overflow-y: auto;
}

.email-composer__preview-body {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 16px;
	margin: 12px 0;
	min-height: 100px;
}
</style>
