<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  The case identity, as a card in the page's right-hand column.

  The page used to render the eyebrow CASE and the title and nothing else:
  which case you were on was a fact you had to go looking for, in a data
  widget whose Identifier field hid behind Show all 12 fields. Every
  competitor puts that fact near the title. This card is it: the case number,
  the case type, the status, the handler and the deadline.

  It is a `custom` widget rather than the built-in `header` one because that
  built-in is a dashboard banner (title, subtitle, call to action): it binds
  neither a $ref status nor a countdown.

  🔴 IT USED TO BE A FULL-WIDTH BAND UNDER THE TITLE, AND IT WAS MOSTLY AIR.
  Two grid rows tall, twelve columns wide, holding three to five short facts
  laid out in a row: on a real case the band was more than half empty, and the
  two rows it cost came out of the content below. As a card in the narrow
  right column the same facts stack, read as a labelled group, and the page
  gets its two rows back.

  It also used to render a breadcrumb whose LAST crumb was the case title,
  directly under the page header that already printed that title. The trail
  said "Cases > Dakkapel Kerkstraat 12" one line below "Dakkapel Kerkstraat
  12". That crumb is gone with the rest of the trail; the way back to the list
  is the app menu, which is where every other page in the fleet puts it.

  @spec openspec/changes/case-header/specs/case-dashboard-view/spec.md
-->
<template>
	<div class="case-header" data-testid="case-header">
		<dl class="case-header__identity">
			<div v-if="identifier" class="case-header__field">
				<dt>{{ t('dossiq', 'Case number') }}</dt>
				<dd data-testid="case-header-identifier">
					{{ identifier }}
				</dd>
			</div>

			<div v-if="caseTypeName" class="case-header__field">
				<dt>{{ t('dossiq', 'Case type') }}</dt>
				<dd data-testid="case-header-casetype">
					{{ caseTypeName }}
				</dd>
			</div>

			<div class="case-header__field">
				<dt>{{ t('dossiq', 'Status') }}</dt>
				<dd>
					<CnStatusBadge
						data-testid="case-header-status"
						:label="statusLabel"
						:variant="statusVariant"
						size="small" />
				</dd>
			</div>

			<div v-if="assignee" class="case-header__field">
				<dt>{{ t('dossiq', 'Assignee') }}</dt>
				<dd data-testid="case-header-assignee">
					{{ assignee }}
				</dd>
			</div>

			<div v-if="countdown" class="case-header__field">
				<dt>{{ t('dossiq', 'Deadline') }}</dt>
				<dd
					class="case-header__countdown"
					data-testid="case-header-countdown"
					:class="countdownClass">
					{{ countdown.text }}
				</dd>
			</div>
		</dl>
	</div>
</template>

<script>
import { CnStatusBadge } from '@conduction/nextcloud-vue'
import { translate as t } from '@nextcloud/l10n'
import { useObjectStore } from '../../store/modules/object.js'
import { initializeStores } from '../../store/store.js'
import { deadlineCountdown } from '../../utils/deadlineCountdown.js'
import { resolveText } from '../../utils/i18nResolver.js'

/**
 * The thresholds the retired Time left tile counted with, kept here so the
 * deadline reads the same as it did before the tile folded into this row.
 * They follow the handling rhythm rather than round numbers: at two weeks a
 * handler still has room to act, at five days they do not.
 */
const DEFAULT_THRESHOLDS = { warn: 14, danger: 5 }

