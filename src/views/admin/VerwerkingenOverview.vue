<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  FG/admin verwerkingen overview (AVG verwerkingenlogging, thin consumer).
  A scoped WINDOW on OpenRegister's processing-activity register — not an
  engine: catalogue review status, the unclassified-processing counter
  (OR-PA-4 flagged fallback), and the per-betrokkene inzageverzoek export
  entry point (OR-PA-7 via InzageExportModal). All data comes from OR's
  /api/avg endpoints; OR enforces FG/admin access fail-closed (OR-PA-8) —
  non-FG users get 403s and see the access-denied empty state.

  @spec openspec/specs/avg-verwerkingenlogging/spec.md
-->
<template>
	<div class="verwerkingen-overview">
		<div class="verwerkingen-overview__header">
			<h2>{{ t('procest', 'Processing activities (AVG)') }}</h2>
			<NcButton type="primary" :disabled="denied" @click="showExport = true">
				<template #icon>
					<FileExportOutline :size="20" />
				</template>
				{{ t('procest', 'Data subject access export') }}
			</NcButton>
		</div>

		<p class="verwerkingen-overview__hint">
			{{ t('procest', 'The processing log, retention, and Art. 30 register are managed centrally in OpenRegister. This view is scoped to the case-handling catalogue procest contributes.') }}
		</p>

		<NcLoadingIcon v-if="loading" :size="32" />

		<NcEmptyContent
			v-else-if="denied"
			:name="t('procest', 'Access denied')"
			:description="t('procest', 'Privacy-officer or admin privileges are required to view processing activities.')" />

		<template v-else>
			<NcNoteCard v-if="unclassifiedCount > 0" type="warning" data-testid="unclassified-warning">
				{{ n('procest', '%n processing is not attributed to a catalogued activity and landed in the flagged fallback. Review the attribution mappings.', '%n processings are not attributed to a catalogued activity and landed in the flagged fallback. Review the attribution mappings.', unclassifiedCount) }}
			</NcNoteCard>

			<NcEmptyContent
				v-if="activities.length === 0"
				:name="t('procest', 'No processing activities')"
				:description="t('procest', 'Run the procest repair step to seed the case-handling catalogue as drafts.')" />

			<table v-else class="verwerkingen-overview__table">
				<thead>
					<tr>
						<th>{{ t('procest', 'Code') }}</th>
						<th>{{ t('procest', 'Activity') }}</th>
						<th>{{ t('procest', 'Legal basis') }}</th>
						<th>{{ t('procest', 'Review status') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="activity in activities" :key="activity.code || activity.uuid">
						<td><code>{{ activity.code }}</code></td>
						<td>{{ activity.naam }}</td>
						<td>{{ activity.rechtsgrond }}</td>
						<td>
							<span :class="`status status--${activity.status}`">{{ statusLabel(activity.status) }}</span>
						</td>
					</tr>
				</tbody>
			</table>

			<p class="verwerkingen-overview__footer">
				{{ t('procest', 'Draft activities await review by the privacy officer in OpenRegister; publishing them there confirms the catalogue entry.') }}
			</p>
		</template>

		<InzageExportModal v-if="showExport" @close="showExport = false" />
	</div>
</template>

<script>
import { NcButton, NcEmptyContent, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import FileExportOutline from 'vue-material-design-icons/FileExportOutline.vue'
import InzageExportModal from '../../modals/InzageExportModal.vue'
import { countVerwerkingen, FALLBACK_ACTIVITY_CODE, listVerwerkingsactiviteiten } from '../../services/verwerkingenApi.js'

export default {
	name: 'VerwerkingenOverview',
	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		FileExportOutline,
		InzageExportModal,
	},
	data() {
		return {
			loading: true,
			denied: false,
			activities: [],
			unclassifiedCount: 0,
			showExport: false,
		}
	},
	async mounted() {
		await this.load()
	},
	methods: {
		/** @spec openspec/specs/avg-verwerkingenlogging/spec.md */
		async load() {
			this.loading = true
			this.denied = false
			try {
				const all = await listVerwerkingsactiviteiten()
				// Procest slice: the seeded case-handling catalogue plus the
				// platform fallback (surfaced via the unclassified counter).
				this.activities = all.filter(a => a.code !== FALLBACK_ACTIVITY_CODE)
				const fallback = all.find(a => a.code === FALLBACK_ACTIVITY_CODE)
				if (fallback && fallback.uuid) {
					this.unclassifiedCount = await countVerwerkingen({ activity: fallback.uuid })
				}
			} catch (err) {
				if (err.response && (err.response.status === 403 || err.response.status === 401)) {
					this.denied = true
				} else {
					console.error('[VerwerkingenOverview] load failed', err)
					this.activities = []
				}
			} finally {
				this.loading = false
			}
		},
		/**
		 * Human label for an OR activity lifecycle status.
		 *
		 * @param {string} status OR status (concept | published | archived).
		 * @return {string} Translated label.
		 * @spec openspec/specs/avg-verwerkingenlogging/spec.md
		 */
		statusLabel(status) {
			switch (status) {
			case 'concept':
				return t('procest', 'Draft (awaiting FG review)')
			case 'published':
				return t('procest', 'Published')
			case 'archived':
				return t('procest', 'Archived')
			default:
				return status || t('procest', 'Unknown')
			}
		},
	},
}
</script>

<style scoped lang="scss">
.verwerkingen-overview {
	padding: calc(var(--default-grid-baseline) * 4);
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);

	&__header {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: calc(var(--default-grid-baseline) * 2);
	}

	&__hint,
	&__footer {
		color: var(--color-text-maxcontrast);
	}

	&__table {
		width: 100%;
		border-collapse: collapse;

		th,
		td {
			text-align: start;
			padding: calc(var(--default-grid-baseline) * 2);
			border-bottom: 1px solid var(--color-border);
		}
	}
}

.status {
	padding: 2px 8px;
	border-radius: var(--border-radius-pill);
	background-color: var(--color-background-dark);

	&--published {
		background-color: var(--color-success-light, var(--color-background-dark));
	}

	&--concept {
		background-color: var(--color-warning-light, var(--color-background-dark));
	}
}
</style>
