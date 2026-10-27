<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  - SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<div v-if="templates.length > 0" class="message-template-select">
		<label class="message-template-select__label" :for="inputId">
			{{ t('dossiq', 'Template') }}
			<span class="message-template-select__optional">{{ t('dossiq', '(optional)') }}</span>
		</label>
		<NcSelect
			:modelValue="chosen"
			:inputId="inputId"
			:options="templates"
			:inputLabel="t('dossiq', 'Template')"
			:labelOutside="true"
			:placeholder="t('dossiq', 'Start from a template')"
			label="name"
			trackBy="id"
			@update:modelValue="choose" />
		<span v-if="failed" class="message-template-select__error" role="alert">{{ t('dossiq', 'The template could not be filled in for this case.') }}</span>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcSelect } from '@nextcloud/vue'
import { draftFromTemplate, listMessageTemplates } from '../../services/messageTemplates.js'

/**
 * Pick one of the case type's message templates. The choice is rendered for
 * the case and handed back as `fill` ({ templateId, subject, body }); the host
 * puts it in its own fields, which stay editable. Nothing shows when the case
 * type has no template for the host's channels.
 *
 * @spec openspec/changes/compose-from-message-template/specs/case-messages/spec.md#requirement-choosing-a-message-template-fills-the-message
 */
export default {
	name: 'MessageTemplateSelect',

	components: { NcSelect },

	props: {
		/** The case the message is about. */
		caseId: { type: String, default: '' },
		/** The case's case type, whose templates are offered. */
		caseTypeId: { type: String, default: '' },
		/** The channels the host sends on; a template for another channel is left out. */
		channels: { type: Array, default: () => [] },
		/** The select's input id. */
		inputId: { type: String, default: 'message-template-select' },
	},

	emits: ['fill'],

	data() {
		return { templates: [], chosen: null, failed: false }
	},

	watch: {
		caseTypeId: {
			immediate: true,
			/**
			 * Load the templates once the case type is known.
			 *
			 * @param {string} id The case type.
			 * @return {Promise<void>}
			 */
			async handler(id) {
				this.templates = []
				if (!id) {
					return
				}
				try {
					this.templates = await listMessageTemplates(id, this.channels)
				} catch {
					this.templates = []
				}
			},
		},
	},

	methods: {
		t,

		/**
		 * Render the chosen template for the case and hand it to the host.
		 *
		 * @param {object|null} template The chosen template.
		 * @return {Promise<void>}
		 */
		async choose(template) {
			this.chosen = template
			this.failed = false
			if (!template || !this.caseId) {
				return
			}
			try {
				const draft = await draftFromTemplate(this.caseId, template.id)
				this.$emit('fill', { templateId: template.id, ...draft })
			} catch {
				this.failed = true
			}
		},
	},
}
</script>

<style scoped>
.message-template-select {
	display: flex;
	flex-direction: column;
	gap: 6px;
	min-width: 0;
}

.message-template-select__label {
	font-size: 14px;
	font-weight: 600;
	line-height: normal;
}

/* Under its label as a text field is (DqBerichtVersturen "Sjabloon"): no
   NcSelect margins, and its 2px ring room above and below out of the flow. */
.message-template-select .v-select.select {
	margin: -2px 0;
}

.message-template-select__optional {
	font-weight: 400;
	color: var(--color-text-maxcontrast);
}

.message-template-select__error {
	font-size: 13px;
	color: var(--color-error-text, var(--color-error));
}
</style>
