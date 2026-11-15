<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  OpenRegister's search index, read when the section opens. `variant="run"`
  shows the last maintenance run with Update now, which queues the run as a
  background job; `variant="rows"` shows the rows against the index and the
  command that rebuilds it. When OpenRegister does not answer, the section says
  what went wrong instead of drawing a table of zeros.

  @spec openspec/specs/admin-settings/spec.md
-->
<template>
	<div class="search-index-facts" :data-testid="`search-index-facts-${variant}`">
		<NcLoadingIcon v-if="loading" :size="24" />

		<NcNoteCard v-else-if="error" type="warning" data-testid="search-index-facts-error">
			{{ t('dossiq', 'OpenRegister does not answer: {error}. Try again later; searching in the app keeps working while the index itself runs.', { error }) }}
		</NcNoteCard>

		<template v-else>
			<SettingsFactsList :rows="rows" :layout="variant === 'run' ? 'inline' : 'boxes'" />

			<div v-if="variant === 'run'" class="search-index-facts__actions cn-settings-row-actions">
				<NcButton
					variant="secondary"
					:disabled="queueing"
					data-testid="search-index-facts-run"
					@click="queueRun">
					{{ t('dossiq', 'Update now') }}
				</NcButton>
			</div>
		</template>
	</div>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import { NcButton, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import SettingsFactsList from './SettingsFactsList.vue'
import { queueSearchIndexRun, searchIndexStatus } from '../../../services/searchIndexApi.js'
import { indexRowFacts, runFacts } from '../../../utils/searchIndexFacts.js'

export default {
	name: 'SearchIndexFacts',

	components: { NcButton, NcLoadingIcon, NcNoteCard, SettingsFactsList },

	props: {
		/** `run` (Integrations) or `rows` (Advanced). */
		variant: {
			type: String,
			default: 'run',
			validator: (value) => ['run', 'rows'].includes(value),
		},
	},

	data() {
		return {
			loading: true,
			error: '',
			queueing: false,
			status: null,
		}
	},

	computed: {
		/**
		 * The rows this variant draws.
		 *
		 * @return {Array<object>} Label and value rows.
		 * @spec openspec/specs/admin-settings/spec.md
		 */
		rows() {
			if (this.status === null) {
				return []
			}
			return this.variant === 'run' ? runFacts(this.status) : indexRowFacts(this.status)
		},
	},

	/**
	 * Read the status once the section is on the page.
	 *
	 * @return {Promise<void>}
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	async mounted() {
		await this.load()
	},

	methods: {
		/**
		 * Read what OpenRegister says about its index.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/admin-settings/spec.md
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				this.status = await searchIndexStatus()
			} catch (e) {
				this.error = String(e?.message ?? e)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Queue a maintenance run; the next load shows its result.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/admin-settings/spec.md
		 */
		async queueRun() {
			this.queueing = true
			try {
				await queueSearchIndexRun()
				showSuccess(t('dossiq', 'The search index is updated in the background.'))
			} catch (e) {
				showError(t('dossiq', 'The search index could not be updated: {error}', { error: String(e?.message ?? e) }))
			} finally {
				this.queueing = false
			}
		},
	},
}
</script>

<style scoped>
.search-index-facts__actions {
	display: flex;
	align-items: center;
	gap: 10px;
	margin-top: 14px;
}
</style>
