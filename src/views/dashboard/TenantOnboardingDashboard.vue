<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  Tenant Onboarding Dashboard — 7-step progress, go-live readiness check,
  activate-tenant action. Renders the TenantOnboardingService progress and
  validateGoLive() output (chain member 07).
-->
<template>
	<div class="tenant-onboarding">
		<div class="tenant-onboarding__header">
			<h2>{{ t('procest', 'Tenant onboarding') }}</h2>
			<div class="tenant-onboarding__controls">
				<NcSelect
					:value="selectedTenant"
					:options="tenantOptions"
					:input-label="t('procest', 'Tenant')"
					:placeholder="t('procest', 'Pick a tenant')"
					@input="onTenantChange" />
				<NcButton type="secondary" @click="loadProgress">
					<template #icon><Refresh :size="18" /></template>
					{{ t('procest', 'Refresh') }}
				</NcButton>
			</div>
		</div>

		<NcLoadingIcon v-if="loading" :size="32" />
		<NcNoteCard v-if="error" type="error">{{ error }}</NcNoteCard>

		<NcEmptyContent v-if="!loading && !tenantId">
			<template #icon><AccountTie :size="48" /></template>
			<template #default>
				{{ t('procest', 'Select a tenant to view onboarding progress.') }}
			</template>
		</NcEmptyContent>

		<div v-if="!loading && tenantId && progress" class="tenant-onboarding__body">
			<div class="tenant-onboarding__progress">
				<div class="tenant-onboarding__progress-bar">
					<div class="tenant-onboarding__progress-bar-fill" :style="{ width: progressPercent + '%' }" />
				</div>
				<span class="tenant-onboarding__progress-label">
					{{ completedSteps }} / {{ totalSteps }} {{ t('procest', 'steps complete') }} ({{ progressPercent }}%)
				</span>
			</div>

			<ul class="tenant-onboarding__steps">
				<li
					v-for="step in steps"
					:key="step.step"
					class="tenant-onboarding__step"
					:class="{ 'tenant-onboarding__step--done': step.status === 'complete' }">
					<div class="tenant-onboarding__step-icon">
						<CheckCircle v-if="step.status === 'complete'" :size="22" />
						<CircleOutline v-else :size="22" />
					</div>
					<div class="tenant-onboarding__step-body">
						<div class="tenant-onboarding__step-title">{{ stepLabel(step.step) }}</div>
						<div v-if="step.completedAt" class="tenant-onboarding__step-meta">
							{{ t('procest', 'Completed {at} by {who}', { at: step.completedAt, who: step.completedBy || '—' }) }}
						</div>
					</div>
					<NcButton
						v-if="step.status !== 'complete'"
						size="small"
						type="primary"
						:disabled="markingStep === step.step"
						@click="markComplete(step.step)">
						<template #icon>
							<NcLoadingIcon v-if="markingStep === step.step" :size="16" />
							<Check v-else :size="16" />
						</template>
						{{ t('procest', 'Mark complete') }}
					</NcButton>
				</li>
			</ul>

			<div class="tenant-onboarding__go-live">
				<h3>{{ t('procest', 'Go-live readiness') }}</h3>
				<NcButton :disabled="checkingGoLive" type="secondary" @click="checkGoLive">
					<template #icon>
						<NcLoadingIcon v-if="checkingGoLive" :size="18" />
						<RocketLaunch v-else :size="18" />
					</template>
					{{ t('procest', 'Check readiness') }}
				</NcButton>
				<div v-if="goLiveResult" class="tenant-onboarding__go-live-result">
					<div v-if="goLiveResult.ready" class="tenant-onboarding__go-live-ready">
						<CheckCircle :size="24" />
						<span>{{ t('procest', 'Tenant is ready to go live.') }}</span>
						<NcButton type="primary" :disabled="activating" @click="activate">
							<template #icon>
								<NcLoadingIcon v-if="activating" :size="18" />
								<Power v-else :size="18" />
							</template>
							{{ t('procest', 'Activate tenant') }}
						</NcButton>
					</div>
					<div v-else class="tenant-onboarding__go-live-blocked">
						<AlertCircle :size="24" />
						<div>
							<strong>{{ t('procest', 'Not ready. Missing:') }}</strong>
							<ul class="tenant-onboarding__missing">
								<li v-for="m in (goLiveResult.missing || [])" :key="m">{{ m }}</li>
							</ul>
						</div>
					</div>
				</div>
			</div>
		</div>
	</div>
</template>

