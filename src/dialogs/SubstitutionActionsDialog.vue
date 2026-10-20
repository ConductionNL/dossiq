<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  The actions done under one substitution, stamped with the capacity they were
  done in. Opened by the substitution console's row action `actions-done`, with
  the row as `object` and its id as `objectId`.

  @spec openspec/specs/handler-vervanging-waarneming/spec.md
-->
<template>
	<NcDialog
		:name="t('dossiq', 'Actions performed under this substitution')"
		size="normal"
		data-testid="substitution-actions"
		@closing="$emit('close')">
		<NcLoadingIcon v-if="loading" :size="32" />
		<NcNoteCard v-else-if="error" type="error">
			{{ error }}
		</NcNoteCard>
		<NcEmptyContent
			v-else-if="actions.length === 0"
			:name="t('dossiq', 'No actions recorded yet')" />
		<ul v-else class="substitution-actions">
			<li v-for="(action, index) in actions" :key="index">
				{{ action.timestamp }} — {{ action.caseTitle || action.caseId }} —
				{{ action.action }}
				({{ t('dossiq', 'on behalf of {who}', { who: action.actedOnBehalfOf }) }})
			</li>
		</ul>
	</NcDialog>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { fetchSubstitutionActions } from '../services/substitutionApi.js'

export default {
	name: 'SubstitutionActionsDialog',

	components: { NcDialog, NcEmptyContent, NcLoadingIcon, NcNoteCard },

	props: {
		/** The substitution row. */
		object: {
			type: Object,
			default: () => ({}),
		},

		/** The substitution's id. */
		objectId: {
			type: [String, Number],
			required: true,
		},
	},

	emits: ['close'],

	data() {
		return {
			loading: true,
			error: '',
			actions: [],
		}
	},

	async mounted() {
		try {
			this.actions = await fetchSubstitutionActions(String(this.objectId))
		} catch {
			this.error = t('dossiq', 'The actions could not be read.')
		} finally {
			this.loading = false
		}
	},

	methods: {
		t,
	},
}
</script>

<style scoped>
.substitution-actions {
	margin: 0;
	padding-inline-start: 20px;
}
</style>
