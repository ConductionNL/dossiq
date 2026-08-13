<template>
	<div class="voorstellen-panel">
		<NcLoadingIcon v-if="loading" :size="20" />

		<template v-else>
			<div v-if="voorstellen.length === 0" class="voorstellen-panel__empty">
				{{ t('procest', 'No proposals') }}
			</div>

			<div v-else class="voorstellen-panel__list">
				<div
					v-for="voorstel in voorstellen"
					:key="voorstel.id"
					class="voorstellen-panel__item"
					:class="{ 'voorstellen-panel__item--active': isActive(voorstel) }"
					role="button"
					tabindex="0"
					@click="$router.push({ name: 'VoorstelDetail', params: { id: voorstel.id } })"
					@keydown.enter="$router.push({ name: 'VoorstelDetail', params: { id: voorstel.id } })"
					@keydown.space.prevent="$router.push({ name: 'VoorstelDetail', params: { id: voorstel.id } })">
					<div class="voorstellen-panel__item-header">
						<span class="voorstellen-panel__type">{{ formatType(voorstel.type) }}</span>
						<span class="voorstellen-panel__status" :class="`voorstellen-panel__status--${voorstel.status}`">
							{{ formatStatus(voorstel.status) }}
						</span>
					</div>
					<div class="voorstellen-panel__item-meta">
						{{ voorstel.steller }}
						<span v-if="voorstel.currentStep && isActive(voorstel)">
							— {{ t('procest', 'step') }} {{ formatStepProgress(voorstel) }}
						</span>
					</div>
				</div>
			</div>

			<NcButton
				v-if="!isReadOnly"
				class="voorstellen-panel__add"
				@click="showCreate = true">
				{{ t('procest', 'New proposal') }}
			</NcButton>
		</template>

		<VoorstelCreateDialog
			v-if="showCreate"
			:case-id="caseId"
			:case-title="caseTitle"
			@close="showCreate = false"
			@created="onCreated" />
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import VoorstelCreateDialog from '../../../dialogs/VoorstelCreateDialog.vue'
import { useObjectStore } from '../../../store/modules/object.js'

const STATUS_LABELS = {
	concept: 'Draft',
	in_parafering: 'Awaiting initials',
	ter_accordering: 'Awaiting approval',
	geaccordeerd: 'Approved',
	aangeboden: 'Presented',
	besloten: 'Decided',
	gearchiveerd: 'Archived',
	teruggestuurd: 'Returned',
}

const TYPE_LABELS = {
	dt_advies: 'Management team advice',
	collegeadvies: 'Executive board advice',
	raadsvoorstel: 'Council proposal',
}

export default {
	name: 'VoorstellenPanel',
	components: {
		NcButton,
		NcLoadingIcon,
		VoorstelCreateDialog,
	},
	props: {
		caseId: {
			type: String,
			required: true,
		},
		caseTitle: {
			type: String,
			default: '',
		},
		isReadOnly: {
			type: Boolean,
			default: false,
		},
	},
	data() {
		return {
			loading: true,
			voorstellen: [],
			showCreate: false,
		}
	},
	computed: {
		/** @spec openspec/specs/parafering-actions/spec.md */
		objectStore() {
			return useObjectStore()
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
				const results = await this.objectStore.fetchCollection('proposal', {
					'_filters[case]': this.caseId,
					_limit: 50,
				})
				this.voorstellen = Array.isArray(results) ? results : (results?.results || [])
			} catch (error) {
				console.error('Failed to load voorstellen for case:', error)
				this.voorstellen = []
			} finally {
				this.loading = false
			}
		},
		isActive(voorstel) {
			return !['besloten', 'gearchiveerd'].includes(voorstel.status)
		},
		/**
		 * @param type
		 * @spec openspec/specs/parafering-actions/spec.md
		 */
		formatType(type) {
			return t('procest', TYPE_LABELS[type] || type || '-')
		},
		/**
		 * @param status
		 * @spec openspec/specs/parafering-actions/spec.md
		 */
		formatStatus(status) {
			return t('procest', STATUS_LABELS[status] || status || '-')
		},
		/**
		 * @param voorstel
		 * @spec openspec/specs/parafering-actions/spec.md
		 */
		formatStepProgress(voorstel) {
			let steps = []
			if (voorstel.routeSnapshot) {
				try {
					steps = typeof voorstel.routeSnapshot === 'string'
						? JSON.parse(voorstel.routeSnapshot)
						: voorstel.routeSnapshot
				} catch {
					// ignore
				}
			}
			if (!steps.length) return `${voorstel.currentStep}`
			return `${voorstel.currentStep}/${steps.length}`
		},
		/** @spec openspec/specs/parafering-actions/spec.md */
		async onCreated() {
			this.showCreate = false
			await this.loadVoorstellen()
		},
	},
}
</script>

<style scoped>
.voorstellen-panel__empty {
	color: var(--color-text-maxcontrast);
	padding: 4px 0;
}

.voorstellen-panel__item {
	padding: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	margin-bottom: 4px;
	cursor: pointer;
}

.voorstellen-panel__item:hover {
	background: var(--color-background-hover);
}

.voorstellen-panel__item--active {
	border-left: 3px solid var(--color-primary-element);
}

.voorstellen-panel__item-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
}

.voorstellen-panel__type {
	font-weight: 600;
	font-size: 0.9em;
}

.voorstellen-panel__status {
	font-size: 0.8em;
	padding: 1px 6px;
	border-radius: var(--border-radius);
}

.voorstellen-panel__status--concept { background: var(--color-background-dark); }

.voorstellen-panel__status--in_parafering { background: var(--color-primary-element-light); color: var(--color-primary-element); }

.voorstellen-panel__status--geaccordeerd { background: var(--color-success-light, #e8f5e9); color: var(--color-success, #2e7d32); }

.voorstellen-panel__status--besloten { background: var(--color-success-light, #e8f5e9); color: var(--color-success, #2e7d32); }

.voorstellen-panel__status--teruggestuurd { background: var(--color-warning-light, #fff3e0); color: var(--color-warning, #e65100); }

.voorstellen-panel__item-meta {
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
	margin-top: 2px;
}

.voorstellen-panel__add {
	margin-top: 8px;
}
</style>
