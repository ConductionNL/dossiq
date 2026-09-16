<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<!--
	Who is on this case, and in which role.

	The Parties section beside this one renders the person links: a Nextcloud
	user or a vCard contact, grouped by the role type this instance declares.
	This one renders the PARTY model OpenRegister shipped in #3761, which is
	the half a case needs and a contact list cannot carry: a melder with no
	account, a gemachtigde acting for the applicant, an organisation with a
	parent, and the indicators any of them carry.

	🔴 THE PRIMARY PARTY COMES FIRST, AND IT IS NOT A SORT. `primary` is the
	link the case is filed against, which on a dossiq case is the initiator. A
	handler opening this tab is looking for that name before any other, so the
	role holding it is rendered first and the party itself is first inside it.
	Leaving it to fall wherever the grouping put it is how the applicant ends
	up under the neighbour who filed a zienswijze.

	🔴 AN INDICATOR IS RENDERED WITH ITS VERDICT, NEVER AS A BARE CHIP. An
	indicator that only renders is an indicator somebody misses. Each one says
	what it does: warn the reader, refuse publishing a file on the case, or
	refuse a message to that party. The refusals are ALSO enforced where the
	act happens — the publication in `BesluitPublicatiePanel`, the send in
	`FileRequestService`, and both again inside OpenRegister — because a
	verdict drawn in a widget is a verdict a second caller does not have.

	WHY A FAILED READ SAYS SO. A case with nobody on it is a real answer and
	this says it in words. "OpenRegister could not be asked" is a different
	answer, and drawing it as an empty list would tell a handler the
	gemachtigde they added this morning is gone.

	@spec openspec/changes/gemachtigde-role-on-every-case-type/specs/roles-decisions/spec.md
-->
<template>
	<div class="case-parties" data-testid="case-parties">
		<NcLoadingIcon v-if="loading" :size="24" />

		<NcEmptyContent
			v-else-if="failed"
			:name="t('dossiq', 'The parties of this case could not be read')"
			:description="
				t(
					'dossiq',
					'OpenRegister did not answer. Nothing has been removed from the case.',
				)
			" />

		<NcEmptyContent
			v-else-if="groups.length === 0"
			:name="t('dossiq', 'No parties on this case yet')"
			:description="
				t(
					'dossiq',
					'Add a party to record who the case is for and who acts on it.',
				)
			" />

		<template v-else>
			<div
				v-if="verdicts.length > 0"
				class="case-parties__verdicts"
				data-testid="case-parties-verdicts">
				<NcNoteCard
					v-for="verdict in verdicts"
					:key="`${verdict.party}-${verdict.key}`"
					:type="verdict.severity"
					:data-effect="verdict.effect"
					data-testid="case-parties-verdict">
					<strong>{{ verdict.label }}</strong>
					<span>{{ verdict.sentence }}</span>
				</NcNoteCard>
			</div>

			<section
				v-for="group in groups"
				:key="group.key"
				class="case-parties__role"
				:data-role="group.key"
				data-testid="case-parties-role">
				<h4 class="case-parties__role-label">
					{{ group.label }}
				</h4>
				<ul class="case-parties__list">
					<li
						v-for="party in group.parties"
						:key="`${group.key}-${party.partyUuid || party.contactUid}`"
						class="case-parties__party"
						:data-party="party.partyUuid || party.contactUid"
						data-testid="case-parties-party">
						<span class="case-parties__party-name">
							{{ nameOf(party) }}
						</span>
						<span
							v-if="party.partyUuid === primary"
							class="case-parties__primary"
							data-testid="case-parties-primary">
							{{ t('dossiq', 'Primary party') }}
						</span>
						<span v-if="kindOf(party)" class="case-parties__party-kind">
							{{ kindOf(party) }}
						</span>
						<span v-if="party.email" class="case-parties__party-email">
							{{ party.email }}
						</span>
						<span
							v-for="indicator in indicatorsFor(party)"
							:key="`${party.partyUuid}-${indicator.key}`"
							class="case-parties__indicator"
							:class="`case-parties__indicator--${indicator.severity}`"
							:data-effect="indicator.effect"
							data-testid="case-parties-indicator"
							:title="indicator.verdict">
							{{ indicator.label }} · {{ indicator.verdict }}
						</span>
					</li>
				</ul>
			</section>
		</template>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import {
	fetchCaseParties,
	fetchParty,
	indicatorsOf,
	indicatorVerdict,
	rolesInOrder,
} from '../../services/caseParties.js'

