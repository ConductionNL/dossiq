<template>
	<div class="start-case-widget">
		<div v-if="loading" class="start-case-widget__loading">
			<NcLoadingIcon :size="44" />
		</div>
		<NcEmptyContent v-else-if="caseTypes.length === 0"
			:title="t('procest', 'No case types configured')"
			:description="t('procest', 'Configure case types in Procest admin settings')">
			<template #icon>
				<BriefcaseVariantOutline />
			</template>
		</NcEmptyContent>
		<div v-else class="start-case-widget__grid">
			<button v-for="caseType in caseTypes"
				:key="caseType.id"
				class="start-case-widget__card"
				:disabled="creating"
				:title="caseType.description || caseType.title"
				@click="startCase(caseType)">
				<BriefcaseVariantOutline :size="24" class="start-case-widget__card-icon" />
				<span class="start-case-widget__card-title">{{ caseType.title }}</span>
				<NcLoadingIcon v-if="creatingId === caseType.id" :size="20" />
			</button>
		</div>
	</div>
</template>

<script>
import { NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import { useObjectStore } from '../../store/modules/object.js'
import BriefcaseVariantOutline from 'vue-material-design-icons/BriefcaseVariantOutline.vue'

export default {
	name: 'StartCaseWidget',
	components: {
		NcEmptyContent,
		NcLoadingIcon,
		BriefcaseVariantOutline,
	},
	props: {
		title: {
			type: String,
			required: true,
		},
	},
	data() {
		return {
			loading: false,
			creating: false,
			creatingId: null,
			caseTypes: [],
		}
	},
	computed: {
		/** @spec openspec/changes/retrofit-2026-05-24-signalering-widgets/tasks.md */
		objectStore() {
			return useObjectStore()
		},
	},
	mounted() {
		this.fetchCaseTypes()
	},
	methods: {
		/**
		 * Fetch available case types from OpenRegister.
		 *
		 * @return {Promise<void>}
		 */
		/** @spec openspec/changes/retrofit-2026-05-24-signalering-widgets/tasks.md */
		async fetchCaseTypes() {
			this.loading = true
			try {
				const results = await this.objectStore.fetchCollection('caseType', {
					_limit: 50,
					isDraft: false,
				})
				this.caseTypes = results || []
			} catch (err) {
				console.error('[StartCaseWidget] Failed to fetch case types:', err)
				this.caseTypes = []
			} finally {
				this.loading = false
			}
		},
		/**
		 * Create a new case of the given type and navigate to it.
		 *
		 * @param {object} caseType The case type to start
		 * @return {Promise<void>}
		 */
		/**
		 * @param caseType
		 * @spec openspec/changes/retrofit-2026-05-24-signalering-widgets/tasks.md
		 */
		async startCase(caseType) {
			if (this.creating) {
				return
			}
			this.creating = true
			this.creatingId = caseType.id
			try {
				const today = new Date().toISOString().split('T')[0]
				const newCase = await this.objectStore.saveObject('case', {
					title: caseType.title,
					caseType: caseType.id,
					startDate: today,
				})
				if (newCase?.id) {
					window.location.href = generateUrl(`/apps/procest/cases/${newCase.id}`)
				}
			} catch (err) {
				console.error('[StartCaseWidget] Failed to create case:', err)
			} finally {
				this.creating = false
				this.creatingId = null
			}
		},
	},
}
</script>

<style scoped>
.start-case-widget {
	padding: 8px;
	height: 100%;
}

.start-case-widget__loading {
	display: flex;
	align-items: center;
	justify-content: center;
	height: 100%;
}

.start-case-widget__grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
	gap: 8px;
}

.start-case-widget__card {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 6px;
	padding: 12px 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background: var(--color-main-background);
	cursor: pointer;
	transition: background-color 0.15s ease, border-color 0.15s ease;
}

.start-case-widget__card:hover:not(:disabled) {
	background: var(--color-background-hover);
	border-color: var(--color-primary-element);
}

.start-case-widget__card:disabled {
	opacity: 0.5;
	cursor: not-allowed;
}

.start-case-widget__card-icon {
	color: var(--color-primary-element);
}

.start-case-widget__card-title {
	font-size: var(--default-font-size);
	font-weight: 500;
	color: var(--color-main-text);
	text-align: center;
	overflow: hidden;
	text-overflow: ellipsis;
	display: -webkit-box;
	-webkit-line-clamp: 2;
	-webkit-box-orient: vertical;
}
</style>
