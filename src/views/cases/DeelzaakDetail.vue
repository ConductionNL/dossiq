<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  DeelzaakDetail — full-page detail view of a single sub-case, with an
  always-visible breadcrumb back to the parent case. Mounted under
  /cases/:parentId/deelzaken/:id and shares the underlying `case`
  schema with CaseDetail — the breadcrumb is the differentiator.

  Components are kept lean here: the page renders a parent breadcrumb,
  basic identifying metadata, status, deadline and end-date, and links
  to the canonical CaseDetail view for the full edit experience.

  @spec openspec/changes/deelzaak-support/tasks.md#T06
-->
<template>
	<div class="deelzaak-detail">
		<!-- Parent breadcrumb (mandatory when viewing a sub-case) -->
		<nav v-if="parent" class="deelzaak-detail__breadcrumb" aria-label="breadcrumb">
			<router-link :to="parentRoute" class="deelzaak-detail__breadcrumb-link">
				<ArrowLeft :size="16" />
				{{ parent.title || parent.identifier || t('procest', 'Parent case') }}
			</router-link>
			<span class="deelzaak-detail__breadcrumb-sep" aria-hidden="true"> › </span>
			<span class="deelzaak-detail__breadcrumb-current">
				{{ subCase ? (subCase.title || subCase.identifier) : t('procest', 'Sub-case') }}
			</span>
		</nav>

		<NcLoadingIcon v-if="loading" :size="32" />

		<NcEmptyContent
			v-else-if="!subCase"
			:name="t('procest', 'Sub-case not found')"
			:description="t('procest', 'The sub-case could not be loaded. It may have been deleted or unlinked from its parent.')">
			<template #icon>
				<AlertCircleOutline :size="48" />
			</template>
			<template #action>
				<NcButton type="primary" @click="goToParent">
					{{ t('procest', 'Back to parent case') }}
				</NcButton>
			</template>
		</NcEmptyContent>

		<template v-else>
			<header class="deelzaak-detail__header">
				<div>
					<h2>{{ subCase.title || subCase.identifier }}</h2>
					<p class="deelzaak-detail__subtitle">
						{{ subCase.identifier || '—' }}
					</p>
				</div>
				<div class="deelzaak-detail__actions">
					<NcButton type="secondary" @click="goToFullCase">
						<template #icon>
							<OpenInNew :size="20" />
						</template>
						{{ t('procest', 'Open in case view') }}
					</NcButton>
					<NcButton type="tertiary" @click="goToParent">
						<template #icon>
							<ArrowLeft :size="20" />
						</template>
						{{ t('procest', 'Back to parent') }}
					</NcButton>
				</div>
			</header>

			<dl class="deelzaak-detail__grid">
				<div class="deelzaak-detail__row">
					<dt>{{ t('procest', 'Status') }}</dt>
					<dd>
						<span class="status-badge" :class="statusClass">
							{{ statusName }}
						</span>
					</dd>
				</div>
				<div class="deelzaak-detail__row">
					<dt>{{ t('procest', 'Assignee') }}</dt>
					<dd>{{ subCase.assignee || '—' }}</dd>
				</div>
				<div class="deelzaak-detail__row">
					<dt>{{ t('procest', 'Case type') }}</dt>
					<dd>{{ caseType ? (caseType.title || caseType.name) : '—' }}</dd>
				</div>
				<div class="deelzaak-detail__row">
					<dt>{{ t('procest', 'Start date') }}</dt>
					<dd>{{ formatDate(subCase.startDate) }}</dd>
				</div>
				<div class="deelzaak-detail__row">
					<dt>{{ t('procest', 'Deadline') }}</dt>
					<dd>{{ formatDate(subCase.deadline) }}</dd>
				</div>
				<div class="deelzaak-detail__row">
					<dt>{{ t('procest', 'Completed') }}</dt>
					<dd>{{ subCase.endDate ? formatDate(subCase.endDate) : t('procest', 'Open') }}</dd>
				</div>
			</dl>

			<section v-if="subCase.description" class="deelzaak-detail__section">
				<h3>{{ t('procest', 'Description') }}</h3>
				<p>{{ subCase.description }}</p>
			</section>
		</template>
	</div>
</template>