export default {
	name: 'CasePartiesWidget',

	components: { NcEmptyContent, NcLoadingIcon, NcNoteCard },

	props: {
		/** The case this widget belongs to, bound by CnDetailWidgetHost. */
		objectId: {
			type: [String, Number],
			default: '',
		},
	},

	data() {
		return {
			loading: true,
			failed: false,
			listing: null,
			/** The party records, keyed by uuid, for their indicators. */
			partyRecords: {},
		}
	},

	computed: {
		/**
		 * The case this widget is about.
		 *
		 * @return {string} The case uuid, or the empty string.
		 * @spec openspec/changes/gemachtigde-role-on-every-case-type/specs/roles-decisions/spec.md
		 */
		caseId() {
			return String(this.objectId || this.$route?.params?.id || '')
		},

		/**
		 * The roles on the case, the primary party's role first.
		 *
		 * @return {Array<object>} `{key, label, parties}` per role.
		 * @spec openspec/changes/gemachtigde-role-on-every-case-type/specs/roles-decisions/spec.md
		 */
		groups() {
			return rolesInOrder(this.listing)
		},

		/**
		 * The uuid of the party the case is filed against.
		 *
		 * @return {string|null} The primary party's uuid.
		 * @spec openspec/changes/gemachtigde-role-on-every-case-type/specs/roles-decisions/spec.md
		 */
		primary() {
			return this.listing?.primary || null
		},

		/**
		 * The kinds the case schema accepts, keyed for labelling a party.
		 *
		 * @return {object} Kind key to label.
		 * @spec openspec/changes/gemachtigde-role-on-every-case-type/specs/roles-decisions/spec.md
		 */
		kindLabels() {
			const labels = {}
			for (const kind of this.listing?.kinds || []) {
				if (kind && kind.key) {
					labels[kind.key] = kind.label || kind.key
				}
			}
			return labels
		},

		/**
		 * Every indicator on the case, with what it refuses, for the notice
		 * above the list. Only the ones that block an act are raised here; a
		 * plain warning is shown beside its own party and not repeated.
		 *
		 * @return {Array<object>} The blocking verdicts.
		 * @spec openspec/changes/gemachtigde-role-on-every-case-type/specs/roles-decisions/spec.md
		 */
		verdicts() {
			return indicatorsOf(Object.values(this.partyRecords))
				.map((indicator) => ({
					...indicatorVerdict(indicator),
					party: indicator.party,
					key: indicator.key,
					sentence: this.sentenceFor(indicator),
				}))
				.filter((verdict) => verdict.severity === 'error')
		},
	},

	watch: {
		caseId: {
			immediate: true,
			/**
			 * Read the parties of the case this widget was given.
			 *
			 * @return {void}
			 * @spec openspec/changes/gemachtigde-role-on-every-case-type/specs/roles-decisions/spec.md
			 */
			handler() {
				this.load()
			},
		},
	},

	methods: {
		t,

		/**
		 * Read the listing, then each party's own record for its indicators.
		 *
		 * The second read is per party that HAS a uuid, so a case carrying
		 * only the user and contact links written before the party model
		 * makes no extra call at all.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/gemachtigde-role-on-every-case-type/specs/roles-decisions/spec.md
		 */
		async load() {
			if (this.caseId === '') {
				this.loading = false
				return
			}

			this.loading = true
			this.failed = false
			this.partyRecords = {}

			const listing = await fetchCaseParties(this.caseId)
			if (listing === null) {
				this.failed = true
				this.listing = null
				this.loading = false
				return
			}

			this.listing = listing
			const uuids = [
				...new Set(
					(listing.results || [])
						.map((row) => row && row.partyUuid)
						.filter(Boolean),
				),
			]
			const records = await Promise.all(uuids.map((uuid) => fetchParty(uuid)))
			const byUuid = {}
			uuids.forEach((uuid, index) => {
				if (records[index]) {
					byUuid[uuid] = records[index]
				}
			})
			this.partyRecords = byUuid
			this.loading = false
		},

		/**
		 * What to call a party on screen.
		 *
		 * @param {object} party The link row.
		 * @return {string} The name, its uid when it has none.
		 * @spec openspec/changes/gemachtigde-role-on-every-case-type/specs/roles-decisions/spec.md
		 */
		nameOf(party) {
			return (
				party.displayName
				|| this.partyRecords[party.partyUuid]?.name
				|| party.contactUid
				|| party.partyUuid
				|| ''
			)
		},

		/**
		 * What kind of party this is, in the schema's own words.
		 *
		 * @param {object} party The link row.
		 * @return {string} The kind's label, '' when the link names no kind.
		 * @spec openspec/changes/gemachtigde-role-on-every-case-type/specs/roles-decisions/spec.md
		 */
		kindOf(party) {
			if (!party.partyKind) {
				return ''
			}
			return this.kindLabels[party.partyKind] || party.partyKind
		},

		/**
		 * The indicators one party carries, each with its verdict.
		 *
		 * @param {object} party The link row.
		 * @return {Array<object>} The verdicts.
		 * @spec openspec/changes/gemachtigde-role-on-every-case-type/specs/roles-decisions/spec.md
		 */
		indicatorsFor(party) {
			const record = this.partyRecords[party.partyUuid]
			if (!record) {
				return []
			}
			return (record.indicators || []).filter(Boolean).map((indicator) => ({
				...indicatorVerdict(indicator),
				key: indicator.key,
			}))
		},

		/**
		 * The sentence a blocking indicator gets in the notice above the list.
		 *
		 * @param {object} indicator The indicator, stamped with its party.
		 * @return {string} What it refuses, and for whom.
		 * @spec openspec/changes/gemachtigde-role-on-every-case-type/specs/roles-decisions/spec.md
		 */
		sentenceFor(indicator) {
			const verdict = indicatorVerdict(indicator)
			const name = indicator.partyName || indicator.party
			if (verdict.effect === 'refuse-publication') {
				return t(
					'dossiq',
					'Publishing a file on this case is refused, because of {party}.',
					{ party: name },
				)
			}
			return t('dossiq', 'A message to {party} is refused.', { party: name })
		},
	},
}
</script>

