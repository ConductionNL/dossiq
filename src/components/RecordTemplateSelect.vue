<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - Start a form from a named record template, for any schema.
  -
  - A record template is a named set of values a user saved in OpenRegister
  - and shared with a group or with everyone. This select lists the templates
  - of one schema that the signed-in user may use, straight from
  - GET /apps/openregister/api/record-templates?schema=<id>, and hands the
  - chosen one to the form that mounts it. dossiq keeps no template store.
  -
  - It draws nothing while there is no template to offer, and nothing when the
  - list cannot be read: a template is a shortcut, and the form works without.
  - OpenRegister compares `schema` as text with what the template was saved
  - under, so pass the schema id exactly as the register configuration holds it.
  -
  - Mounted in WooSearchPlanDialog (the search plan of a request). Any other
  - form is one prop away: pass its schema and take `apply`.
  -
  - @spec openspec/changes/woo-request-corpus-collection/specs/record-template-start/spec.md#requirement-a-form-offers-the-named-templates-of-its-schema-req-rts-002
  -
  - @visual exclude One select inside another dialog, empty until a record template exists in a live OpenRegister. Its listing, its empty behaviour and what it hands over are unit-tested in tests/vitest/recordTemplateSelect.spec.js.
-->
<template>
	<div v-if="templates.length > 0" class="record-template-select">
		<NcSelect
			v-model="chosen"
			:options="templates"
			:inputLabel="label || t('dossiq', 'Start from a template')"
			:placeholder="t('dossiq', 'Pick a template')"
			label="name"
			trackBy="uuid"
			data-testid="record-template-select"
			@update:modelValue="choose" />
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import NcSelect from '@nextcloud/vue/components/NcSelect'

export default {
	name: 'RecordTemplateSelect',

	components: { NcSelect },

	props: {
		/** The schema id whose templates are offered, as the register configuration holds it. */
		schema: {
			type: String,
			default: '',
		},

		/** The label above the select. Defaults to "Start from a template". */
		label: {
			type: String,
			default: '',
		},
	},

	emits: ['apply'],

	data() {
		return {
			templates: [],
			chosen: null,
		}
	},

	watch: {
		schema: {
			immediate: true,
			/**
			 * List again when the form names another schema.
			 *
			 * @return {void}
			 * @spec openspec/changes/woo-request-corpus-collection/specs/record-template-start/spec.md#requirement-a-form-offers-the-named-templates-of-its-schema-req-rts-002
			 */
			handler() {
				this.load()
			},
		},
	},

	methods: {
		t,

		/**
		 * The templates of this schema the signed-in user may use.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/woo-request-corpus-collection/specs/record-template-start/spec.md#requirement-a-form-offers-the-named-templates-of-its-schema-req-rts-002
		 */
		async load() {
			this.templates = []
			this.chosen = null
			if (this.schema === '') {
				return
			}
			try {
				const { data } = await axios.get(
					generateUrl('/apps/openregister/api/record-templates'),
					{ params: { schema: this.schema } },
				)
				const rows = Array.isArray(data?.results) ? data.results : []
				this.templates = rows
					.filter((row) => row?.uuid && row?.name)
					.map((row) => ({
						uuid: String(row.uuid),
						name: String(row.name),
						values:
							row.values && !Array.isArray(row.values)
								? row.values
								: {},
					}))
			} catch {
				this.templates = []
			}
		},

		/**
		 * Hand the chosen template to the form.
		 *
		 * @param {{uuid: string, name: string, values: object}|null} template The template, null when cleared.
		 * @return {void}
		 * @spec openspec/changes/woo-request-corpus-collection/specs/record-template-start/spec.md#requirement-a-form-offers-the-named-templates-of-its-schema-req-rts-002
		 */
		choose(template) {
			this.$emit('apply', template || null)
		},
	},
}
</script>
