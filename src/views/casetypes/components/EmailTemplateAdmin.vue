<template>
	<div class="email-template-admin">
		<NcNoteCard type="info">
			{{ introText }}
		</NcNoteCard>

		<div class="email-template-admin__layout">
			<!-- Template list -->
			<div class="email-template-admin__list">
				<div class="email-template-admin__list-header">
					<h4>{{ t('dossiq', 'Templates') }}</h4>
					<NcButton
						type="primary"
						:disabled="!caseTypeId"
						@click="startCreate">
						<template #icon>
							<Plus :size="20" />
						</template>
						{{ t('dossiq', 'New') }}
					</NcButton>
				</div>

				<NcLoadingIcon v-if="loading" :size="24" />
				<ul v-else-if="templates.length > 0">
					<li
						v-for="tpl in templates"
						:key="tpl.id || tpl.name"
						class="email-template-admin__list-item"
						:class="{
							'email-template-admin__list-item--active':
								isSelected(tpl),
						}"
						role="button"
						tabindex="0"
						@click="selectTemplate(tpl)"
						@keydown.enter="selectTemplate(tpl)"
						@keydown.space.prevent="selectTemplate(tpl)">
						<span class="email-template-admin__list-name">{{
							tpl.name
						}}</span>
						<span class="email-template-admin__list-version"
							>v{{ tpl.version || 1 }}</span
						>
					</li>
				</ul>
				<p v-else class="email-template-admin__empty">
					{{ t('dossiq', 'No templates yet for this case type.') }}
				</p>
			</div>

			<!-- Editor -->
			<div v-if="editing" class="email-template-admin__editor">
				<div class="setting-row">
					<label for="etpl-name">{{ t('dossiq', 'Name') }}</label>
					<NcInputField id="etpl-name" v-model="draft.name" />
				</div>

				<div class="setting-row">
					<NcSelect
						v-model="draft.moment"
						:inputLabel="t('dossiq', 'Moment')"
						:options="momentOptions"
						:reduce="(option) => option.value"
						label="label" />
				</div>

				<div class="setting-row">
					<NcSelect
						v-model="draft.channel"
						:inputLabel="t('dossiq', 'Channel')"
						:options="channelOptions"
						:reduce="(option) => option.value"
						label="label" />
				</div>

				<div class="setting-row">
					<label for="etpl-subject">{{ t('dossiq', 'Subject') }}</label>
					<NcInputField
						id="etpl-subject"
						v-model="draft.subject"
						@focus="activeField = 'subject'" />
					<p
						v-if="unresolved.length > 0"
						class="email-template-admin__warning">
						{{
							t('dossiq', 'Unresolved variables: {names}', {
								names: unresolved.join(', '),
							})
						}}
					</p>
				</div>

				<!-- The body is plain text with {{name}} variables: the editor
				     previews it escaped, marks known and unknown variables and
				     names the unknown ones under it. -->
				<div class="setting-row" @focusin="activeField = 'body'">
					<span>{{ t('dossiq', 'Body') }}</span>
					<CnMarkdownEditor
						v-model="draft.body"
						mode="split"
						variableSyntax="{{name}}"
						plainText
						:rows="8"
						:ariaLabel="t('dossiq', 'Body')"
						placeholder=""
						:variables="variableList"
						:unknownVariableText="t('dossiq', 'Unresolved variables: {names}')" />
				</div>

				<div class="email-template-admin__actions">
					<NcButton type="primary" :disabled="saving" @click="save">
						<template #icon>
							<NcLoadingIcon v-if="saving" :size="20" />
						</template>
						{{ saveLabel }}
					</NcButton>
					<NcButton type="tertiary" @click="cancelEdit">
						{{ t('dossiq', 'Cancel') }}
					</NcButton>
				</div>
			</div>

			<!-- Variable sidebar -->
			<div v-if="editing" class="email-template-admin__variables">
				<h4>{{ t('dossiq', 'Variables') }}</h4>
				<p class="email-template-admin__hint">
					{{ t('dossiq', 'Click to insert into the focused field') }}
				</p>
				<div
					v-for="(names, group) in variables"
					:key="group"
					class="email-template-admin__var-group">
					<h5>{{ groupLabel(group) }}</h5>
					<button
						v-for="name in names"
						:key="group + '.' + name"
						type="button"
						class="email-template-admin__var"
						@click="insertVariable(name)">
						{{ varToken(name) }}
					</button>
				</div>
			</div>
		</div>
	</div>
</template>

<script>
import { CnMarkdownEditor } from '@conduction/nextcloud-vue'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcInputField,
	NcLoadingIcon,
	NcNoteCard,
	NcSelect,
} from '@nextcloud/vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import { collectUnresolved } from '../../../utils/emailTemplatePreview.js'
import { MESSAGE_CHANNELS, MESSAGE_MOMENTS } from '../../../utils/messageDelivery.js'

/**
 * The fields of a template draft, each with its empty value, in draft order.
 *
 * @type {object}
 */
