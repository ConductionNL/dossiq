<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  CaseAccessTab — who holds which right on this case, and where each grant
  came from.

  🔴 IT RENDERS OPENREGISTER'S ANSWER AND COMPUTES NONE OF IT (D-1, D-5).

  "A gemeente must be able to prove after the fact who could open a dossier"
  is the clause behind this panel. The answer to it lives in OpenRegister: the
  grant, its provenance, the deny and the inheritance. This component fetches
  OpenRegister's reads through src/services/caseAccessApi.js and lists what came
  back, one row per rule, each with the holder OpenRegister named and the source
  it reported.

  Since openregister#3744 the case answers for itself, so the first read is the
  object's own permission set and the older five are the fallback for an
  instance that answers 404 to it (D-6). The second half of the auditor's
  question, "who could open this in March", is the history read beside it: a
  date the reader picks, and the holders of that date with who set them.

  A grant may carry an end and an area (openregister#3750). Both are rendered
  and neither is compared to anything here: whether an expired grant still
  answers is resolved in OpenRegister, on every path a question takes (D-8).

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
			<p v-if="reviewRefused" class="case-access-tab__unreadable">
				{{ t('dossiq', 'You may open this case. You may not review who else can.') }}
			</p>

			<p v-else-if="unreadable.length > 0" class="case-access-tab__unreadable">
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
							<span v-if="row.declared === false" class="case-access-tab__hint">
								{{ t('dossiq', 'OpenRegister does not publish this right.') }}
							</span>
						</td>
						<td>
							<span class="case-access-tab__source">{{ sourceLabel(row.source) }}</span>
							<span v-if="roleHint(row)" class="case-access-tab__hint">{{ roleHint(row) }}</span>
							<span v-if="row.until" class="case-access-tab__hint">{{ endsHint(row) }}</span>
							<span v-if="areaHint(row)" class="case-access-tab__hint">{{ areaHint(row) }}</span>
						</td>
					</tr>
				</tbody>
			</table>

			<section v-if="historyReadable" class="case-access-tab__history">
				<h4>{{ t('dossiq', 'Who held a right on a date') }}</h4>

				<label class="case-access-tab__moment">
					<span>{{ t('dossiq', 'Report the rights as they stood on') }}</span>
					<input
						v-model="moment"
						type="date"
						:max="today"
						@change="loadMoment">
				</label>

				<p v-if="momentLoading" class="case-access-tab__empty">
					{{ t('dossiq', 'Reading the trail for that date') }}
				</p>

				<template v-else-if="asOf">
					<p v-if="asOf.answered === false" class="case-access-tab__empty">
						{{
							t(
								'dossiq',
								'The trail does not reach {date}, so nobody can be reported for it.',
								{ date: moment },
							)
						}}
					</p>

					<template v-else>
						<p class="case-access-tab__enforcement">
							{{ setBySentence }}
						</p>
						<p v-if="asOf.changedAfterwardsBy" class="case-access-tab__enforcement">
							{{ changedAfterwardsSentence }}
						</p>
						<table class="case-access-tab__table">
							<thead>
								<tr>
									<th scope="col">{{ t('dossiq', 'Holder') }}</th>
									<th scope="col">{{ t('dossiq', 'Right') }}</th>
									<th scope="col">{{ t('dossiq', 'Where it came from') }}</th>
								</tr>
							</thead>
							<tbody>
								<tr
									v-for="(row, index) in asOf.rows"
									:key="`asof-${row.holder}-${row.right}-${index}`"
									class="case-access-tab__row">
									<td>{{ row.holder || t('dossiq', 'Not named') }}</td>
									<td>{{ row.right }}</td>
									<td>
										<span class="case-access-tab__source">{{ sourceLabel(row.source) }}</span>
										<span v-if="roleHint(row)" class="case-access-tab__hint">{{ roleHint(row) }}</span>
									</td>
								</tr>
							</tbody>
						</table>
					</template>
				</template>
			</section>
		</template>
	</div>
</template>

<script>
import { NcLoadingIcon } from '@nextcloud/vue'
import {
	asOfRows,
	fetchAccessHistory,
	fetchCallerScope,
	fetchDenyRules,
	fetchObjectGrants,
	fetchObjectPermissions,
	fetchPermissionCatalogue,
	fetchRoleGrants,
	grantRows,
} from '../../../services/caseAccessApi.js'

/** OpenRegister's answer to a reader who may open the case and not review it. */
const REVIEW_REFUSED = 403

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
			reviewRefused: false,
			historyReadable: false,
			moment: '',
			momentLoading: false,
			asOf: null,
		}
	},

	computed: {
		/**
		 * Today, so nobody asks the trail about a date that has not happened.
		 *
		 * @return {string} Today as `YYYY-MM-DD`.
		 * @spec openspec/changes/case-grants-name-their-source/specs/case-management/spec.md
		 */
		today() {
			return new Date().toISOString().slice(0, 10)
		},

		/**
		 * Who wrote the rules that stood at the moment asked about.
		 *
		 * @return {string} One sentence.
		 * @spec openspec/changes/case-grants-name-their-source/specs/case-management/spec.md
		 */
		setBySentence() {
			if (!this.asOf?.setBy) {
				return t('dossiq', 'These rights stood on that date. Nobody is named as setting them.')
			}
			return t('dossiq', '{who} set these rights.', { who: this.asOf.setBy })
		},

		/**
		 * What changed the rights after the moment asked about.
		 *
		 * @return {string} One sentence.
		 * @spec openspec/changes/case-grants-name-their-source/specs/case-management/spec.md
		 */
		changedAfterwardsSentence() {
			const change = this.asOf?.changedAfterwardsBy
			if (!change) {
				return ''
			}
			return t('dossiq', '{who} changed them afterwards, on {date}.', {
				who: change.by || t('dossiq', 'Somebody'),
				date: change.at || t('dossiq', 'a date the trail does not give'),
			})
		},

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
			const permissions = this.objectId
				? await fetchObjectPermissions(this.objectId)
				: { status: 0, set: null }

			// A 403 is OpenRegister answering, and the answer is "not you". The
			// fallback reads are skipped: they would assemble a partial list
			// under a sentence saying this reader may not see one (D-7).
			this.reviewRefused = (permissions.status === REVIEW_REFUSED)
			this.historyReadable = (permissions.set !== null)

			const [catalogue, objectGrants, roleGrants, callerScope, denyRules] =
				await Promise.all([
					fetchPermissionCatalogue(),
					this.fallbackGrants(permissions),
					permissions.set === null ? fetchRoleGrants() : null,
					fetchCallerScope(),
					permissions.set === null ? fetchDenyRules() : null,
				])

			// Each failed read is NAMED. An auditor reading a short list has to
			// know which source is missing from it.
			const missing = []
			if (catalogue === null) missing.push(t('dossiq', 'the rights it publishes'))
			if (permissions.set === null) {
				if (objectGrants === null) missing.push(t('dossiq', 'the shares on this case'))
				if (roleGrants === null) missing.push(t('dossiq', 'the roles'))
				if (denyRules === null) missing.push(t('dossiq', 'the refusals'))
			}
			if (callerScope === null) missing.push(t('dossiq', 'your own rights'))

			this.catalogue = catalogue?.permissions || []
			this.enforcement = permissions.set?.denyEnforcement
				|| catalogue?.denyEnforcement
				|| denyRules?.denyEnforcement
				|| ''
			this.unreadable = this.reviewRefused ? [] : missing
			this.rows = grantRows({
				objectPermissions: permissions.set,
				objectGrants,
				roleGrants,
				callerScope,
				denyRules,
			})
			this.loading = false
		},

		/**
		 * The share read, asked only when the object could not answer for itself.
		 *
		 * @param {{status: number, set: object|null}} permissions What the object answered.
		 *
		 * @return {Promise<Array|null>} The grants, or null.
		 * @spec openspec/changes/case-grants-name-their-source/specs/case-management/spec.md
		 */
		fallbackGrants(permissions) {
			if (permissions.set !== null || this.objectId === '') {
				return Promise.resolve(null)
			}
			return fetchObjectGrants(this.objectId)
		},

		/**
		 * Ask the trail who held a right on the date the reader picked.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/case-grants-name-their-source/specs/case-management/spec.md
		 */
		async loadMoment() {
			if (this.moment === '' || this.objectId === '') {
				this.asOf = null
				return
			}

			this.momentLoading = true
			// End of that day, so a grant written during it is reported as
			// standing on it. Asking about midnight answers about the day before,
			// which is the answer nobody meant to ask for.
			const { history } = await fetchAccessHistory(this.objectId, `${this.moment}T23:59:59`)
			this.asOf = asOfRows(history)
			this.momentLoading = false
		},

		/**
		 * The role a grant arrived through, when a role is what granted it.
		 *
		 * @param {object} row The row.
		 *
		 * @return {string} The sentence, empty when no role is named.
		 * @spec openspec/changes/case-grants-name-their-source/specs/case-management/spec.md
		 */
		roleHint(row) {
			if (!row?.role) {
				return (row?.detail || '')
			}
			return t('dossiq', 'Through the role {role}', { role: row.role })
		},

		/**
		 * When a grant stops answering, as OpenRegister wrote it.
		 *
		 * 🔑 THE DATE IS PRINTED, NOT JUDGED. Saying "expired" here would need a
		 * clock, and the clock that decides lives in OpenRegister (D-8).
		 *
		 * @param {object} row The row.
		 *
		 * @return {string} The sentence.
		 * @spec openspec/changes/case-grants-name-their-source/specs/case-management/spec.md
		 */
		endsHint(row) {
			return t('dossiq', 'Written to end on {date}', { date: row.until })
		},

		/**
		 * The registers and schemas a grant is confined to.
		 *
		 * @param {object} row The row.
		 *
		 * @return {string} The sentence, empty when the grant names no area.
		 * @spec openspec/changes/case-grants-name-their-source/specs/case-management/spec.md
		 */
		areaHint(row) {
			const named = [
				...(row?.scopedTo?.registers || []),
				...(row?.scopedTo?.schemas || []),
			].filter((name) => typeof name === 'string' && name !== '')

			if (named.length === 0) {
				return ''
			}

			return t('dossiq', 'Only in {areas}', { areas: named.join(', ') })
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

.case-access-tab__history {
	margin-block-start: 24px;
	border-top: 1px solid var(--color-border);
	padding-block-start: 12px;
}

.case-access-tab__moment {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px;
	margin-block-end: 8px;
}
</style>
