<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  CaseReportGroupWidget — the report group this case was placed in on creation
  (board DqZaakAssistent), beside the library `ai-features` widget in the
  Assistant tab.

  🔑 THE GROUP IS READ, NEVER PLACED. Opening the case reads the group the
  case was put in when it was created; it does not ask hermiq to place it
  again, which would count the same resident twice. The near-duplicates are
  the members hermiq was unsure about, listed so somebody looks at them.

  @spec openspec/changes/ai-features-on-the-case-consume-hermiq/specs/ai-features-on-the-case/spec.md#requirement-identical-reports-collapse-on-the-case-and-dossiq-decides-what-a-group-means-req-aic-04
-->
<template>
	<section
		v-if="!loading && group.state !== 'off'"
		class="case-report-group"
		data-testid="case-ai-group">
		<h4>{{ t('dossiq', 'Reports like this one') }}</h4>
		<p
			v-if="group.state === 'unavailable'"
			class="case-report-group__hint"
			data-testid="case-ai-group-unavailable">
			{{ group.reason }}
			<span v-if="group.gate" class="case-report-group__gate">
				{{ t('dossiq', 'Gate: {gate}', { gate: group.gate }) }}
			</span>
		</p>
		<template v-else>
			<p class="case-report-group__count" data-testid="case-ai-group-count">
				{{
					n(
						'dossiq',
						'{count} report in this group',
						'{count} reports in this group',
						group.count,
						{ count: group.count },
					)
				}}
			</p>
			<template v-if="group.nearDuplicates.length > 0">
				<p class="case-report-group__hint">
					{{
						t(
							'dossiq',
							'hermiq was not sure these belong to the group. Check whether they are the same report.',
						)
					}}
				</p>
				<ul class="case-report-group__near" data-testid="case-ai-group-near">
					<li
						v-for="member in group.nearDuplicates"
						:key="member.reportId"
						data-testid="case-ai-group-near-item">
						<router-link
							:to="{ name: 'CaseDetail', params: { id: member.reportId } }">
							{{ member.reportId }}
						</router-link>
						<span class="case-report-group__hint">
							{{
								t('dossiq', '{percent}% alike', {
									percent: Math.round(member.score * 100),
								})
							}}
						</span>
					</li>
				</ul>
			</template>
		</template>
	</section>
</template>

<script>
import { translatePlural as n, translate as t } from '@nextcloud/l10n'
import { fetchReportGroup, groupView } from '../../services/caseAiFeaturesApi.js'

export default {
	name: 'CaseReportGroupWidget',

	props: {
		/** The case uuid, injected by CnObjectSidebar. */
		objectId: {
			type: String,
			default: '',
		},
	},

	data() {
		return {
			loading: true,
			group: groupView({ declared: false }),
		}
	},

	/**
	 * Read the group once.
	 *
	 * @return {Promise<void>}
	 * @spec openspec/changes/ai-features-on-the-case-consume-hermiq/specs/ai-features-on-the-case/spec.md#scenario-the-near-duplicates-are-visible-beside-the-group
	 */
	async mounted() {
		if (this.objectId !== '') {
			this.group = groupView(await fetchReportGroup(this.objectId))
		}
		this.loading = false
	},

	methods: {
		t,
		n,
	},
}
</script>

<style scoped>
.case-report-group {
	margin: 0 12px 16px;
	padding-bottom: 12px;
	border-bottom: 1px solid var(--color-border);
}

.case-report-group__hint,
.case-report-group__gate {
	display: block;
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
}

.case-report-group__count {
	font-weight: bold;
}
</style>