<script>
import {
	NcButton,
	NcEmptyContent,
	NcLoadingIcon,
	NcNoteCard,
	NcSelect,
} from '@nextcloud/vue'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import RocketLaunch from 'vue-material-design-icons/RocketLaunch.vue'
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import CircleOutline from 'vue-material-design-icons/CircleOutline.vue'
import AlertCircle from 'vue-material-design-icons/AlertCircle.vue'
import Power from 'vue-material-design-icons/Power.vue'
import Check from 'vue-material-design-icons/Check.vue'
import AccountTie from 'vue-material-design-icons/AccountTie.vue'

const STEP_LABELS = {
	tenant_provisioning: 'Tenant provisioning',
	mandate_validation: 'Mandate validation',
	contract_signature: 'Contract signature',
	admin_account: 'Admin account creation',
	zaaktype_seed: 'Zaaktype configuration',
	branding: 'Tenant branding',
	welcome_email: 'Welcome email',
}

export default {
	name: 'TenantOnboardingDashboard',
	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
		Refresh,
		RocketLaunch,
		CheckCircle,
		CircleOutline,
		AlertCircle,
		Power,
		Check,
		AccountTie,
	},
	data() {
		return {
			loading: false,
			error: null,
			tenantOptions: [],
			tenantId: '',
			progress: null,
			markingStep: '',
			goLiveResult: null,
			checkingGoLive: false,
			activating: false,
		}
	},
	computed: {
		/** @spec openspec/changes/tenant-zaaksysteem-saas-07-onboarding-workflow/tasks.md */
		selectedTenant() {
			return this.tenantOptions.find(o => o.id === this.tenantId) || null
		},
		/** @spec openspec/changes/tenant-zaaksysteem-saas-07-onboarding-workflow/tasks.md */
		steps() {
			if (!this.progress) return []
			return this.progress.steps || this.progress.tasks || []
		},
		/** @spec openspec/changes/tenant-zaaksysteem-saas-07-onboarding-workflow/tasks.md */
		totalSteps() {
			return this.steps.length || 7
		},
		/** @spec openspec/changes/tenant-zaaksysteem-saas-07-onboarding-workflow/tasks.md */
		completedSteps() {
			return this.steps.filter(s => s.status === 'complete').length
		},
		/** @spec openspec/changes/tenant-zaaksysteem-saas-07-onboarding-workflow/tasks.md */
		progressPercent() {
			if (!this.totalSteps) return 0
			return Math.round((this.completedSteps / this.totalSteps) * 100)
		},
	},
	mounted() {
		this.loadTenants()
	},
	methods: {
		t,
		/** @spec openspec/changes/tenant-zaaksysteem-saas-07-onboarding-workflow/tasks.md */
		stepLabel(step) {
			return t('procest', STEP_LABELS[step] || step)
		},
		/** @spec openspec/changes/tenant-zaaksysteem-saas-07-onboarding-workflow/tasks.md */
		onTenantChange(opt) {
			this.tenantId = opt ? opt.id : ''
			this.progress = null
			this.goLiveResult = null
			if (this.tenantId) this.loadProgress()
		},
		/** @spec openspec/changes/tenant-zaaksysteem-saas-07-onboarding-workflow/tasks.md */
		async loadTenants() {
			this.loading = true
			try {
				const res = await axios.get(generateUrl('/apps/procest/api/saas/tenants'))
				const list = Array.isArray(res.data) ? res.data : (res.data?.results || [])
				this.tenantOptions = list.map(tn => ({
					id: tn.id || tn.tenantId,
					label: tn.naam || tn.label || tn.id,
				}))
			} catch (e) {
				this.error = e?.response?.data?.message || e.message || t('procest', 'Failed to load tenants')
			} finally {
				this.loading = false
			}
		},
		/** @spec openspec/changes/tenant-zaaksysteem-saas-07-onboarding-workflow/tasks.md */
		async loadProgress() {
			if (!this.tenantId) return
			this.loading = true
			this.error = null
			try {
				const res = await axios.get(
					generateUrl('/apps/procest/api/saas/tenants/' + encodeURIComponent(this.tenantId) + '/onboarding/progress'),
				)
				this.progress = res.data || null
			} catch (e) {
				if (e?.response?.status === 404) {
					// Not initialised yet — offer to init.
					await this.initialise()
				} else {
					this.error = e?.response?.data?.message || e.message || t('procest', 'Failed to load progress')
				}
			} finally {
				this.loading = false
			}
		},
		/** @spec openspec/changes/tenant-zaaksysteem-saas-07-onboarding-workflow/tasks.md */
		async initialise() {
			try {
				await axios.post(
					generateUrl('/apps/procest/api/saas/tenants/' + encodeURIComponent(this.tenantId) + '/onboarding/initialise'),
				)
				const res = await axios.get(
					generateUrl('/apps/procest/api/saas/tenants/' + encodeURIComponent(this.tenantId) + '/onboarding/progress'),
				)
				this.progress = res.data
			} catch (e) {
				this.error = e?.response?.data?.message || e.message || t('procest', 'Failed to initialise')
			}
		},
		/** @spec openspec/changes/tenant-zaaksysteem-saas-07-onboarding-workflow/tasks.md */
		async markComplete(step) {
			this.markingStep = step
			try {
				await axios.post(
					generateUrl('/apps/procest/api/saas/tenants/' + encodeURIComponent(this.tenantId) + '/onboarding/' + encodeURIComponent(step) + '/complete'),
				)
				await this.loadProgress()
			} catch (e) {
				this.error = e?.response?.data?.message || e.message || t('procest', 'Failed to mark step complete')
			} finally {
				this.markingStep = ''
			}
		},
		/** @spec openspec/changes/tenant-zaaksysteem-saas-07-onboarding-workflow/tasks.md */
		async checkGoLive() {
			this.checkingGoLive = true
			try {
				const res = await axios.get(
					generateUrl('/apps/procest/api/saas/tenants/' + encodeURIComponent(this.tenantId) + '/onboarding/progress'),
					{ params: { check: 'go-live' } },
				)
				this.goLiveResult = res.data?.goLive || res.data?.readiness || null
				// Fallback: if backend doesn't return goLive inline, derive ready=completedSteps===totalSteps.
				if (!this.goLiveResult) {
					this.goLiveResult = {
						ready: this.completedSteps === this.totalSteps,
						missing: this.completedSteps === this.totalSteps ? [] : [t('procest', 'Open onboarding steps')],
					}
				}
			} catch (e) {
				this.error = e?.response?.data?.message || e.message || t('procest', 'Go-live check failed')
			} finally {
				this.checkingGoLive = false
			}
		},
		/** @spec openspec/changes/tenant-zaaksysteem-saas-07-onboarding-workflow/tasks.md */
		async activate() {
			this.activating = true
			try {
				await axios.post(
					generateUrl('/apps/procest/api/saas/tenants/' + encodeURIComponent(this.tenantId) + '/onboarding/activate'),
				)
				await this.loadProgress()
				await this.checkGoLive()
			} catch (e) {
				this.error = e?.response?.data?.message || e.message || t('procest', 'Activate failed')
			} finally {
				this.activating = false
			}
		},
	},
}
</script>