<style scoped lang="scss">
.case-parties {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);

	&__verdicts {
		display: flex;
		flex-direction: column;
		gap: var(--default-grid-baseline);
	}

	&__role-label {
		margin: 0 0 var(--default-grid-baseline) 0;
		color: var(--color-text-maxcontrast);
		font-size: 0.9em;
		text-transform: none;
	}

	&__list {
		display: flex;
		flex-direction: column;
		gap: var(--default-grid-baseline);
	}

	&__party {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: calc(var(--default-grid-baseline) * 2);
		padding: var(--default-grid-baseline);
		border-radius: var(--border-radius);

		&:hover {
			background-color: var(--color-background-hover);
		}
	}

	&__party-name {
		font-weight: bold;
	}

	&__party-kind,
	&__party-email {
		color: var(--color-text-maxcontrast);
	}

	&__primary {
		padding: 0 calc(var(--default-grid-baseline) * 2);
		border-radius: var(--border-radius-pill);
		background-color: var(--color-primary-element-light);
		font-size: 0.85em;
	}

	&__indicator {
		padding: 0 calc(var(--default-grid-baseline) * 2);
		border-radius: var(--border-radius-pill);
		font-size: 0.85em;

		&--warning {
			background-color: var(--color-warning);
			color: var(--color-warning-text, var(--color-main-text));
		}

		&--error {
			background-color: var(--color-error);
			color: var(--color-error-text, var(--color-primary-element-text));
		}
	}
}
</style>
