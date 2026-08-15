<template>
	<div class="parafeer-inbox">
		<h3 class="parafeer-inbox__title">
			{{ t('procest', 'For endorsement') }}
			<span v-if="!loading" class="parafeer-inbox__count"
				>({{ pendingVoorstellen.length }})</span
			>
		</h3>

		<NcLoadingIcon v-if="loading" :size="20" />

		<div
			v-else-if="pendingVoorstellen.length === 0"
			class="parafeer-inbox__empty">
			{{ t('procest', 'No proposals awaiting endorsement') }}
		</div>

		<div v-else class="parafeer-inbox__list">
			<div
				v-for="voorstel in pendingVoorstellen"
				:key="voorstel.id"
				class="parafeer-inbox__item">
				<div class="parafeer-inbox__item-info">
					<strong>{{ voorstel.subject }}</strong>
					<span class="parafeer-inbox__item-meta">
						{{ formatType(voorstel.type) }} — {{ t('procest', 'by') }}
						{{ voorstel.author }} — {{ t('procest', 'waiting since') }}
						{{ formatDate(voorstel) }}
					</span>
				</div>
				<div class="parafeer-inbox__item-actions">
					<NcButton
						type="primary"
						:aria-label="t('procest', 'Endorse')"
						@click="
							$router.push({
								name: 'VoorstelDetail',
								params: { id: voorstel.id },
							})
						">
						{{ t('procest', 'View') }}
					</NcButton>
				</div>
			</div>
		</div>
	</div>
</template>

<script>
import { getCurrentUser } from '@nextcloud/auth'
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import { useObjectStore } from '../../../store/modules/object.js'
import { isActiveActor } from '../../../utils/parafeerEngine.js'

const TYPE_LABELS = {
	dt_advies: 'Management team advice',
	collegeadvies: 'Executive board advice',
	raadsvoorstel: 'Council proposal',
}

export default {
	name: 'ParafeerInbox',
	components: {
		NcButton,
		NcLoadingIcon,
	},

	data() {
		return {
			loading: true,
			voorstellen: [],
		}
	},

	computed: {
		/** @spec openspec/specs/parafering-actions/spec.md */
		objectStore() {
			return useObjectStore()
		},

		/** @spec openspec/specs/parafering-actions/spec.md */
		currentUserId() {
			return getCurrentUser()?.uid || ''
		},

		/** @spec openspec/specs/parafering-actions/spec.md */
		pendingVoorstellen() {
			return this.voorstellen.filter(
				(v) =>
					['in_parafering', 'ter_accordering'].includes(v.status)
					&& isActiveActor(v, this.currentUserId),
			)
		},
	},

	async created() {
		await this.loadVoorstellen()
	},

	methods: {
		/** @spec openspec/specs/parafering-actions/spec.md */
		async loadVoorstellen() {
			this.loading = true
			try {
				const results = await this.objectStore.fetchCollection('voorstel', {
					_limit: 200,
				})
				this.voorstellen = Array.isArray(results)
					? results
					: results?.results || []
			} catch (error) {
				console.error('Failed to load voorstellen for inbox:', error)
				this.voorstellen = []
			} finally {
				this.loading = false
			}
		},

		/**
		 * @param type
		 * @spec openspec/specs/parafering-actions/spec.md
		 */
		formatType(type) {
			return t('procest', TYPE_LABELS[type] || type || '-')
		},

		/**
		 * @param voorstel
		 * @spec openspec/specs/parafering-actions/spec.md
		 */
		formatDate(voorstel) {
			const date = voorstel._self?.updated || voorstel.updatedAt
			if (!date) return '-'
			return new Date(date).toLocaleDateString('nl-NL')
		},
	},
}
</script>

<style scoped>
.parafeer-inbox__title {
	font-size: 1.1em;
	margin-bottom: 8px;
}

.parafeer-inbox__count {
	color: var(--color-text-maxcontrast);
	font-weight: 400;
}

.parafeer-inbox__empty {
	color: var(--color-text-maxcontrast);
	padding: 8px 0;
}

.parafeer-inbox__item {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 10px 0;
	border-bottom: 1px solid var(--color-border);
}

.parafeer-inbox__item:last-child {
	border-bottom: none;
}

.parafeer-inbox__item-meta {
	display: block;
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
}
</style>