<style scoped>
.tenant-onboarding {
	padding: 16px;
	max-width: 1000px;
	margin: 0 auto;
}

.tenant-onboarding__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	margin-bottom: 16px;
}

.tenant-onboarding__controls {
	display: flex;
	gap: 8px;
	align-items: center;
}

.tenant-onboarding__progress {
	display: flex;
	align-items: center;
	gap: 12px;
	margin: 12px 0 16px 0;
}

.tenant-onboarding__progress-bar {
	flex: 1;
	height: 10px;
	background: var(--color-background-dark);
	border-radius: 5px;
	overflow: hidden;
}

.tenant-onboarding__progress-bar-fill {
	height: 100%;
	background: var(--color-success);
	transition: width 0.3s ease-out;
}

.tenant-onboarding__progress-label {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.tenant-onboarding__steps {
	list-style: none;
	padding: 0;
	margin: 0 0 20px 0;
}

.tenant-onboarding__step {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 10px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	margin-bottom: 6px;
}

.tenant-onboarding__step--done {
	background: var(--color-background-dark);
	opacity: 0.7;
}

.tenant-onboarding__step-icon {
	color: var(--color-success);
}

.tenant-onboarding__step-body {
	flex: 1;
}

.tenant-onboarding__step-title {
	font-weight: 500;
}

.tenant-onboarding__step-meta {
	font-size: 11px;
	color: var(--color-text-maxcontrast);
}

.tenant-onboarding__go-live {
	margin-top: 24px;
	padding: 16px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background: var(--color-main-background);
}

.tenant-onboarding__go-live-result {
	margin-top: 12px;
}

.tenant-onboarding__go-live-ready {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 12px;
	background: var(--color-success);
	color: var(--color-main-background);
	border-radius: var(--border-radius);
}

.tenant-onboarding__go-live-blocked {
	display: flex;
	gap: 12px;
	padding: 12px;
	background: var(--color-warning);
	color: var(--color-main-background);
	border-radius: var(--border-radius);
}

.tenant-onboarding__missing {
	margin: 4px 0 0 16px;
	padding: 0;
}
</style>
