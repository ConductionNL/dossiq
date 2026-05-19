<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<template>
	<div class="email-template-admin">
		<div class="email-template-admin__header">
			<h4>{{ t('procest', 'Email templates') }}</h4>
			<NcButton type="primary" @click="openCreate">
				{{ t('procest', 'New template') }}
			</NcButton>
		</div>

		<div v-if="loading" class="email-template-admin__loading">
			<NcLoadingIcon :size="32" />
		</div>

		<div v-else-if="templates.length === 0" class="email-template-admin__empty">
			{{ t('procest', 'No email templates for this case type.') }}
		</div>

		<div v-else class="email-template-admin__list">
			<div
				v-for="tpl in templates"
				:key="tpl.id || tpl.uuid"
				class="email-template-admin__item">
				<div class="email-template-admin__item-header">
					<strong>{{ tpl.name }}</strong>
					<span class="email-template-admin__version">v{{ tpl.version }}</span>
				</div>
				<div class="email-template-admin__subject">{{ tpl.subject }}</div>
				<div class="email-template-admin__actions">
					<NcButton type="tertiary" @click="editTemplate(tpl)">
						{{ t('procest', 'Edit') }}
					</NcButton>
					<NcButton type="tertiary" @click="previewTemplate(tpl)">
						{{ t('procest', 'Preview') }}
					</NcButton>
				</div>
			</div>
		</div>

		<!-- Create / Edit dialog -->
		<div
			v-if="showForm"
			class="email-template-admin__dialog-overlay"
			@click.self="showForm = false">
			<div class="email-template-admin__dialog">
				<h5>{{ editing ? t('procest', 'Edit template') : t('procest', 'New template') }}</h5>

				<div class="form-group">
					<label>{{ t('procest', 'Name') }}</label>
					<NcTextField
						:value="form.name"
						:label="t('procest', 'Template name')"
						@update:value="v => form.name = v" />
				</div>

				<div class="form-group">
					<label>{{ t('procest', 'Subject') }}</label>
					<NcTextField
						:value="form.subject"
						:label="t('procest', 'Email subject')"
						@update:value="v => form.subject = v" />
				</div>

				<div class="form-group">
					<label>{{ t('procest', 'Body') }}</label>
					<textarea
						v-model="form.body"
						rows="10"
						class="email-template-admin__body-input"
						:placeholder="t('procest', 'HTML body. Use {{variableName}} for placeholders.')" />
				</div>

				<!-- Variable sidebar -->
				<div class="email-template-admin__variables">
					<h6>{{ t('procest', 'Available variables') }}</h6>
					<div v-for="(vars, group) in availableVariables" :key="group" class="email-template-admin__var-group">
						<strong>{{ group }}</strong>
						<code
							v-for="(desc, varName) in vars"
							:key="varName"
							:title="desc"
							class="email-template-admin__var"
							@click="insertVar(varName)">
							{{ '{{' + varName + '}}' }}
						</code>
					</div>
				</div>

				<!-- Unresolved variables highlighted in red -->
				<div v-if="unresolvedVars.length > 0" class="email-template-admin__unresolved-warning">
					{{ t('procest', 'Unresolved variables:') }}
					<span v-for="v in unresolvedVars" :key="v" class="email-template-admin__unresolved">
						{{ '{{' + v + '}}' }}
					</span>
				</div>

				<div class="email-template-admin__dialog-actions">
					<NcButton @click="showForm = false">
						{{ t('procest', 'Cancel') }}
					</NcButton>
					<NcButton type="primary" :loading="saving" @click="save">
						{{ t('procest', 'Save') }}
					</NcButton>
				</div>
			</div>
		</div>

		<!-- Live preview dialog -->
		<div
			v-if="showPreview"
			class="email-template-admin__dialog-overlay"
			@click.self="showPreview = false">
			<div class="email-template-admin__dialog">
				<h5>{{ t('procest', 'Preview') }}: {{ previewTemplate && previewTemplate.name }}</h5>
				<p><strong>{{ t('procest', 'Subject:') }}</strong> {{ previewTemplate && previewTemplate.subject }}</p>
				<div class="email-template-admin__preview-body" v-html="previewHtml" />
				<NcButton @click="showPreview = false">
					{{ t('procest', 'Close') }}
				</NcButton>
			</div>
		</div>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon, NcTextField } from '@nextcloud/vue'
import { t } from '@nextcloud/l10n'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const SAMPLE_DATA = {
	zaakNummer: 'ZAAK-2026-000001',
	titel: 'Voorbeeld zaak',
	startdatum: '2026-01-01',
	deadline: '2026-02-01',
	status: 'In behandeling',
	naam: 'Jan de Vries',
	email: 'jan@example.nl',
	zaakTypeTitel: 'Vergunningaanvraag',
}

