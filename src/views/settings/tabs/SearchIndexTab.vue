<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  The state of the indexes behind case search.

  THE INDEXES ARE OPENREGISTER'S AND DOSSIQ KEEPS NO COPY. This reads
  /api/settings/search-index and shows what came back. A second record of how
  many indexes exist is one an administrator can read while the real ones are
  missing, and a stale index is a case nobody finds, which under the Woo is an
  answer that is wrong rather than late.

  The acts are named, not offered. A rebuild walks every magic table in scope
  and nothing stops one from a settings page, so the occ verbs are printed for
  somebody who has a shell and a reason.

  @spec openspec/changes/case-search-declares-its-fields/specs/case-search-via-or-unified-search/spec.md
-->
<template>
	<div class="search-index" data-testid="search-index">
		<NcLoadingIcon v-if="loading" :size="24" />

		<NcNoteCard
			v-else-if="error"
			type="error"
			data-testid="search-index-error">
			{{ error }}
		</NcNoteCard>

		<template v-else>
			<dl class="search-index__figures">
				<div>
					<dt>{{ t('dossiq', 'Tables in scope') }}</dt>
					<dd data-testid="search-index-tables">{{ status.tableCount }}</dd>
				</div>
				<div>
					<dt>{{ t('dossiq', 'Indexes on them') }}</dt>
					<dd data-testid="search-index-indexes">{{ status.indexCount }}</dd>
				</div>
				<div>
					<dt>{{ t('dossiq', 'Last run') }}</dt>
					<dd data-testid="search-index-last-run">{{ lastRun }}</dd>
				</div>
				<div>
					<dt>{{ t('dossiq', 'Rebuild without locking') }}</dt>
					<dd data-testid="search-index-concurrent">{{ concurrent }}</dd>
				</div>
			</dl>

			<p data-testid="search-index-commands">
				{{
					t(
						'dossiq',
						'Run {command} status on the server for the same figures, and {command} rebuild --apply after a schema change.',
						{ command: command },
					)
				}}
			</p>
		</template>
	</div>
</template>

<script>
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import {
	SEARCH_INDEX_COMMAND,
	searchIndexStatus,
} from '../../../services/searchIndexApi.js'

export default {
	name: 'SearchIndexTab',

	components: {
		NcLoadingIcon,
		NcNoteCard,
	},

	data() {
		return {
			loading: true,
			error: '',
			status: {
				concurrentRebuildSupported: false,
				tables: {},
				tableCount: 0,
				indexCount: 0,
				lastRun: null,
			},
		}
	},

	computed: {
		/**
		 * The occ command that acts on the indexes.
		 *
		 * @return {string} The command.
		 * @spec openspec/changes/case-search-declares-its-fields/specs/case-search-via-or-unified-search/spec.md
		 */
		command() {
			return SEARCH_INDEX_COMMAND
		},

		/**
		 * When maintenance last ran, or that it never has.
		 *
		 * @return {string} The answer, in words.
		 * @spec openspec/changes/case-search-declares-its-fields/specs/case-search-via-or-unified-search/spec.md
		 */
		lastRun() {
			if (this.status.lastRun === null || this.status.lastRun === '') {
				return t('dossiq', 'Never')
			}

			return String(this.status.lastRun)
		},

		/**
		 * Whether the platform rebuilds an index without locking the table.
		 *
		 * @return {string} The answer, in words.
		 * @spec openspec/changes/case-search-declares-its-fields/specs/case-search-via-or-unified-search/spec.md
		 */
		concurrent() {
			return this.status.concurrentRebuildSupported === true
				? t('dossiq', 'Yes')
				: t('dossiq', 'No, a rebuild locks the table it is on')
		},
	},

	/**
	 * Read openregister's index status once the section is on the page.
	 *
	 * @return {Promise<void>}
	 * @spec openspec/changes/case-search-declares-its-fields/specs/case-search-via-or-unified-search/spec.md
	 */
	async mounted() {
		await this.load()
	},

	methods: {
		/**
		 * Read what openregister says about its indexes.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/case-search-declares-its-fields/specs/case-search-via-or-unified-search/spec.md
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
	},
}
</script>

<style scoped>
.search-index__figures {
	display: flex;
	flex-wrap: wrap;
	gap: 24px;
	margin-block-end: 12px;
}

.search-index__figures dt {
	color: var(--color-text-maxcontrast);
}

.search-index__figures dd {
	font-weight: bold;
	margin: 0;
}
</style>