export default {
	name: 'CaseHeaderRow',

	components: { CnStatusBadge },

	props: {
		/**
		 * The manifest widget definition, handed to every `widget-<id>` slot
		 * by CnDetailPage. Its `props` carry the countdown thresholds.
		 */
		widget: {
			type: Object,
			default: () => ({}),
		},

		/** The loaded case, handed to the slot by the page. */
		object: {
			type: Object,
			default: null,
		},

		/** The case id, for the fetch fallback when the page has not loaded yet. */
		objectId: {
			type: [String, Number],
			default: '',
		},
	},

	data() {
		return {
			fetched: null,
			statusRow: null,
			caseTypeRow: null,
		}
	},

	computed: {
		/**
		 * The case this row describes, from the page or from our own fetch.
		 *
		 * @spec openspec/changes/case-header/specs/case-dashboard-view/spec.md
		 */
		caseObject() {
			return this.object || this.fetched || {}
		},

		/**
		 * The case id, from the page's own binding or the route.
		 *
		 * @return {string} The id, or the empty string.
		 *
		 * @spec openspec/changes/case-header/specs/case-dashboard-view/spec.md
		 */
		caseId() {
			return String(this.objectId || this.$route?.params?.id || '')
		},

		/**
		 * @return {string} The case number.
		 *
		 * @spec openspec/changes/case-header/specs/case-dashboard-view/spec.md
		 */
		identifier() {
			return String(this.caseObject.identifier ?? '')
		},

		/**
		 * @return {string} The Nextcloud user id of the primary handler.
		 *
		 * @spec openspec/changes/case-header/specs/case-dashboard-view/spec.md
		 */
		assignee() {
			return String(this.caseObject.assignee ?? '')
		},

		/**
		 * The case type's name, resolved from the uuid the case carries.
		 *
		 * An id that cannot be resolved keeps showing nothing rather than the
		 * raw uuid: a uuid under the title is not a fact about the case, it is
		 * a fact about the database.
		 *
		 * @return {string} The case type title, or the empty string.
		 *
		 * @spec openspec/changes/case-header/specs/case-dashboard-view/spec.md
		 */
		caseTypeName() {
			if (!this.caseTypeRow) {
				return ''
			}
			return (
				resolveText(this.caseTypeRow, 'title')
				|| resolveText(this.caseTypeRow, 'name')
			)
		},

		/**
		 * The status badge's label.
		 *
		 * A case with no status record reads Unknown rather than rendering
		 * nothing: an absent badge and an unset status look the same, and only
		 * one of them is a data problem.
		 *
		 * `statusType.name` is `x-translatable`, so it arrives as a per-locale
		 * object on a translated row and must be resolved rather than printed,
		 * or the badge shows raw JSON.
		 *
		 * @return {string} The status name, or Unknown.
		 *
		 * @spec openspec/changes/case-header/specs/case-dashboard-view/spec.md
		 */
		statusLabel() {
			const name = this.statusRow ? resolveText(this.statusRow, 'name') : ''
			return name || t('dossiq', 'Unknown')
		},

		/**
		 * @return {string} `success` on a final status, `info` otherwise.
		 *
		 * @spec openspec/changes/case-header/specs/case-dashboard-view/spec.md
		 */
		statusVariant() {
			if (!this.statusRow) {
				return 'default'
			}
			return this.statusRow.isFinal === true ? 'success' : 'info'
		},

		/**
		 * @return {{warn: number, danger: number}} The countdown bands.
		 *
		 * @spec openspec/changes/case-header/specs/case-dashboard-view/spec.md
		 */
		thresholds() {
			const declared = this.widget?.props?.thresholds
			return {
				warn: Number(declared?.warn ?? DEFAULT_THRESHOLDS.warn),
				danger: Number(declared?.danger ?? DEFAULT_THRESHOLDS.danger),
			}
		},

		/**
		 * The deadline in words, or null when the case has no deadline.
		 *
		 * Null renders NO countdown element at all. Printing "0 days left" for
		 * a case that has claimed no deadline would sort it visually beside the
		 * cases due today, which is a different claim entirely.
		 *
		 * @return {{days: number, overdue: boolean, text: string}|null}
		 *
		 * @spec openspec/changes/case-header/specs/case-dashboard-view/spec.md
		 */
		countdown() {
			return deadlineCountdown(this.caseObject.deadline ?? null)
		},

		/**
		 * @return {string} The band class the countdown paints in.
		 *
		 * @spec openspec/changes/case-header/specs/case-dashboard-view/spec.md
		 */
		countdownClass() {
			if (!this.countdown) {
				return ''
			}
			if (
				this.countdown.overdue
				|| this.countdown.days <= this.thresholds.danger
			) {
				return 'is-danger'
			}
			if (this.countdown.days <= this.thresholds.warn) {
				return 'is-warning'
			}
			return ''
		},

	},

	watch: {
		caseObject: {
			immediate: true,
			/**
			 * Re-resolve the status type and the case type whenever the page
			 * hands over a different case.
			 *
			 * @spec openspec/changes/case-header/specs/case-dashboard-view/spec.md
			 */
			handler() {
				this.resolveReferences()
			},
		},
	},

	/**
	 * Read the case when the page did not hand one over.
	 *
	 * @return {Promise<void>}
	 *
	 * @spec openspec/changes/case-header/specs/case-dashboard-view/spec.md
	 */
	async mounted() {
		await initializeStores()
		if (!this.object && this.caseId) {
			try {
				this.fetched = await useObjectStore().fetchObject(
					'case',
					this.caseId,
				)
			} catch {
				// A case that cannot be read leaves the row on its empty state
				// rather than throwing the whole page away.
				this.fetched = null
			}
		}
		await this.resolveReferences()
	},

	methods: {
		t,

		/**
		 * Look the status type and the case type up by the uuids the case
		 * carries, so the row shows two names rather than two uuids.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/case-header/specs/case-dashboard-view/spec.md
		 */
		async resolveReferences() {
			const statusId = String(this.caseObject.status ?? '')
			const caseTypeId = String(this.caseObject.caseType ?? '')
			if (!statusId && !caseTypeId) {
				return
			}
			let store
			try {
				store = useObjectStore()
			} catch {
				// Before initializeStores() has run there is no Pinia instance;
				// mounted() calls this again once there is.
				return
			}
			await Promise.all([
				this.resolveOne(store, 'statusType', statusId, 'statusRow'),
				this.resolveOne(store, 'caseType', caseTypeId, 'caseTypeRow'),
			])
		},

		/**
		 * Read one referenced row into one data key.
		 *
		 * @param {object} store The object store.
		 * @param {string} schema The schema slug to read from.
		 * @param {string} id The uuid the case carries, possibly empty.
		 * @param {string} key The data key to fill.
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/case-header/specs/case-dashboard-view/spec.md
		 */
		async resolveOne(store, schema, id, key) {
			if (!id) {
				this[key] = null
				return
			}
			try {
				this[key] = (await store.fetchObject(schema, id)) || null
			} catch {
				// An unresolvable reference reads as absent, which the badge
				// renders as Unknown and the type field renders as nothing.
				this[key] = null
			}
		},
	},
}
</script>

