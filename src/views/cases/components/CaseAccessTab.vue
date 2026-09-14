<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  CaseAccessTab — who holds which right on this case, and where each grant
  came from.

  🔴 IT RENDERS OPENREGISTER'S ANSWER AND COMPUTES NONE OF IT (D-1, D-5).

  "A gemeente must be able to prove after the fact who could open a dossier"
  is the clause behind this panel. The answer to it lives in OpenRegister: the
  grant, its provenance, the deny and the inheritance. This component fetches
  five of OpenRegister's reads through src/services/caseAccessApi.js and lists
  what came back, one row per rule, each with the holder OpenRegister named and
  the source it reported.

  It never combines two rules into one row. A deny is its own row, listed
  beside the grant it will remove rather than subtracted from it, because the
  moment this panel decided that a deny beats a grant it would be a second
  evaluator of the same question, and a second evaluator eventually disagrees
  with the first. That disagreement, on this particular question, is a
  disclosure.

  A read that fails says so. It does not fall back to an empty list: "we could
  not ask" and "nobody holds this" are opposite answers to an auditor, and they
  would otherwise be the same empty table.

  Registered in src/registry.js as `CaseAccessTab` and wired as a `component:`
  sidebar tab on CaseDetail in src/manifest.json.

  @spec openspec/changes/case-grants-name-their-source/specs/case-management/spec.md
