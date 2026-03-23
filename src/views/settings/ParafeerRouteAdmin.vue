<template>
	<div class="parafeerroute-admin">
		<div class="parafeerroute-admin__header">
			<h3>{{ t('procest', 'Parafeerroutes') }}</h3>
			<NcButton type="primary" @click="startCreate">
				{{ t('procest', 'Nieuwe route') }}
			</NcButton>
		</div>

		<NcLoadingIcon v-if="loading" />

		<div v-else-if="routes.length === 0" class="parafeerroute-admin__empty">
			{{ t('procest', 'Geen parafeerroutes geconfigureerd.') }}
		</div>

		<div v-else class="parafeerroute-admin__list">
			<div
				v-for="route in routes"
				:key="route.id"
				class="parafeerroute-admin__item"
				:class="{ 'parafeerroute-admin__item--selected': selectedRoute?.id === route.id }"
				@click="selectRoute(route)">
				<div class="parafeerroute-admin__item-info">
					<strong>{{ route.name }}</strong>
					<span class="parafeerroute-admin__item-meta">
						{{ formatVoorstelType(route.voorstelType) }}
						<span v-if="route.isDefault" class="parafeerroute-admin__default-badge">
							{{ t('procest', 'Standaard') }}
						</span>
					</span>
				</div>
				<span class="parafeerroute-admin__step-count">
					{{ getStepCount(route) }} {{ t('procest', 'stappen') }}
				</span>
			</div>
		</div>

		<!-- Edit panel -->
		<div v-if="editingRoute" class="parafeerroute-admin__edit">
			<h4>{{ isNew ? t('procest', 'Nieuwe parafeerroute') : t('procest', 'Parafeerroute bewerken') }}</h4>

			<div class="form-group">
				<label>{{ t('procest', 'Naam') }} *</label>
				<NcTextField
					:value="editingRoute.name"
					@update:value="v => editingRoute.name = v" />
			</div>

			<div class="form-group">
				<label>{{ t('procest', 'Voorstel type') }}</label>
				<NcSelect
					v-model="editingRoute.voorstelType"
					:options="voorstelTypeOptions"
					:placeholder="t('procest', 'Selecteer type...')" />
			</div>

			<div class="form-group">
				<label>{{ t('procest', 'Zaaktype') }}</label>
				<NcSelect
					v-model="editingRoute.caseType"
					:options="caseTypes"
					label="title"
					track-by="id"
					:reduce="ct => ct.id"
					:placeholder="t('procest', 'Selecteer zaaktype...')" />
			</div>

			<div class="form-group">
				<label>
					<input v-model="editingRoute.isDefault" type="checkbox">
					{{ t('procest', 'Standaard route voor dit type') }}
				</label>
			</div>

			<!-- Steps editor -->
			<h4>{{ t('procest', 'Stappen') }}</h4>
			<div v-for="(step, idx) in editingSteps" :key="idx" class="parafeerroute-admin__step">
				<span class="parafeerroute-admin__step-order">{{ idx + 1 }}</span>
				<NcTextField
					:value="step.label"
					:placeholder="t('procest', 'Label')"
					class="parafeerroute-admin__step-label"
					@update:value="v => step.label = v" />
				<NcSelect
					v-model="step.type"
					:options="stepTypeOptions"
					class="parafeerroute-admin__step-type" />
				<NcTextField
					:value="step.actor"
					:placeholder="t('procest', 'Actor (gebruikers-ID)')"
					class="parafeerroute-admin__step-actor"
					@update:value="v => step.actor = v" />
				<NcSelect
					v-model="step.actorType"
					:options="actorTypeOptions"
					class="parafeerroute-admin__step-actor-type" />
				<NcButton type="tertiary-no-background" @click="removeStep(idx)">
					<template #icon>
						<Delete :size="20" />
					</template>
				</NcButton>
			</div>

			<NcButton @click="addStep">
				{{ t('procest', 'Stap toevoegen') }}
			</NcButton>

			<div class="parafeerroute-admin__actions">
				<NcButton type="primary" :disabled="saving" @click="saveRoute">
					{{ t('procest', 'Opslaan') }}
				</NcButton>
				<NcButton @click="cancelEdit">
					{{ t('procest', 'Annuleren') }}
				</NcButton>
				<NcButton v-if="!isNew" type="error" @click="deleteRoute">
					{{ t('procest', 'Verwijderen') }}
				</NcButton>
			</div>
		</div>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon, NcSelect, NcTextField } from '@nextcloud/vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import { useObjectStore } from '../../store/modules/object.js'