const DRAFT_FIELDS = { id: null, name: '', subject: '', body: '', moment: null, channel: null }

/**
 * The path of a case type's email templates.
 *
 * @param {string} caseTypeId The case type id.
 * @return {string} The path, before generateUrl.
 */
function caseTypeTemplatesPath(caseTypeId) {
	return `/apps/dossiq/api/casetypes/${encodeURIComponent(caseTypeId)}/email-templates`
}

/**
 * GET a url with the request token.
 *
 * @param {string} url The url.
 * @return {Promise<Response>} The response.
 */
function fetchWithToken(url) {
	return fetch(url, {
		headers: { requesttoken: OC.requestToken },
	})
}

/**
 * The template list from the templates response: a bare list, `results` or `templates`.
 *
 * @param {Array|object} data The response body.
 * @return {Array<object>} The templates.
 */
function templatesFrom(data) {
	return Array.isArray(data) ? data : data.results || data.templates || []
}

/**
 * A draft of a template, every missing field emptied.
 *
 * @param {object} tpl The template.
 * @return {object} The draft.
 */
function draftFrom(tpl) {
	const draft = {}
	for (const [field, empty] of Object.entries(DRAFT_FIELDS)) {
		draft[field] = tpl[field] || empty
	}
	return draft
}

/**
 * Where a draft is saved: its own url with PUT once it exists, else the case
 * type's templates with POST.
 *
 * @param {object} draft The draft.
 * @param {string} caseTypeId The case type id.
 * @return {{url: string, method: string}} The target.
 */
function saveTarget(draft, caseTypeId) {
	if (draft.id) {
		return {
			url: generateUrl(`/apps/dossiq/api/email-templates/${encodeURIComponent(draft.id)}`),
			method: 'PUT',
		}
	}
	return { url: generateUrl(caseTypeTemplatesPath(caseTypeId)), method: 'POST' }
}

/**
 * The saved body of a draft, an unset moment or channel as null.
 *
 * @param {object} draft The draft.
 * @return {string} The JSON body.
 */
function templatePayload(draft) {
	return JSON.stringify({
		name: draft.name,
		subject: draft.subject,
		body: draft.body,
		moment: draft.moment || null,
		channel: draft.channel || null,
	})
}

/**
 * The fetch options that save a draft as JSON with the request token.
 *
 * @param {object} draft The draft.
 * @param {string} method PUT or POST.
 * @return {object} The fetch options.
 */
function saveInit(draft, method) {
	return {
		method,
		headers: {
			'Content-Type': 'application/json',
			requesttoken: OC.requestToken,
		},
		body: templatePayload(draft),
	}
}

/**
 * Per-case-type email template CRUD with click-to-insert variables and a
 * plain-text body editor that previews it and marks unresolved {{placeholders}}. No
 * compose/thread/queue components — display, compose and link come from the
 * email leaf + Nextcloud Mail.
 *
 * @spec openspec/specs/case-email-integration/spec.md
 */