<script>
import { NcButton, NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import AlertCircleOutline from 'vue-material-design-icons/AlertCircleOutline.vue'
import ArrowLeft from 'vue-material-design-icons/ArrowLeft.vue'
import OpenInNew from 'vue-material-design-icons/OpenInNew.vue'

import { useObjectStore } from '../../store/modules/object.js'
import { useDeelzaakStore } from '../../store/modules/deelzaak.js'
import { formatDate } from '../../utils/caseHelpers.js'

export default {
	name: 'DeelzaakDetail',
	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		AlertCircleOutline,
		ArrowLeft,
		OpenInNew,
	},
	data() {
		return {
			subCase: null,
			parent: null,
			caseType: null,
			statusType: null,
			loading: true,
		}
	},
	computed: {
		objectStore() {
			return useObjectStore()
		},
		deelzaakStore() {
			return useDeelzaakStore()
		},
		parentIdFromRoute() {
			return this.$route?.params?.parentId || this.subCase?.parentCase || null
		},
		subCaseIdFromRoute() {
			return this.$route?.params?.id || null
		},
		parentRoute() {
			return this.parent
				? { name: 'CaseDetail', params: { id: this.parent.id } }
				: { name: 'Cases' }
		},
		statusName() {
			return this.statusType?.name || (this.subCase?.status ? '—' : t('procest', 'No status'))
		},
		statusClass() {
			if (!this.statusType) {
				return ''
			}
			if (this.statusType.isFinal === true || this.statusType.isFinal === 'true') {
				return 'status-badge--final'
			}
			return 'status-badge--active'
		},
	},
	watch: {
		subCaseIdFromRoute: {
			immediate: false,
			handler() {
				this.reload()
			},
		},
	},
	async mounted() {
		await this.reload()
	},
	methods: {
		formatDate,
		async reload() {
			if (!this.subCaseIdFromRoute) {
				this.loading = false
				return
			}
			this.loading = true
			try {
				this.subCase = await this.objectStore
					.fetchObject('case', this.subCaseIdFromRoute)
					.catch(() => null)

				if (!this.subCase) {
					return
				}

				const parentUuid = this.subCase.parentCase || this.parentIdFromRoute
				if (parentUuid) {
					// Prefer the store action so the parentCase getter stays warm.
					this.parent = await this.deelzaakStore
						.fetchParentCase(parentUuid)
						.catch(() => null)
					if (!this.parent) {
						this.parent = await this.objectStore
							.fetchObject('case', parentUuid)
							.catch(() => null)
					}
				} else {
					this.parent = null
				}

				if (this.subCase.caseType) {
					this.caseType = await this.objectStore
						.fetchObject('caseType', this.subCase.caseType)
						.catch(() => null)
				}
				if (this.subCase.status) {
					this.statusType = await this.objectStore
						.fetchObject('statusType', this.subCase.status)
						.catch(() => null)
				}
			} catch (err) {
				console.error('[DeelzaakDetail] reload failed', err)
			} finally {
				this.loading = false
			}
		},
		goToParent() {
			this.$router.push(this.parentRoute)
		},
		goToFullCase() {
			if (this.subCase) {
				this.$router.push({ name: 'CaseDetail', params: { id: this.subCase.id } })
			}
		},
	},
}
</script>

<style scoped>
.deelzaak-detail {
	display: flex;
	flex-direction: column;
	gap: 16px;
	padding: 16px;
}

.deelzaak-detail__breadcrumb {
	display: flex;
	gap: 4px;
	align-items: center;
	font-size: 0.9rem;
	color: var(--color-text-maxcontrast);
}

.deelzaak-detail__breadcrumb-link {
	display: inline-flex;
	gap: 4px;
	align-items: center;
	color: var(--color-primary-element);
	text-decoration: none;
}

.deelzaak-detail__breadcrumb-link:hover {
	text-decoration: underline;
}

.deelzaak-detail__breadcrumb-sep {
	color: var(--color-text-lighter);
}

.deelzaak-detail__breadcrumb-current {
	color: var(--color-main-text);
	font-weight: 500;
}

.deelzaak-detail__header {
	display: flex;
	justify-content: space-between;
	align-items: flex-start;
	gap: 16px;
	flex-wrap: wrap;
}

.deelzaak-detail__header h2 {
	margin: 0;
}

.deelzaak-detail__subtitle {
	margin: 4px 0 0;
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.deelzaak-detail__actions {
	display: flex;
	gap: 8px;
}

.deelzaak-detail__grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
	gap: 12px;
	margin: 0;
}

.deelzaak-detail__row {
	display: flex;
	flex-direction: column;
	gap: 4px;
	padding: 12px;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
}

.deelzaak-detail__row dt {
	font-size: 0.8rem;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	text-transform: uppercase;
	letter-spacing: 0.04em;
}

.deelzaak-detail__row dd {
	margin: 0;
	color: var(--color-main-text);
}

.deelzaak-detail__section {
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 16px;
}

.deelzaak-detail__section h3 {
	margin: 0 0 8px;
}

.status-badge {
	display: inline-block;
	padding: 2px 8px;
	border-radius: var(--border-radius-pill);
	font-size: 12px;
	font-weight: 500;
}

.status-badge--active {
	background: var(--color-primary-light);
	color: var(--color-primary-text);
}

.status-badge--final {
	background: var(--color-success);
	color: white;
}
</style>
