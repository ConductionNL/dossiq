<template>
	<div>
		<!--
			`rowClickToView` IS WHAT MAKES A ROW OPEN ITS CASE TYPE.

			With `selectable` set and `rowClickToView` absent, nextcloud-vue's
			CnIndexPage and CnDataTable both treat a row-body click as a
			selection toggle and RETURN before emitting `row-click`
			(CnDataTable.onRowClick: `if (this.selectable && !this.rowClickToView)
			{ this.toggleSelect(row); return }`). So `@rowClick="selectCaseType"`
			below could never fire, and an admin had no way to open a case type
			from this list: clicking a row only ticked its checkbox. With it set,
			the body click opens the row and selection moves to the checkbox
			column, which is exactly the split the library documents.
		-->
		<CnIndexPage
			:title="t('dossiq', 'Case Types')"
			:description="t('dossiq', 'Configure case types')"
			:schema="schema"
			:objects="caseTypes"
			:loading="loading"
			:selectable="true"
			:rowClickToView="true"
			@add="$emit('create')"
			@refresh="fetchCaseTypes"
			@rowClick="selectCaseType">
			<!--
				THE EMPTY LIST IS THE FIRST THING A NEW ADMIN SEES, AND IT SAID
				"No items found".

				That is `CnIndexPage`'s own default, and it tells an admin
				looking at a blank Case Type Management section nothing about
				what a case type is or that they are expected to make one.
				admin-settings `#empty-case-type-list` asks for an empty state
				message and for guidance towards the first case type, so the
				page says both here rather than inheriting a generic line.
			-->
			<template #empty>
				<NcEmptyContent
					:name="t('dossiq', 'No case types configured yet')"
					:description="
						t(
							'dossiq',
							'Create your first case type to start handling cases.',
						)
					">
					<template #icon>
						<ShapeOutlineIcon :size="64" />
					</template>
				</NcEmptyContent>
			</template>

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
						row.isDraft ? t('dossiq', 'Draft') : t('dossiq', 'Published')
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
						:title="t('dossiq', 'Set as default')"
						@click="setDefault(row)">
						<template #icon>
							<StarIcon :size="20" />
						</template>
					</NcButton>
					<NcButton
						type="tertiary"
						:disabled="duplicating === row.id"
						:title="t('dossiq', 'Duplicate')"
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
						:title="t('dossiq', 'Delete')"
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
import { NcButton, NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import ContentDuplicateIcon from 'vue-material-design-icons/ContentDuplicate.vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import ShapeOutlineIcon from 'vue-material-design-icons/ShapeOutline.vue'
import StarIcon from 'vue-material-design-icons/Star.vue'
import { useObjectStore } from '../../store/modules/object.js'
import { useSettingsStore } from '../../store/modules/settings.js'
import { formatDuration } from '../../utils/durationHelpers.js'

export default {
	name: 'CaseTypeList',
	components: {
		StarIcon,
		ShapeOutlineIcon,
		DeleteIcon,
		ContentDuplicateIcon,
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		CnIndexPage,
	},

	emits: ['create', 'select'],

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
		 * @param {string} caseTypeId Identifier of the case type id.
		 * @spec openspec/changes/retrofit-2026-05-24-case-types/tasks.md
		 */
		async loadStatusTypeCount(caseTypeId) {
			const statusTypes = await this.objectStore.fetchCollection(
				'statusType',
				{
					caseType: caseTypeId,
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
		 * @param {string} duration An ISO 8601 duration, for example P30D.
		 * @spec openspec/changes/retrofit-2026-05-24-case-types/tasks.md
		 */
		formatDeadline(duration) {
			return formatDuration(duration)
		},

		/**
		 * @param {object} ct The case type.
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
				return `${from} – ${until}`
			}
			return t('dossiq', '{from} – (no end)', { from })
		},

		/**
		 * @param {object} ct The case type.
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
		 * @param {object} row The row.
		 * @spec openspec/changes/retrofit-2026-05-24-case-types/tasks.md
		 */
		selectCaseType(row) {
			this.$emit('select', row.id)
		},

		/**
		 * @param {object} ct The case type.
		 * @spec openspec/changes/retrofit-2026-05-24-case-types/tasks.md
		 */
		async setDefault(ct) {
			this.error = ''
			if (ct.isDraft) {
				this.error = t(
					'dossiq',
					'Only published case types can be set as default',
				)
				return
			}
			const config = { ...this.settingsStore.config, default_case_type: ct.id }
			await this.settingsStore.saveSettings(config)
		},

		/**
		 * @param {object} ct The case type.
		 * @spec openspec/changes/retrofit-2026-05-24-case-types/tasks.md
		 */
		async confirmDelete(ct) {
			this.error = ''

			try {
				const cases = await this.objectStore.fetchCollection('case', {
					caseType: ct.id,
					_limit: 1,
				})
				if (cases && cases.length > 0) {
					this.error = t(
						'dossiq',
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
							'dossiq',
							'This will delete the case type and all {count} status types. Continue?',
							{ count: statusCount },
						)
					: t('dossiq', 'Delete case type "{title}"?', {
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
						caseType: ct.id,
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
							'dossiq',
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
					generateUrl('/apps/dossiq/api/case-definitions/{id}', {
						id: ct.id,
					}),
				)
			} catch (err) {
				this.error =
					err.response?.status === 409
						? t(
								'dossiq',
								'Cannot delete: unpublish this case type first',
							)
						: err.response?.data?.error
							|| t('dossiq', 'Failed to delete case type')
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
		 * @param {object} ct The case type.
		 * @spec openspec/changes/zaaktype-copy/tasks.md#T09
		 */
		async duplicate(ct) {
			this.error = ''
			this.duplicating = ct.id
			try {
				const response = await axios.post(
					generateUrl('/apps/dossiq/api/case-definitions/{id}/copy', {
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
					|| t('dossiq', 'Failed to duplicate case type')
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