export default {
	name: 'EmailTemplateAdmin',
	components: {
		CnMarkdownEditor,
		NcButton,
		NcInputField,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
		Plus,
	},

	props: {
		caseTypeId: {
			type: String,
			default: null,
		},
	},

	data() {
		return {
			loading: false,
			saving: false,
			editing: false,
			activeField: 'body',
			templates: [],
			variables: {},
			draft: { id: null, name: '', subject: '', body: '', moment: null, channel: null },
		}
	},

	computed: {
		/** @spec openspec/specs/case-email-integration/spec.md */
		introText() {
			return t(
				'dossiq',
				'Per-case-type email templates with placeholder variables. Editing a template creates a new version. Old versions are retained. Templates prefill a Nextcloud Mail draft; Dossiq never sends mail itself.',
			)
		},

		/**
		 * The case events a message can go out on, translated.
		 *
		 * @return {Array<{value: string, label: string}>} The options.
		 * @spec openspec/changes/case-type-messages-moment-channel/specs/case-types/spec.md
		 */
		momentOptions() {
			return MESSAGE_MOMENTS.map((option) => ({ value: option.value, label: t('dossiq', option.label) }))
		},

		/**
		 * The ways a message reaches the applicant, translated.
		 *
		 * @return {Array<{value: string, label: string}>} The options.
		 * @spec openspec/changes/case-type-messages-moment-channel/specs/case-types/spec.md
		 */
		channelOptions() {
			return MESSAGE_CHANNELS.map((option) => ({ value: option.value, label: t('dossiq', option.label) }))
		},

		/** @spec openspec/specs/case-email-integration/spec.md */
		flatVariableNames() {
			return Object.values(this.variables).flat()
		},

		/**
		 * The subject's unresolved variables; the body editor names its own.
		 *
		 * @return {Array<string>} The names.
		 * @spec openspec/specs/case-email-integration/spec.md
		 */
		unresolved() {
			return collectUnresolved(this.draft.subject, this.flatVariableNames)
		},

		/**
		 * The variables the body may use, each described by its group.
		 *
		 * @return {Array<{name: string, description: string}>} The variables.
		 * @spec openspec/specs/case-email-integration/spec.md
		 */
		variableList() {
			return Object.entries(this.variables).flatMap(([group, names]) => names.map((name) => ({ name, description: this.groupLabel(group) })))
		},

		/** @spec openspec/specs/case-email-integration/spec.md */
		saveLabel() {
			if (this.saving) return t('dossiq', 'Saving...')
			return this.draft.id
				? t('dossiq', 'Save as new version')
				: t('dossiq', 'Create template')
		},
	},

	watch: {
		caseTypeId: {
			immediate: true,
			handler() {
				if (this.caseTypeId) {
					this.load()
				}
			},
		},
	},

	methods: {
		/**
		 * @param {string} name The name.
		 * @spec openspec/specs/case-email-integration/spec.md
		 */
		varToken(name) {
			return '{{' + name + '}}'
		},

		/**
		 * @param {object} group The group.
		 * @spec openspec/specs/case-email-integration/spec.md
		 */
		groupLabel(group) {
			const labels = {
				case: t('dossiq', 'Case'),
				contact: t('dossiq', 'Contact'),
				caseType: t('dossiq', 'Case type'),
			}
			return labels[group] || group
		},

		isSelected(tpl) {
			return this.draft.id && tpl.id === this.draft.id
		},

		/** @spec openspec/specs/case-email-integration/spec.md */
		async load() {
			this.loading = true
			try {
				const path = caseTypeTemplatesPath(this.caseTypeId)
				const [tplRes, varRes] = await Promise.all([
					fetchWithToken(generateUrl(path)),
					fetchWithToken(generateUrl(`${path}/variables`)),
				])
				await this.keepLoaded(tplRes, varRes)
			} catch (error) {
				// Non-fatal — empty editor still works for create.
			} finally {
				this.loading = false
			}
		},

		/**
		 * Keep the templates and the variables whose read succeeded.
		 *
		 * @param {Response} tplRes The templates response.
		 * @param {Response} varRes The variables response.
		 * @return {Promise<void>}
		 */
		async keepLoaded(tplRes, varRes) {
			if (tplRes.ok) {
				this.templates = templatesFrom(await tplRes.json())
			}
			if (varRes.ok) {
				this.variables = await varRes.json()
			}
		},

		/** @spec openspec/specs/case-email-integration/spec.md */
		startCreate() {
			this.draft = { id: null, name: '', subject: '', body: '', moment: null, channel: null }
			this.editing = true
			this.activeField = 'body'
		},

		/**
		 * @param {object} tpl The tpl.
		 * @spec openspec/specs/case-email-integration/spec.md
		 */
		selectTemplate(tpl) {
			this.draft = draftFrom(tpl)
			this.editing = true
			this.activeField = 'body'
		},

		cancelEdit() {
			this.editing = false
		},

		/**
		 * @param {string} name The name.
		 * @spec openspec/specs/case-email-integration/spec.md
		 */
		insertVariable(name) {
			const token = `{{${name}}}`
			if (this.activeField === 'subject') {
				this.draft.subject = `${this.draft.subject || ''}${token}`
			} else {
				this.draft.body = `${this.draft.body || ''}${token}`
			}
		},

		/** @spec openspec/specs/case-email-integration/spec.md */
		async save() {
			this.saving = true
			try {
				const { url, method } = saveTarget(this.draft, this.caseTypeId)
				await fetch(url, saveInit(this.draft, method))
				this.editing = false
				await this.load()
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.email-template-admin__layout {
	display: grid;
	grid-template-columns: 220px 1fr 200px;
	gap: 16px;
	margin-top: 12px;
}

.email-template-admin__list-header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	margin-bottom: 8px;
}

.email-template-admin__list-item {
	display: flex;
	justify-content: space-between;
	padding: 6px 8px;
	border-radius: var(--border-radius);
	cursor: pointer;
}

.email-template-admin__list-item:hover,
.email-template-admin__list-item--active {
	background: var(--color-background-hover);
}

.email-template-admin__list-version {
	color: var(--color-text-maxcontrast);
	font-size: 0.8em;
}

.email-template-admin__empty,
.email-template-admin__hint {
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
}

.setting-row {
	display: flex;
	flex-direction: column;
	gap: 4px;
	margin-bottom: 12px;
}

.email-template-admin__warning {
	color: var(--color-error, #e9322d);
	font-size: 0.85em;
}

.email-template-admin__actions {
	display: flex;
	gap: 8px;
}

.email-template-admin__var-group {
	margin-bottom: 8px;
}

.email-template-admin__var {
	display: block;
	width: 100%;
	text-align: left;
	background: var(--color-background-dark);
	border: none;
	border-radius: var(--border-radius);
	padding: 4px 6px;
	margin-bottom: 4px;
	cursor: pointer;
	font-family: monospace;
	font-size: 0.85em;
}

.email-template-admin__var:hover {
	background: var(--color-background-hover);
}
</style>
