<template>
	<div class="public-status-page">
		<div v-if="loading" class="public-status-page__loading">
			<NcLoadingIcon :size="32" />
			<p>{{ t('dossiq', 'Loading status...') }}</p>
		</div>

		<div v-else-if="error" class="public-status-page__error">
			<h2>{{ t('dossiq', 'Status unavailable') }}</h2>
			<p>{{ error }}</p>
		</div>

		<div v-else-if="statusData" class="public-status-page__content">
			<header class="public-status-page__header">
				<h1>{{ statusData.title }}</h1>
				<p v-if="statusData.identifier" class="public-status-page__ref">
					{{
						t('dossiq', 'Reference: {ref}', {
							ref: statusData.identifier,
						})
					}}
				</p>
				<p
					v-if="mergedFrom"
					class="public-status-page__ref"
					data-testid="public-status-merged-from">
					{{
						t(
							'dossiq',
							'Your request {ref} is handled together with this one.',
							{ ref: mergedFrom },
						)
					}}
				</p>
			</header>

			<!-- Visual status indicator -->
			<section
				class="public-status-page__progress"
				role="progressbar"
				:aria-label="t('dossiq', 'Case progress')">
				<div class="public-status-page__status-label">
					{{ t('dossiq', 'Current status') }}
				</div>
				<div
					class="public-status-page__status-value"
					data-testid="public-status-value">
					{{ statusData.currentStatus || t('dossiq', 'In progress') }}
				</div>
				<p
					v-if="statusData.currentStatusDescription"
					class="public-status-page__status-description"
					data-testid="public-status-description">
					{{ statusData.currentStatusDescription }}
				</p>
			</section>

			<!-- Dates -->
			<section class="public-status-page__dates">
				<div
					v-if="statusData.startDate"
					class="public-status-page__date-item">
					<span class="public-status-page__date-label">{{
						t('dossiq', 'Submitted')
					}}</span>
					<span class="public-status-page__date-value">{{
						formatDate(statusData.startDate)
					}}</span>
				</div>
				<div
					v-if="statusData.plannedEndDate"
					class="public-status-page__date-item">
					<span class="public-status-page__date-label">{{
						t('dossiq', 'Expected completion')
					}}</span>
					<span class="public-status-page__date-value">{{
						formatDate(statusData.plannedEndDate)
					}}</span>
				</div>
			</section>

			<!-- What has happened on the case, as far as the applicant may see. -->
			<section
				v-if="timeline.length"
				class="public-status-page__timeline"
				data-testid="public-status-timeline">
				<h2 class="public-status-page__timeline-title">
					{{ t('dossiq', 'What has happened') }}
				</h2>
				<ol class="public-status-page__timeline-list">
					<li
						v-for="entry in timeline"
						:key="entry.id"
						class="public-status-page__timeline-entry"
						data-testid="public-status-timeline-entry">
						<span class="public-status-page__timeline-moment">{{
							formatDate(entry.occurredAt)
						}}</span>
						<span class="public-status-page__timeline-message">{{
							entry.message
						}}</span>
					</li>
				</ol>
			</section>

			<footer class="public-status-page__footer">
				<p>
					{{
						t(
							'dossiq',
							'For questions about your case, please contact the municipality.',
						)
					}}
				</p>
			</footer>
		</div>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcLoadingIcon } from '@nextcloud/vue'
import { descriptionOnCase, labelOnCase } from '../../utils/statusPublicLabel.js'