-->
<template>
	<div class="case-access-tab">
		<h3>{{ t('dossiq', 'Who holds which right') }}</h3>

		<div v-if="loading" class="case-access-tab__loading">
			<NcLoadingIcon :size="20" />
			{{ t('dossiq', 'Reading the grants on this case') }}
		</div>

		<template v-else>
			<p v-if="unreadable.length > 0" class="case-access-tab__unreadable">
				{{
					t(
						'dossiq',
						'OpenRegister could not be asked for {sources}. This list is incomplete, not empty.',
						{ sources: unreadable.join(', ') },
					)
				}}
			</p>

			<p v-if="enforcement" class="case-access-tab__enforcement">
				{{ enforcementSentence }}
			</p>

			<p v-if="rows.length === 0" class="case-access-tab__empty">
				{{ t('dossiq', 'OpenRegister reports no rule on this case.') }}
			</p>

			<table v-else class="case-access-tab__table">
				<thead>
					<tr>
						<th scope="col">{{ t('dossiq', 'Holder') }}</th>
						<th scope="col">{{ t('dossiq', 'Right') }}</th>
						<th scope="col">{{ t('dossiq', 'Where it comes from') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr
						v-for="(row, index) in rows"
						:key="`${row.holder}-${row.right}-${row.source}-${index}`"
						class="case-access-tab__row">
						<td>{{ row.holder || t('dossiq', 'Not named') }}</td>
						<td>
							<span class="case-access-tab__right">{{ row.right }}</span>
							<span v-if="describe(row.right)" class="case-access-tab__hint">
								{{ describe(row.right) }}
							</span>
						</td>
						<td>
							<span class="case-access-tab__source">{{ sourceLabel(row.source) }}</span>
							<span v-if="row.detail" class="case-access-tab__hint">{{ row.detail }}</span>
						</td>
					</tr>
				</tbody>
			</table>
		</template>
	</div>
</template>

<script>
import { NcLoadingIcon } from '@nextcloud/vue'
import {
	fetchCallerScope,
	fetchDenyRules,
	fetchObjectGrants,
	fetchPermissionCatalogue,
	fetchRoleGrants,
	grantRows,
} from '../../../services/caseAccessApi.js'

export default {
	name: 'CaseAccessTab',
	components: {
		NcLoadingIcon,
	},

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
			rows: [],
			catalogue: [],
			enforcement: '',
			unreadable: [],
		}
	},

	computed: {
		/**
		 * What the deny switch means for the rows below.
		 *
		 * @return {string} One sentence, empty when the mode is unknown.
		 * @spec openspec/changes/case-grants-name-their-source/specs/case-management/spec.md
		 */
		enforcementSentence() {
			if (this.enforcement === 'enforcing') {
				return t('dossiq', 'A refusal below is in force now.')
			}
			if (this.enforcement === 'off') {
				return t('dossiq', 'Refusals are switched off, so no rule below removes a right.')
			}
			return t(
				'dossiq',
				'Refusals are staged: a rule below is recorded, and removes a right once it is switched on.',
			)
		},
	},

	async mounted() {
		await this.load()
	},

	methods: {
		/**
		 * Ask OpenRegister, once, and render what came back.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/case-grants-name-their-source/specs/case-management/spec.md
		 */
		async load() {
			this.loading = true
			const [catalogue, objectGrants, roleGrants, callerScope, denyRules] =
				await Promise.all([
					fetchPermissionCatalogue(),
					this.objectId ? fetchObjectGrants(this.objectId) : null,
					fetchRoleGrants(),
					fetchCallerScope(),
					fetchDenyRules(),
				])

			// Each failed read is NAMED. An auditor reading a short list has to
			// know which source is missing from it.
			const missing = []
			if (catalogue === null) missing.push(t('dossiq', 'the rights it publishes'))
			if (objectGrants === null) missing.push(t('dossiq', 'the shares on this case'))
			if (roleGrants === null) missing.push(t('dossiq', 'the roles'))
			if (callerScope === null) missing.push(t('dossiq', 'your own rights'))
			if (denyRules === null) missing.push(t('dossiq', 'the refusals'))

			this.catalogue = catalogue?.permissions || []
			this.enforcement = catalogue?.denyEnforcement || denyRules?.denyEnforcement || ''
			this.unreadable = missing
			this.rows = grantRows({ objectGrants, roleGrants, callerScope, denyRules })
			this.loading = false
		},

		/**
		 * The sentence OpenRegister publishes for one verb.
		 *
		 * @param {string} right The verb.
		 *
		 * @return {string} The description, empty when the catalogue has none.
		 * @spec openspec/changes/case-grants-name-their-source/specs/case-management/spec.md
		 */
		describe(right) {
			const entry = this.catalogue.find((permission) => permission?.action === right)
			return entry?.description || ''
		},

		/**
		 * The label for one source, in the vocabulary OpenRegister reports.
		 *
		 * @param {string} source The source key.
		 *
		 * @return {string} The label, the raw key when it is one we do not know.
		 * @spec openspec/changes/case-grants-name-their-source/specs/case-management/spec.md
		 */
		sourceLabel(source) {
			const labels = {
				share: t('dossiq', 'A share on this case'),
				role: t('dossiq', 'A role'),
				group: t('dossiq', 'A grant on a group of case types'),
				object: t('dossiq', 'A rule on this case'),
				schema: t('dossiq', 'A rule on every case'),
				register: t('dossiq', 'A rule on the register'),
				deny: t('dossiq', 'A rule that refuses'),
				'staged-deny': t('dossiq', 'A rule that will refuse'),
				'default-open': t('dossiq', 'No rule, so it is open'),
				none: t('dossiq', 'No rule grants it'),
			}
			return labels[source] || source
		},
	},
}
</script>

<style scoped>
.case-access-tab {
	padding: 12px;
}

.case-access-tab__table {
	width: 100%;
	border-collapse: collapse;
}

.case-access-tab__table th,
.case-access-tab__table td {
	text-align: start;
	padding: 4px 8px;
	border-bottom: 1px solid var(--color-border);
	vertical-align: top;
}

.case-access-tab__right,
.case-access-tab__source {
	display: block;
}

.case-access-tab__hint {
	display: block;
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
}

.case-access-tab__unreadable {
	color: var(--color-warning-text, var(--color-text-maxcontrast));
}

.case-access-tab__enforcement,
.case-access-tab__empty {
	color: var(--color-text-maxcontrast);
}
</style>
