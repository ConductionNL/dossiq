<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  Picking fields for a case type from the attribute catalogue (board
  DqZaaktypeVelden, "Attribuut kiezen uit de catalogus"), over the library's
  CnFieldCatalogueDialog. The catalogue is every attribute in the register in
  its folder, with how many case types carry it; what this case type already
  carries is not offered again. Adding gives the case type its own copy of
  each picked attribute. A refusal (a reader who may not read or write the
  catalogue) stays in the dialog in OpenRegister's words.

  Opened with `{ type: "open-modal", target: "FieldCatalogueDialog", props:
  { caseTypeId: "@objectId" } }`; an unresolved token falls back to the route.

  @spec openspec/specs/property-definition-management/spec.md
-->
<template>
	<CnFieldCatalogueDialog
		:title="t('dossiq', 'Choose an attribute from the catalogue')"
		:description="t('dossiq', 'Attributes sit in folders. An attribute more case types use shows how many; whoever may not read the catalogue gets OpenRegister\'s refusal.')"
		:fields="fields"
		:folders="folders"
		:excludeIds="excludeIds"
		:loading="loading"
		:error="error"
		:allLabel="t('dossiq', 'All attributes')"
		:otherLabel="t('dossiq', 'Uncategorised')"
		:usageLabel="t('dossiq', 'in {count} case types')"
		:confirmLabel="t('dossiq', 'Add')"
		@select="add"
		@closing="$emit('close')" />
</template>

<script>
import { CnFieldCatalogueDialog } from '@conduction/nextcloud-vue'
import { showSuccess } from '@nextcloud/dialogs'
import { emit } from '@nextcloud/event-bus'
import { n, translate as t } from '@nextcloud/l10n'
import { addToCaseType, buildCatalogue, fetchAttributes } from '../services/fieldCatalogue.js'
import { resolveCaseId } from '../utils/resolveCaseId.js'

export default {
	name: 'FieldCatalogueDialog',

	components: { CnFieldCatalogueDialog },

	props: {
		/** The case type that picks; `@objectId` or empty reads the route. */
		caseTypeId: {
			type: String,
			default: '',
		},
	},

	emits: ['close', 'added'],

	data() {
		return {
			fields: [],
			folders: [],
			excludeIds: [],
			loading: true,
			error: '',
		}
	},

	computed: {
		/** @return {string} The case type id, from the prop or the route. */
		targetCaseTypeId() {
			return resolveCaseId(this.caseTypeId, this.$route)
		},
	},

	async mounted() {
		try {
			Object.assign(this, buildCatalogue(await fetchAttributes(), this.targetCaseTypeId))
		} catch (failure) {
			this.error = this.refusalOf(failure, t('dossiq', 'The catalogue could not be read.'))
		}
		this.loading = false
	},

	methods: {
		t,

		/**
		 * Add the picked attributes to the case type, then refresh the page.
		 *
		 * @param {Array<object>} picked The catalogue entries.
		 * @return {Promise<void>}
		 * @spec openspec/specs/property-definition-management/spec.md
		 */
		async add(picked) {
			if (!Array.isArray(picked) || picked.length === 0) {
				return
			}
			this.error = ''
			try {
				const added = await addToCaseType(picked, this.targetCaseTypeId)
				showSuccess(n('dossiq', '{count} field added to the case type.', '{count} fields added to the case type.', added, { count: added }))
				emit('cn:page:refresh', {})
				this.$emit('added', added)
				this.$emit('close')
			} catch (failure) {
				this.error = this.refusalOf(failure, t('dossiq', 'The field could not be added.'))
			}
		},

		/**
		 * The server's words for a refusal, else the fallback.
		 *
		 * @param {unknown} failure The failed request.
		 * @param {string} fallback The sentence when the server gave none.
		 * @return {string} The sentence.
		 */
		refusalOf(failure, fallback) {
			const data = failure?.response?.data
			const said = data?.message || data?.error
			return typeof said === 'string' && said !== '' ? said : fallback
		},
	},
}
</script>