<style scoped>
/*
 * A stacked card, not a fixed-height scroll box.
 *
 * The previous rule set solved a problem this layout no longer has. The row
 * was a `gridHeight: 2` cell whose `overflow-x: auto` coerced `overflow-y` to
 * `auto` as well, making it a scroll container; a column flex box that centred
 * its content then pushed the overflow out of BOTH ends, and the half above
 * the box was unreachable because `scrollTop` cannot go below zero. The
 * breadcrumb was the first child, so it was the half that was lost: painted,
 * hit-tested against whatever sat behind it, and impossible to click.
 *
 * `safe center` fixed that by falling back to start-alignment on overflow.
 * The card does not need it: the fields stack, the cell is sized to the stack
 * rather than the stack squeezed into the cell, and there is no horizontal
 * axis to scroll. Keeping the workaround would only make the next reader
 * wonder what it was guarding.
 */
.case-header {
	display: flex;
	flex-direction: column;
	gap: 2px;
	padding: 4px 0;
}

.case-header__identity {
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin: 0;
}

/*
 * Label above value, not beside it. The column is four of twelve wide, and a
 * baseline-aligned `dt`/`dd` pair wraps a long case type onto its own line
 * anyway, leaving the label stranded beside white space.
 */
.case-header__field {
	display: flex;
	flex-direction: column;
	gap: 2px;
	min-width: 0;
}

.case-header__field dt {
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
}

.case-header__field dd {
	margin: 0;
	font-weight: 500;
	overflow-wrap: anywhere;
}

.case-header__countdown.is-warning {
	color: var(--color-warning-text, var(--color-warning));
}

.case-header__countdown.is-danger {
	color: var(--color-error-text, var(--color-error));
}
</style>