export default {
	name: 'EmailTemplateAdmin',
	components: { NcButton, NcLoadingIcon, NcTextField },
	props: {
		caseTypeId: { type: String, required: true },
	},
	data() {
		return {
			templates: [],
			loading: false,
			showForm: false,
			showPreview: false,
			saving: false,
			editing: null,
			form: { name: '', subject: '', body: '' },
			previewTemplate: null,
			availableVariables: {
				case: { zaakNummer: 'Zaaknummer', titel: 'Titel', startdatum: 'Startdatum', deadline: 'Deadline', status: 'Status' },
				contact: { naam: 'Naam aanvrager', email: 'E-mail aanvrager' },
				caseType: { zaakTypeTitel: 'Naam zaaktype' },
			},
		}
	},
	computed: {
		previewHtml() {
			if (!this.previewTemplate) return ''
			return this.resolveVars(this.previewTemplate.body || '')
		},
		unresolvedVars() {
			const text = this.form.body + ' ' + this.form.subject
			const allVars = Object.values(this.availableVariables).flatMap(g => Object.keys(g))
			const found = [...text.matchAll(/\{\{(\w+)\}\}/g)].map(m => m[1])
			return [...new Set(found.filter(v => !allVars.includes(v)))]
		},
	},
	mounted() {
		this.loadTemplates()
	},
	methods: {
		async loadTemplates() {
			this.loading = true
			try {
				const url = generateUrl('/apps/procest/api/casetypes/' + encodeURIComponent(this.caseTypeId) + '/email-templates')
				const { data } = await axios.get(url)
				this.templates = data.results || []
			} catch (e) {
				// eslint-disable-next-line no-console
				console.error('[procest] Failed to load templates', e)
			} finally {
				this.loading = false
			}
		},
		openCreate() {
			this.editing = null
			this.form = { name: '', subject: '', body: '' }
			this.showForm = true
		},
		editTemplate(tpl) {
			this.editing = tpl
			this.form = { name: tpl.name || '', subject: tpl.subject || '', body: tpl.body || '' }
			this.showForm = true
		},
		previewTemplate(tpl) {
			this.previewTemplate = tpl
			this.showPreview = true
		},
		insertVar(varName) {
			this.form.body += '{{' + varName + '}}'
		},
		resolveVars(text) {
			return text.replace(/\{\{(\w+)\}\}/g, (match, key) => {
				const value = SAMPLE_DATA[key]
				return value !== undefined
					? value
					: '<span style="color:red">' + match + '</span>'
			})
		},
		async save() {
			this.saving = true
			try {
				if (this.editing) {
					const url = generateUrl('/apps/procest/api/email-templates/' + encodeURIComponent(this.editing.id || this.editing.uuid))
					await axios.put(url, this.form)
				} else {
					const url = generateUrl('/apps/procest/api/casetypes/' + encodeURIComponent(this.caseTypeId) + '/email-templates')
					await axios.post(url, this.form)
				}
				this.showForm = false
				await this.loadTemplates()
			} catch (e) {
				// eslint-disable-next-line no-console
				console.error('[procest] Failed to save template', e)
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.email-template-admin__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	margin-bottom: 12px;
}

.email-template-admin__item {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 10px 12px;
	margin-bottom: 8px;
}

.email-template-admin__item-header {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-bottom: 4px;
}

.email-template-admin__version {
	font-size: 0.75rem;
	color: var(--color-text-lighter);
}

.email-template-admin__subject {
	font-size: 0.875rem;
	color: var(--color-text-lighter);
	margin-bottom: 8px;
}

.email-template-admin__actions {
	display: flex;
	gap: 4px;
}

.email-template-admin__dialog-overlay {
	position: fixed;
	inset: 0;
	background: rgba(0, 0, 0, 0.4);
	display: flex;
	align-items: center;
	justify-content: center;
	z-index: 200;
}

.email-template-admin__dialog {
	background: var(--color-main-background);
	border-radius: var(--border-radius-large);
	padding: 24px;
	max-width: 720px;
	width: 90%;
	max-height: 85vh;
	overflow-y: auto;
}

.email-template-admin__body-input {
	width: 100%;
	font-family: monospace;
	font-size: 0.875rem;
	padding: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	resize: vertical;
}

.email-template-admin__variables {
	margin: 12px 0;
	padding: 10px;
	background: var(--color-background-dark);
	border-radius: var(--border-radius);
}

.email-template-admin__var-group {
	margin-bottom: 8px;
}

.email-template-admin__var {
	display: inline-block;
	margin: 2px 4px;
	padding: 2px 6px;
	background: var(--color-background-light, #f5f5f5);
	border-radius: 3px;
	cursor: pointer;
	font-size: 0.8125rem;
}

.email-template-admin__var:hover {
	background: var(--color-primary-element-light);
}

.email-template-admin__unresolved-warning {
	padding: 8px 12px;
	background: var(--color-warning-light, #fff3e0);
	border-radius: var(--border-radius);
	margin-bottom: 12px;
}

.email-template-admin__unresolved {
	color: var(--color-error);
	font-family: monospace;
	margin-left: 4px;
}

.email-template-admin__preview-body {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 12px;
	min-height: 80px;
	margin: 12px 0;
}

.email-template-admin__dialog-actions {
	display: flex;
	gap: 8px;
	justify-content: flex-end;
	margin-top: 16px;
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
</style>
