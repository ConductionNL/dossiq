<template>
	<div>
		<CnIndexPage
			:title="t('procest', 'Case Types')"
			:description="t('procest', 'Configure case types')"
			:schema="schema"
			:objects="caseTypes"
			:loading="loading"
			:selectable="true"
			@add="$emit('create')"
			@refresh="fetchCaseTypes"
			@rowClick="selectCaseType">
			<template #column-title="{ row }">
				<span class="ct-title">
					<StarIcon
						v-if="isDefault(row.id)"
						:size="16"
						class="default-star" />
					{{ row.title || '\u2014' }}
				</span>
			</template>

			<template #column-isDraft="{ row }">
				<span
					class="ct-badge"
					:class="row.isDraft ? 'ct-badge--draft' : 'ct-badge--published'">
					{{
						row.isDraft
							? t('procest', 'Draft')
							: t('procest', 'Published')
					}}
				</span>
			</template>

			<template #column-processingDeadline="{ value }">
				{{ formatDeadline(value) }}
			</template>

			<template #column-validFrom="{ row }">
				<span :class="validityClass(row)">
					{{ formatValidity(row) }}
				</span>
			</template>

			<template #row-actions="{ row }">
				<div class="ct-actions" @click.stop>
					<NcButton
						v-if="!row.isDraft"
						type="tertiary"
						:title="t('procest', 'Set as default')"
						@click="setDefault(row)">
						<template #icon>
							<StarIcon :size="20" />
						</template>
					</NcButton>
					<NcButton
						type="tertiary"
						:disabled="duplicating === row.id"
						:title="t('procest', 'Duplicate')"
						@click="duplicate(row)">
						<template #icon>
							<NcLoadingIcon
								v-if="duplicating === row.id"
								:size="20" />
							<ContentDuplicateIcon v-else :size="20" />
						</template>
					</NcButton>
					<NcButton
						type="tertiary"
						:title="t('procest', 'Delete')"
						@click="confirmDelete(row)">
						<template #icon>
							<DeleteIcon :size="20" />
						</template>
					</NcButton>
				</div>
			</template>
		</CnIndexPage>

		<p v-if="error" class="ct-error">
			{{ error }}
		</p>
	</div>
</template>

<script>
import { CnIndexPage } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcLoadingIcon } from '@nextcloud/vue'
import ContentDuplicateIcon from 'vue-material-design-icons/ContentDuplicate.vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import StarIcon from 'vue-material-design-icons/Star.vue'
import { useObjectStore } from '../../store/modules/object.js'
import { useSettingsStore } from '../../store/modules/settings.js'
import { formatDuration } from '../../utils/durationHelpers.js'

