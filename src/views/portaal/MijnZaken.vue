<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  -
  - "Mijn zaken" overview + detail for the citizen portal (zaakportaal-mijngemeente).
  - Lists the authenticated citizen's cases and, on selection, shows the status
  - timeline, possible actions and the message thread. All data is read fresh
  - from the IDOR-safe /api/portaal endpoints; nothing is cached client-side.
-->
<template>
	<div class="zp-mijnzaken">
		<a class="zp-skip-link" href="#zp-main">{{ t('procest', 'Skip to main content') }}</a>

		<h1>{{ t('procest', 'My cases') }}</h1>

		<main id="zp-main">
			<div v-if="loading" class="zp-state">
				<NcLoadingIcon :size="32" />
				<p>{{ t('procest', 'Loading your cases...') }}</p>
			</div>

			<div v-else-if="error" class="zp-state zp-state--error" role="alert">
				<p>{{ error }}</p>
			</div>

			<div v-else-if="!selectedCase">
				<p v-if="cases.length === 0" class="zp-empty">
					{{ t('procest', 'You currently have no active cases.') }}
				</p>

				<table v-else class="zp-cases-table">
					<thead>
						<tr>
							<th scope="col">
								{{ t('procest', 'Reference') }}
							</th>
							<th scope="col">
								{{ t('procest', 'Type') }}
							</th>
							<th scope="col">
								{{ t('procest', 'Subject') }}
							</th>
							<th scope="col">
								{{ t('procest', 'Status') }}
							</th>
							<th scope="col">
								{{ t('procest', 'Submitted') }}
							</th>
							<th scope="col">
								{{ t('procest', 'Deadline') }}
							</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="zaak in cases"
							:key="zaak.zaakId"
							tabindex="0"
							class="zp-cases-row"
							@click="openCase(zaak.zaakId)"
							@keydown.enter="openCase(zaak.zaakId)">
							<td>{{ zaak.zaakKenmerk }}</td>
							<td>{{ zaak.zaaktype }}</td>
							<td>{{ zaak.onderwerp }}</td>
							<td>{{ zaak.status }}</td>
							<td>{{ zaak.ingediendOp }}</td>
							<td>{{ (zaak.termijnen && zaak.termijnen.afhandelTermijnEinde) || '—' }}</td>
						</tr>
					</tbody>
				</table>
			</div>

			<div v-else class="zp-detail">
				<NcButton type="tertiary" @click="closeCase">
					{{ t('procest', 'Back to my cases') }}
				</NcButton>

				<h2>{{ selectedCase.onderwerp }}</h2>
				<p class="zp-detail__ref">
					{{ t('procest', 'Reference: {ref}', { ref: selectedCase.zaakKenmerk }) }}
				</p>

				<StatusTimeline
					:steps="selectedCase.tijdlijn || []"
					:deadline="(selectedCase.termijnen && selectedCase.termijnen.afhandelTermijnEinde) || ''"
					:days-remaining="(selectedCase.termijnen && selectedCase.termijnen.dagenResterend) || 0"
					:exceeded="!!(selectedCase.termijnen && selectedCase.termijnen.termijnOverschreden)" />

				<section v-if="selectedCase.mogelijkeActies && selectedCase.mogelijkeActies.length" class="zp-actions">
					<h3>{{ t('procest', 'Available actions') }}</h3>
					<ul>
						<li v-for="actie in selectedCase.mogelijkeActies" :key="actie">
							{{ actieLabel(actie) }}
						</li>
					</ul>
				</section>
			</div>
		</main>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import StatusTimeline from './components/StatusTimeline.vue'

export default {
	name: 'MijnZaken',
	components: {
		NcButton,
		NcLoadingIcon,
		StatusTimeline,
	},
	data() {
		return {
			loading: true,
			error: '',
			cases: [],
			selectedCase: null,
		}
	},
	async mounted() {
		await this.loadCases()
	},
	methods: {
		async loadCases() {
			this.loading = true
			this.error = ''
			try {
				const { data } = await axios.get(generateUrl('/apps/procest/api/portaal/cases'))
				this.cases = (data && data.results) || []
			} catch (e) {
				this.error = this.t('procest', 'Could not load your cases. Please try again later.')
			} finally {
				this.loading = false
			}
		},
		async openCase(id) {
			this.loading = true
			this.error = ''
			try {
				const { data } = await axios.get(generateUrl('/apps/procest/api/portaal/cases/' + encodeURIComponent(id)))
				this.selectedCase = data
			} catch (e) {
				this.error = this.t('procest', 'Could not open this case.')
			} finally {
				this.loading = false
			}
		},
		closeCase() {
			this.selectedCase = null
		},
		actieLabel(actie) {
			const labels = {
				'bericht-sturen': this.t('procest', 'Send a message'),
				'bezwaar-indienen': this.t('procest', 'File an objection'),
				'klacht-indienen': this.t('procest', 'File a complaint'),
			}
			return labels[actie] || actie
		},
	},
}
</script>

<style scoped>
.zp-mijnzaken {
	padding: 24px;
	max-width: 980px;
	margin: 0 auto;
}

.zp-skip-link {
	position: absolute;
	left: -9999px;
}

.zp-skip-link:focus {
	position: static;
	display: inline-block;
	margin-bottom: 8px;
}

.zp-cases-table {
	width: 100%;
	border-collapse: collapse;
}

.zp-cases-table th,
.zp-cases-table td {
	text-align: left;
	padding: 8px;
	border-bottom: 1px solid var(--color-border, #d0d0d0);
}

.zp-cases-row {
	cursor: pointer;
}

.zp-cases-row:hover,
.zp-cases-row:focus {
	background: var(--color-background-hover, #f5f5f5);
	outline: 2px solid var(--color-primary-element, #21468B);
}

.zp-state {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 8px;
	padding: 32px;
}

.zp-state--error {
	color: var(--color-error, #c4341f);
}

.zp-empty {
	padding: 24px;
	color: var(--color-text-maxcontrast, #6b6b6b);
}

.zp-detail__ref {
	color: var(--color-text-maxcontrast, #6b6b6b);
}
</style>
