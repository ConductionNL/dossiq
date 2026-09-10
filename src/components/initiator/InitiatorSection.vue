<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  The requester card + handoff provenance for the manifest CaseDetail
  overview (initiator-display / semantic-case-intake). Self-fetching from
  the route case id; renders NOTHING when the case has neither a requester
  nor a handoff source ("no initiator, no clutter").

  The card paints from the projection fields first, so it appears without
  waiting for a second request, then resolves the source register row to
  add the address and the secrecy indication. When the case carries
  `requester` but no projection — a case that arrived through the ns#Case
  handoff, or one saved by a form that wrote only the canonical reference —
  the row is resolved by uuid and the projection is filled on first render.
  That back-fill is what keeps the Requester column and its filter in step.

  A protected person's BSN is masked to its last four digits behind a
  Protected marker. Revealing it re-reads the brpPerson row with
  `_reason: "bsn-reveal"`, and OpenRegister logs that read against the
  case's processing activity (`logReads` on brpPerson). Dossiq writes no
  log row of its own.

  @spec openspec/specs/initiator-display/spec.md
  @spec openspec/specs/semantic-case-intake/spec.md
-->
<template>
	<div class="initiator-section" data-testid="initiator-section">
		<!--
			🔴 THE EMPTY STATE IS NOT CLUTTER, THE EMPTY BOX WAS.

			This component used to render NOTHING when a case had neither a
			requester nor a handoff source, on a rule written "no initiator, no
			clutter". That rule assumed the component could decide whether it
			appeared at all. It cannot: the manifest declares `initiator` as a
			grid cell with `showTitle: true`, so the card chrome and the word
			Initiator painted regardless and the body below them was blank. The
			page therefore showed an empty titled box on every case with no
			requester, which is the demo case and most real ones early in their
			life.

			A sentence saying so costs the same space and answers the question
			the blank box raised.
		-->
		<p
			v-if="!hasInitiator && !hasHandoff"
			class="initiator-section__empty"
			data-testid="initiator-empty">
			{{ t('dossiq', 'No initiator has been recorded for this case.') }}
		</p>
		<template v-if="hasInitiator">
			<div class="initiator-section__row">
				<component
					:is="typeIcon"
					:size="20"
					class="initiator-section__icon" />
				<span class="initiator-section__name" data-testid="initiator-name">{{
					caseObject.initiatorDisplayName
				}}</span>
				<span class="initiator-section__type" data-testid="initiator-type">{{
					typeLabel
				}}</span>
				<span
					v-if="isProtected"
					class="initiator-section__protected"
					data-testid="initiator-protected">
					<ShieldLockOutline :size="16" />
					{{ t('dossiq', 'Protected') }}
				</span>
			</div>

			<div class="initiator-section__source">
				<a
					v-if="sourceLink"
					:href="sourceLink"
					data-testid="initiator-source-link">
					{{ shownSourceId }}
				</a>
				<span v-else data-testid="initiator-source-id">{{
					shownSourceId
				}}</span>

				<NcButton
					v-if="isProtected && !revealed"
					variant="tertiary"
					data-testid="initiator-reveal"
					:disabled="revealing"
					@click="reveal">
					{{ t('dossiq', 'Reveal') }}
				</NcButton>
			</div>

			<div
				v-if="addressLine"
				class="initiator-section__address"
				data-testid="initiator-address">
				{{ addressLine }}
			</div>
		</template>

		<!-- Handoff provenance (semantic-case-intake): when the case arrived
		     via the ns#Case handoff it carries a handoffSource back-link to the
		     originating object; surface the origin badge + the received-at
		     timestamp (the case's creation time = the handoff moment). -->
		<div
			v-if="hasHandoff"
			class="initiator-section__handoff"
			data-testid="handoff-provenance">
			<TransitConnectionVariant :size="18" class="initiator-section__icon" />
			<span class="initiator-section__handoff-label">{{
				t('dossiq', 'Received via handoff')
			}}</span>
			<span v-if="handoffReceivedAt" class="initiator-section__type">{{
				handoffReceivedAt
			}}</span>
			<a
				class="initiator-section__handoff-link"
				:href="handoffSourceLink"
				target="_blank"
				rel="noopener noreferrer">
				{{ t('dossiq', 'Open source object') }}
			</a>
		</div>
	</div>
</template>

<script>
import { generateUrl } from '@nextcloud/router'
import { NcButton } from '@nextcloud/vue'
import AccountOutline from 'vue-material-design-icons/AccountOutline.vue'
import CardAccountMailOutline from 'vue-material-design-icons/CardAccountMailOutline.vue'
import Domain from 'vue-material-design-icons/Domain.vue'
import ShieldLockOutline from 'vue-material-design-icons/ShieldLockOutline.vue'
import TransitConnectionVariant from 'vue-material-design-icons/TransitConnectionVariant.vue'
import {
	companyResult,
	maskNumber,
	personResult,
} from '../../services/initiatorSearch.js'
import { useObjectStore } from '../../store/modules/object.js'
import { initializeStores } from '../../store/store.js'

export default {
	name: 'InitiatorSection',
	components: {
		AccountOutline,
		Domain,
		CardAccountMailOutline,
		NcButton,
		ShieldLockOutline,
		TransitConnectionVariant,
	},

	data() {
		return {
			caseObject: {},
			sourceObjectId: null,
			/** The resolved source register row (address, secrecy flag). */
			sourceRow: null,
			/** The full number, held only after an explicit reveal. */
			revealedSourceId: '',
			revealing: false,
		}
	},

	computed: {
		/** @spec openspec/specs/initiator-display/spec.md */
		objectStore() {
			return useObjectStore()
		},

		/** @spec openspec/specs/initiator-display/spec.md */
		hasInitiator() {
			return !!(
				this.caseObject
				&& this.caseObject.initiatorType
				&& this.caseObject.initiatorDisplayName
			)
		},

		/** @spec openspec/specs/initiator-display/spec.md */
		typeLabel() {
			switch (this.caseObject.initiatorType) {
				case 'company':
					return t('dossiq', 'Company')
				case 'contact':
					return t('dossiq', 'Contact')
				default:
					return t('dossiq', 'Person')
			}
		},

		/** @spec openspec/specs/initiator-display/spec.md */
		typeIcon() {
			switch (this.caseObject.initiatorType) {
				case 'company':
					return 'Domain'
				case 'contact':
					return 'CardAccountMailOutline'
				default:
					return 'AccountOutline'
			}
		},

		/** @spec openspec/specs/initiator-display/spec.md */
		typeSchema() {
			switch (this.caseObject.initiatorType) {
				case 'person':
					return 'brpPerson'
				case 'company':
					return 'kvkCompany'
				default:
					return null
			}
		},

		/**
		 * Whether the person behind this case asked for their data to be
		 * protected. Only a person can be: a company has no secrecy
		 * indication in the KvK register.
		 *
		 * @spec openspec/specs/initiator-display/spec.md
		 */
		isProtected() {
			return (
				this.caseObject.initiatorType === 'person'
				&& this.sourceRow?.indicatieGeheim === true
			)
		},

		/** @spec openspec/specs/initiator-display/spec.md */
		revealed() {
			return this.revealedSourceId !== ''
		},

		/**
		 * The identifying number as it should be read right now: masked for
		 * a protected person until it is revealed, in full otherwise.
		 *
		 * @spec openspec/specs/initiator-display/spec.md
		 */
		shownSourceId() {
			const sourceId = String(this.caseObject.initiatorSourceId || '')
			if (!this.isProtected || this.revealed) {
				return this.revealed ? this.revealedSourceId : sourceId
			}
			return maskNumber(sourceId)
		},

		/**
		 * Where the person lives, or where the company is registered, read
		 * off the source row. Empty until the row resolves, and empty for a
		 * contact, which has no register row to read.
		 *
		 * @spec openspec/specs/initiator-display/spec.md
		 */
		addressLine() {
			const row = this.sourceRow
			if (!row) {
				return ''
			}
			const block = row.residence || row.address
			if (!block) {
				return ''
			}
			const street = [block.street || block.streetName, block.houseNumber]
				.filter((part) => part !== undefined && part !== null && part !== '')
				.join(' ')
			return [street, block.postcode, block.city || block.place]
				.filter((part) => !!part)
				.join(', ')
		},

		/**
		 * The in-app contact page for this initiator.
		 *
		 * It used to deep-link into OpenRegister's own object viewer. That
		 * showed the raw register row: every field of the register set, none
		 * of the person's cases, and a way out of the app the reader did not
		 * ask for. `contacts-domain` gives a person and an organisation a page
		 * of their own, and the number on this card is the way to it.
		 *
		 * Built with `generateUrl` rather than `$router.resolve`: the router
		 * base is `generateUrl('/apps/dossiq')`, which is `/index.php/apps/
		 * dossiq` only where Nextcloud's front-controller URLs are in play, so
		 * a hard-coded prefix falls outside the base on a pretty-URL instance
		 * and the router's catch-all quietly redirects to the Dashboard.
		 *
		 * @return {string|null} The contact page URL, or null for a contact
		 *   with no register row behind it.
		 *
		 * @spec openspec/specs/initiator-display/spec.md
		 */
		sourceLink() {
			if (!this.contactRouteBase || !this.sourceObjectId) {
				return null
			}
			return generateUrl(
				`/apps/dossiq/${this.contactRouteBase}/${this.sourceObjectId}`,
			)
		},

		/**
		 * Which contact page an initiator of this type belongs on.
		 *
		 * A detail page takes one schema, so a person and an organisation have
		 * separate pages; `contact` has neither, because a Nextcloud contact is
		 * not a register row.
		 *
		 * @return {string|null} The route segment, or null when there is none.
		 *
		 * @spec openspec/specs/initiator-display/spec.md
		 */
		contactRouteBase() {
			switch (this.caseObject.initiatorType) {
				case 'person':
					return 'contacts'
				case 'company':
					return 'organisations'
				default:
					return null
			}
		},

		/** @spec openspec/specs/semantic-case-intake/spec.md */
		hasHandoff() {
			return !!(this.caseObject && this.caseObject.handoffSource)
		},

		/**
		 * Deep-link to the originating object behind the handoffSource
		 * back-link, via OpenRegister's URN resolver (app-agnostic — the
		 * source may live in any register/app per ADR-051).
		 *
		 * @spec openspec/specs/semantic-case-intake/spec.md
		 */
		handoffSourceLink() {
			if (!this.caseObject.handoffSource) {
				return null
			}
			return generateUrl(
				`/apps/openregister/api/urn/resolve?urn=${encodeURIComponent(this.caseObject.handoffSource)}`,
			)
		},

		/**
		 * The handoff moment = the case's creation timestamp (the case is
		 * created at handoff execution). Formatted for display; empty when
		 * unavailable.
		 *
		 * @spec openspec/specs/semantic-case-intake/spec.md
		 */
		handoffReceivedAt() {
			const raw =
				this.caseObject['@self']?.created
				|| this.caseObject.created
				|| this.caseObject.startDate
			if (!raw) {
				return ''
			}
			const date = new Date(raw)
			return Number.isNaN(date.getTime()) ? '' : date.toLocaleDateString()
		},
	},

	async mounted() {
		// CnAppRoot mounts manifest slot widgets before App.vue's
		// initializeStores() has resolved the app-config, so the 'case'
		// object type may not be registered yet — await it here
		// (idempotent), same pattern as OverdueCasesWidget.
		await initializeStores()
		await this.load()
	},

	methods: {
		/**
		 * Load the case and resolve the requester's source register row.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/initiator-display/spec.md
		 */
		async load() {
			const caseId = this.$route?.params?.id
			if (!caseId) {
				return
			}
			try {
				this.caseObject =
					(await this.objectStore.fetchObject('case', caseId)) || {}
			} catch (err) {
				console.error('[InitiatorSection] case load failed', err)
				this.caseObject = {}
				return
			}
			await this.fillProjectionFromRequester()
			await this.resolveSource()
		},

		/**
		 * Fill the projection from the canonical `requester` uuid when the
		 * case carries the reference and no projection.
		 *
		 * Two writers reach `case.requester`: the picker, which writes the
		 * projection alongside it, and the ns#Case semantic handoff, which
		 * writes the reference alone. Without this the card, the list column
		 * and the filter would all be blank for every handed-off case, and
		 * blank reads as "no requester" rather than as "not projected yet".
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/initiator-display/spec.md
		 */
		async fillProjectionFromRequester() {
			const requester = this.caseObject.requester
			if (!requester || this.caseObject.initiatorDisplayName) {
				return
			}
			for (const [schema, shape, type] of [
				['brpPerson', personResult, 'person'],
				['kvkCompany', companyResult, 'company'],
			]) {
				const row = await this.fetchRow(schema, requester)
				if (!row) {
					continue
				}
				const result = shape(row)
				const projection = {
					initiatorType: type,
					initiatorSourceId: String(result.sourceId || ''),
					initiatorDisplayName: result.displayName || '',
				}
				this.caseObject = { ...this.caseObject, ...projection }
				this.sourceRow = row
				this.sourceObjectId = result.objectId
				try {
					await this.objectStore.saveObject('case', {
						...this.caseObject,
						...projection,
						// saveObject PUTs only when the payload names an id;
						// an OpenRegister object carries it in @self as well
						// as at the top level, and without it the back-fill
						// would CREATE a second case.
						id: this.caseObject.id || this.caseObject['@self']?.id,
					})
				} catch (err) {
					// The card still renders what it resolved; the projection
					// is written again on the next render.
					console.error(
						'[InitiatorSection] projection back-fill failed',
						err,
					)
				}
				return
			}
		},

		/**
		 * Read one row by uuid, answering null for the set that does not
		 * hold it. A uuid says nothing about which register set it came
		 * from, so both are asked in turn.
		 *
		 * @param {string} schema The register set to ask.
		 * @param {string} uuid The row's uuid.
		 * @return {Promise<object|null>} The row, or null.
		 * @spec openspec/specs/initiator-display/spec.md
		 */
		async fetchRow(schema, uuid) {
			try {
				return await this.objectStore.fetchObject(schema, uuid)
			} catch {
				return null
			}
		},

		/**
		 * Resolve the source register row behind `initiatorSourceId`, for
		 * the deep link, the address and the secrecy indication.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/initiator-display/spec.md
		 */
		async resolveSource() {
			if (!this.typeSchema || !this.caseObject.initiatorSourceId) {
				return
			}
			if (this.sourceRow) {
				return
			}
			try {
				const rows = await this.objectStore.fetchCollection(
					this.typeSchema,
					{
						...this.sourceFilter(),
						_limit: 1,
					},
				)
				const match = (rows || [])[0]
				this.sourceRow = match || null
				this.sourceObjectId = match?.id || match?.['@self']?.id || null
			} catch (err) {
				// Link resolution is best-effort — the plain value still renders.
				this.sourceObjectId = null
			}
		},

		/**
		 * The filter that finds the source row by its identifying number.
		 *
		 * @return {object} A single-key filter for the source schema.
		 * @spec openspec/specs/initiator-display/spec.md
		 */
		sourceFilter() {
			const value = this.caseObject.initiatorSourceId
			return this.caseObject.initiatorType === 'person'
				? { citizenServiceNumber: value }
				: { kvkNumber: value }
		},

		/**
		 * Show the full BSN of a protected person.
		 *
		 * The number is already on the case object, so this read is not how
		 * the card gets it: it is how the read gets LOGGED. `brpPerson` sets
		 * `logReads`, so OpenRegister records the access against the case's
		 * processing activity, and `_reason` says why it happened. Dossiq
		 * writes no log row itself.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/initiator-display/spec.md
		 */
		async reveal() {
			if (this.revealing || this.revealed) {
				return
			}
			this.revealing = true
			try {
				const rows = await this.objectStore.fetchCollection('brpPerson', {
					...this.sourceFilter(),
					_limit: 1,
					_reason: 'bsn-reveal',
				})
				const match = (rows || [])[0]
				this.revealedSourceId = String(
					match?.citizenServiceNumber
						|| match?.citizen_service_number
						|| this.caseObject.initiatorSourceId
						|| '',
				)
			} catch (err) {
				console.error('[InitiatorSection] reveal failed', err)
			} finally {
				this.revealing = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.initiator-section__empty {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.initiator-section {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
	padding: calc(var(--default-grid-baseline) * 2);

	&__row {
		display: flex;
		align-items: center;
		gap: calc(var(--default-grid-baseline) * 2);
	}

	&__name {
		font-weight: bold;
	}

	&__type {
		color: var(--color-text-maxcontrast);
	}

	&__protected {
		display: inline-flex;
		align-items: center;
		gap: var(--default-grid-baseline);
		padding: 0 calc(var(--default-grid-baseline) * 1.5);
		border-radius: var(--border-radius-pill);
		background-color: var(--color-warning);
		color: var(--color-warning-text);
	}

	&__source {
		display: flex;
		align-items: center;
		gap: calc(var(--default-grid-baseline) * 2);
		color: var(--color-text-maxcontrast);
	}

	&__address {
		color: var(--color-text-maxcontrast);
	}

	&__handoff {
		display: flex;
		align-items: center;
		gap: calc(var(--default-grid-baseline) * 2);
		padding-top: var(--default-grid-baseline);
	}

	&__handoff-label {
		font-weight: bold;
	}
}
</style>