export default {
	name: 'CaseTypeList',
	components: {
		StarIcon,
		DeleteIcon,
		ContentDuplicateIcon,
		NcLoadingIcon,
		CnIndexPage,
	},

	data() {
		return {
			statusTypeCounts: {},
			error: '',
			schema: null,
			duplicating: null,
		}
	},

	computed: {
		/** @spec openspec/changes/retrofit-2026-05-24-case-types/tasks.md */
		objectStore() {
			return useObjectStore()
		},

		/** @spec openspec/changes/retrofit-2026-05-24-case-types/tasks.md */
		settingsStore() {
			return useSettingsStore()
		},

		/** @spec openspec/changes/retrofit-2026-05-24-case-types/tasks.md */
		loading() {
			return this.objectStore.loading.caseType || false
		},

		/** @spec openspec/changes/retrofit-2026-05-24-case-types/tasks.md */
		caseTypes() {
			return this.objectStore.collections.caseType || []
		},

		/** @spec openspec/changes/retrofit-2026-05-24-case-types/tasks.md */
		defaultCaseTypeId() {
			return this.settingsStore.config?.default_case_type || ''
		},
	},

	/** @spec openspec/changes/retrofit-2026-05-24-case-types/tasks.md */
	async mounted() {
		this.schema = await this.objectStore.fetchSchema('caseType')
		await this.fetchCaseTypes()
	},

	methods: {
		/** @spec openspec/changes/retrofit-2026-05-24-case-types/tasks.md */
		async fetchCaseTypes() {
			await this.objectStore.fetchCollection('caseType', { _limit: 100 })
			for (const ct of this.caseTypes) {
				this.loadStatusTypeCount(ct.id)
			}
		},

		/**
		 * @param caseTypeId
		 * @spec openspec/changes/retrofit-2026-05-24-case-types/tasks.md
		 */
		async loadStatusTypeCount(caseTypeId) {
			const statusTypes = await this.objectStore.fetchCollection(
				'statusType',
				{
					'_filters[caseType]': caseTypeId,
					_limit: 100,
				},
			)
			this.statusTypeCounts[caseTypeId] = (statusTypes || []).length
			await this.objectStore.fetchCollection('caseType', { _limit: 100 })
		},

		isDefault(id) {
			return this.defaultCaseTypeId === id
		},

		/**
		 * @param duration
		 * @spec openspec/changes/retrofit-2026-05-24-case-types/tasks.md
		 */
		formatDeadline(duration) {
			return formatDuration(duration)
		},

		/**
		 * @param ct
		 * @spec openspec/changes/retrofit-2026-05-24-case-types/tasks.md
		 */
		formatValidity(ct) {
			if (!ct.validFrom) return '\u2014'
			const from = new Date(ct.validFrom).toLocaleDateString('nl-NL', {
				month: 'short',
				year: 'numeric',
			})
			if (ct.validUntil) {
				const until = new Date(ct.validUntil).toLocaleDateString('nl-NL', {
					month: 'short',
					year: 'numeric',
				})
				return `${from} \u2014 ${until}`
			}
			return t('procest', '{from} \u2014 (no end)', { from })
		},

		/**
		 * @param ct
		 * @spec openspec/changes/retrofit-2026-05-24-case-types/tasks.md
		 */
		validityClass(ct) {
			if (!ct.validUntil) return ''
			const now = new Date()
			const until = new Date(ct.validUntil)
			if (until < now) return 'validity--expired'
			return ''
		},

		/**
		 * @param row
		 * @spec openspec/changes/retrofit-2026-05-24-case-types/tasks.md
		 */
		selectCaseType(row) {
			this.$emit('select', row.id)
		},

		/**
		 * @param ct
		 * @spec openspec/changes/retrofit-2026-05-24-case-types/tasks.md
		 */
		async setDefault(ct) {
			this.error = ''
			if (ct.isDraft) {
				this.error = t(
					'procest',
					'Only published case types can be set as default',
				)
				return
			}
			const config = { ...this.settingsStore.config, default_case_type: ct.id }
			await this.settingsStore.saveSettings(config)
		},

		/**
		 * @param ct
		 * @spec openspec/changes/retrofit-2026-05-24-case-types/tasks.md
		 */
		async confirmDelete(ct) {
			this.error = ''

			try {
				const cases = await this.objectStore.fetchCollection('case', {
					'_filters[caseType]': ct.id,
					_limit: 1,
				})
				if (cases && cases.length > 0) {
					this.error = t(
						'procest',
						'Cannot delete: active cases are using this type',
					)
					await this.fetchCaseTypes()
					return
				}
			} catch {
				// If we can't check, proceed with caution
			}

			const statusCount = this.statusTypeCounts[ct.id] || 0
			const message =
				statusCount > 0
					? t(
							'procest',
							'This will delete the case type and all {count} status types. Continue?',
							{ count: statusCount },
						)
					: t('procest', 'Delete case type "{title}"?', {
							title: ct.title,
						})

			if (!confirm(message)) {
				await this.fetchCaseTypes()
				return
			}

			if (statusCount > 0) {
				const statusTypes = await this.objectStore.fetchCollection(
					'statusType',
					{
						'_filters[caseType]': ct.id,
						_limit: 100,
					},
				)
				for (const st of statusTypes || []) {
					const ok = await this.objectStore.deleteObject(
						'statusType',
						st.id,
					)
					if (!ok) {
						this.error = t(
							'procest',
							'Failed to delete status type "{name}"',
							{ name: st.name },
						)
						await this.fetchCaseTypes()
						return
					}
				}
			}

			try {
				await axios.delete(
					generateUrl('/apps/procest/api/case-definitions/{id}', {
						id: ct.id,
					}),
				)
			} catch (err) {
				this.error =
					err.response?.status === 409
						? t(
								'procest',
								'Cannot delete: unpublish this case type first',
							)
						: err.response?.data?.error
							|| t('procest', 'Failed to delete case type')
				await this.fetchCaseTypes()
				return
			}

			if (this.defaultCaseTypeId === ct.id) {
				const config = {
					...this.settingsStore.config,
					default_case_type: '',
				}
				await this.settingsStore.saveSettings(config)
			}

			await this.fetchCaseTypes()
		},

		/**
		 * Deep-copy a case type into a new draft, then navigate to it.
		 *
		 * @param ct
		 * @spec openspec/changes/zaaktype-copy/tasks.md#T09
		 */
		async duplicate(ct) {
			this.error = ''
			this.duplicating = ct.id
			try {
				const response = await axios.post(
					generateUrl('/apps/procest/api/case-definitions/{id}/copy', {
						id: ct.id,
					}),
				)
				const newId = response.data?.id
				await this.fetchCaseTypes()
				if (newId) {
					this.$emit('select', newId)
				}
			} catch (err) {
				this.error =
					err.response?.data?.error
					|| t('procest', 'Failed to duplicate case type')
			} finally {
				this.duplicating = null
			}
		},
	},
}
</script>

<style scoped>
.ct-title {
	display: flex;
	align-items: center;
	gap: 6px;
	font-weight: 500;
}

.default-star {
	color: var(--color-warning);
}

.ct-badge {
	display: inline-block;
	padding: 2px 8px;
	border-radius: var(--border-radius-pill);
	font-size: 12px;
	font-weight: 500;
}

.ct-badge--published {
	background: var(--color-success);
	color: white;
}

.ct-badge--draft {
	background: var(--color-warning);
	color: var(--color-warning-text);
}

.validity--expired {
	color: var(--color-error);
	font-weight: 500;
}

.ct-actions {
	display: flex;
	gap: 4px;
}

.ct-error {
	color: var(--color-error);
	margin-top: 12px;
	padding: 8px;
	background: var(--color-error-light, rgba(var(--color-error-rgb), 0.1));
	border-radius: var(--border-radius);
}
</style>