export default {
	name: 'PublicStatusPage',
	components: {
		NcLoadingIcon,
	},

	props: {
		token: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			loading: true,
			error: '',
			statusData: null,
			timeline: [],
			mergedFrom: '',
		}
	},

	mounted() {
		this.loadStatus()
	},

	methods: {
		// INHERITED, AND FIXED HERE BECAUSE THIS FILE GAINED ANOTHER CALLER.
		// Every string on this page goes through `t()`, and the template had
		// no `t` to go through: the import and the method were both missing,
		// so the page rendered untranslated at best. Its sibling
		// PublicAppointmentPage has had both since it was written.
		t,

		/**
		 * Resolve the public "track your case" token through OpenRegister's
		 * shares integration leaf (ADR-022). The OR `#[PublicPage]` endpoint
		 * `GET /apps/openregister/api/public/case-tokens/{token}` returns an
		 * RBAC-respecting, public-safe view of the case (only the fields the
		 * public group may read) — dossiq no longer runs its own public
		 * token-resolution controller. An unknown / revoked / expired token,
		 * or an RBAC-denied object, resolves to a uniform 404.
		 *
		 * @spec openspec/changes/migrate-public-share-to-shares-leaf/tasks.md#P2.1
		 */
		async loadStatus() {
			this.loading = true
			try {
				const response = await fetch(
					`/apps/openregister/api/public/case-tokens/${encodeURIComponent(this.token)}`,
				)
				if (!response.ok) {
					this.error = t('dossiq', 'Status unavailable')
					return
				}

				const data = await response.json()
				let obj = data.object || {}

				// 🔴 A MERGED CASE IS NOT THE CASE THE APPLICANT IS WAITING
				// ON. The token still resolves, and it resolves to a case
				// nobody works on any more: its term was completed at the
				// merge and its status stopped moving there. The survivor is
				// the one whose status is the answer, and only the server can
				// read it, because this page has no session.
				if (obj.mergedInto) {
					const survivor = await fetch(
						`/apps/dossiq/api/public/case-tokens/${encodeURIComponent(this.token)}/survivor`,
					)
					if (survivor.ok) {
						const merged = await survivor.json()
						obj = merged.object || obj
						this.mergedFrom = String(data.object?.identifier || '')
					}
				}
				// The entries the server already decided are public. There is
				// no client-side filter here on purpose: the projection has no
				// visibility field to filter on, because the decision is the
				// server's and a page with no session cannot be trusted to
				// make it. An empty or absent list is a case with nothing to
				// show, not an error.
				this.timeline = Array.isArray(data.timeline) ? data.timeline : []
				// Map the public-safe OR object view onto the citizen status fields.
				//
				// 🔴 THE STATUS THE APPLICANT READS IS ON THE CASE, NOT BEHIND
				// IT. `obj.status` is the statusType's uuid, because the
				// endpoint above renders the object with `_extend: []` and this
				// page has no session to fetch the statusType with. It was
				// being printed as the current status: a citizen who opened
				// this page read 32 hex characters. `statusPublicLabel` is the
				// case schema's own calculation over the linked statusType, so
				// the words arrive with the case and the fallback to the status
				// name has already been resolved server-side.
				this.statusData = {
					title: obj.title || data.label || '',
					identifier: obj.identifier || '',
					currentStatus: labelOnCase(obj),
					currentStatusDescription: descriptionOnCase(obj),
					plannedEndDate: obj.plannedEndDate || null,
					startDate: obj.startDate || null,
				}
			} catch (err) {
				this.error = t('dossiq', 'Could not load status')
			} finally {
				this.loading = false
			}
		},

		/**
		 * @param {string} dateString The date, as an ISO 8601 string.
		 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
		 */
		formatDate(dateString) {
			if (!dateString) return ''
			return new Date(dateString).toLocaleDateString('nl-NL', {
				year: 'numeric',
				month: 'long',
				day: 'numeric',
			})
		},
	},
}
</script>

<style scoped>
.public-status-page {
	max-width: 600px;
	margin: 0 auto;
	padding: 32px 24px;
	font-family: var(--font-face), sans-serif;
}

.public-status-page__loading {
	text-align: center;
	padding: 48px;
}

.public-status-page__error {
	text-align: center;
	padding: 48px;
	color: var(--color-error);
}

.public-status-page__header {
	margin-bottom: 32px;
	text-align: center;
}

.public-status-page__header h1 {
	margin: 0 0 8px;
	font-size: 24px;
}

.public-status-page__ref {
	color: var(--color-text-maxcontrast);
	font-size: 14px;
}

.public-status-page__progress {
	text-align: center;
	padding: 24px;
	margin-bottom: 24px;
	border: 2px solid var(--color-primary-element);
	border-radius: var(--border-radius-large);
	background: var(--color-primary-element-light);
}

.public-status-page__status-label {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
	margin-bottom: 4px;
}

.public-status-page__status-value {
	font-size: 20px;
	font-weight: bold;
	color: var(--color-primary-element);
}

.public-status-page__status-description {
	margin: 8px 0 0;
	font-size: 14px;
	color: var(--color-main-text);
}

.public-status-page__dates {
	display: flex;
	gap: 24px;
	justify-content: center;
	margin-bottom: 32px;
}

.public-status-page__date-item {
	text-align: center;
}

.public-status-page__date-label {
	display: block;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.public-status-page__date-value {
	font-weight: bold;
}

.public-status-page__timeline {
	margin-bottom: 32px;
}

.public-status-page__timeline-title {
	font-size: 16px;
	margin: 0 0 12px;
}

.public-status-page__timeline-list {
	list-style: none;
	margin: 0;
	padding: 0;
	border-inline-start: 2px solid var(--color-border);
}

.public-status-page__timeline-entry {
	padding: 8px 0 8px 16px;
}

.public-status-page__timeline-moment {
	display: block;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.public-status-page__timeline-message {
	display: block;
}

.public-status-page__footer {
	text-align: center;
	padding-top: 24px;
	border-top: 1px solid var(--color-border);
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}
</style>