export default {
	name: 'ParafeerRouteAdmin',
	components: {
		NcButton,
		NcLoadingIcon,
		NcSelect,
		NcTextField,
		Delete,
	},
	data() {
		return {
			loading: true,
			saving: false,
			routes: [],
			caseTypes: [],
			selectedRoute: null,
			editingRoute: null,
			editingSteps: [],
			isNew: false,
			voorstelTypeOptions: ['dt_advies', 'collegeadvies', 'raadsvoorstel'],
			stepTypeOptions: ['advies', 'parafering', 'accordering'],
			actorTypeOptions: ['user', 'group', 'role'],
		}
	},
	computed: {
		objectStore() {
			return useObjectStore()
		},
	},
	async created() {
		await this.loadData()
	},
	methods: {
		async loadData() {
			this.loading = true
			try {
				const [routes, caseTypes] = await Promise.all([
					this.objectStore.fetchCollection('parafeerroute', { _limit: 100 }),
					this.objectStore.fetchCollection('caseType', { _limit: 100 }),
				])
				this.routes = Array.isArray(routes) ? routes : (routes?.results || [])
				this.caseTypes = Array.isArray(caseTypes) ? caseTypes : (caseTypes?.results || [])
			} catch (error) {
				console.error('Failed to load parafeerroute data:', error)
			} finally {
				this.loading = false
			}
		},
		formatVoorstelType(type) {
			const labels = { dt_advies: 'DT-advies', collegeadvies: 'Collegeadvies', raadsvoorstel: 'Raadsvoorstel' }
			return labels[type] || type || '-'
		},
		getStepCount(route) {
			const steps = typeof route.steps === 'string' ? JSON.parse(route.steps || '[]') : (route.steps || [])
			return steps.length
		},
		selectRoute(route) {
			this.selectedRoute = route
			this.isNew = false
			const steps = typeof route.steps === 'string' ? JSON.parse(route.steps || '[]') : (route.steps || [])
			this.editingRoute = { ...route }
			this.editingSteps = steps.map(s => ({ ...s }))
		},
		startCreate() {
			this.isNew = true
			this.selectedRoute = null
			this.editingRoute = {
				name: '',
				voorstelType: 'collegeadvies',
				caseType: null,
				isDefault: false,
			}
			this.editingSteps = []
		},
		addStep() {
			this.editingSteps.push({
				order: this.editingSteps.length + 1,
				type: 'parafering',
				actor: '',
				actorType: 'user',
				mandatory: true,
				label: '',
			})
		},
		removeStep(idx) {
			this.editingSteps.splice(idx, 1)
			this.editingSteps.forEach((s, i) => { s.order = i + 1 })
		},
		cancelEdit() {
			this.editingRoute = null
			this.editingSteps = []
			this.selectedRoute = null
			this.isNew = false
		},
		async saveRoute() {
			if (!this.editingRoute.name?.trim()) return
			this.saving = true
			try {
				const routeData = {
					...this.editingRoute,
					steps: this.editingSteps.map((s, i) => ({ ...s, order: i + 1 })),
				}
				await this.objectStore.saveObject('parafeerroute', routeData)
				await this.loadData()
				this.cancelEdit()
			} catch (error) {
				console.error('Failed to save parafeerroute:', error)
			} finally {
				this.saving = false
			}
		},
		async deleteRoute() {
			if (!this.editingRoute?.id) return
			try {
				await this.objectStore.deleteObject('parafeerroute', this.editingRoute.id)
				await this.loadData()
				this.cancelEdit()
			} catch (error) {
				console.error('Failed to delete parafeerroute:', error)
			}
		},
	},
}
</script>

<style scoped>
.parafeerroute-admin__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 12px;
}

.parafeerroute-admin__list {
	margin-bottom: 16px;
}

.parafeerroute-admin__item {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 10px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	margin-bottom: 4px;
	cursor: pointer;
}

.parafeerroute-admin__item:hover {
	background: var(--color-background-hover);
}

.parafeerroute-admin__item--selected {
	border-color: var(--color-primary-element);
	background: var(--color-primary-element-light);
}

.parafeerroute-admin__item-meta {
	display: block;
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
}

.parafeerroute-admin__default-badge {
	display: inline-block;
	padding: 1px 6px;
	border-radius: var(--border-radius);
	background: var(--color-primary-element-light);
	color: var(--color-primary-element);
	font-size: 0.85em;
	margin-left: 4px;
}

.parafeerroute-admin__step-count {
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
}

.parafeerroute-admin__edit {
	margin-top: 16px;
	padding: 16px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
}

.form-group {
	margin-bottom: 12px;
}

.form-group label {
	display: block;
	font-weight: 600;
	margin-bottom: 4px;
}

.parafeerroute-admin__step {
	display: flex;
	gap: 8px;
	align-items: center;
	margin-bottom: 8px;
}

.parafeerroute-admin__step-order {
	width: 24px;
	text-align: center;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}

.parafeerroute-admin__step-label {
	flex: 2;
}

.parafeerroute-admin__step-type {
	flex: 1;
}

.parafeerroute-admin__step-actor {
	flex: 2;
}

.parafeerroute-admin__step-actor-type {
	flex: 1;
}

.parafeerroute-admin__actions {
	display: flex;
	gap: 8px;
	margin-top: 16px;
}

.parafeerroute-admin__empty {
	color: var(--color-text-maxcontrast);
	padding: 12px 0;
}
</style>
