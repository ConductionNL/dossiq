<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  The case identity, on the first row of the case page.

  The page used to render the eyebrow CASE and the title and nothing else:
  which case you were on was a fact you had to go looking for, in a data
  widget whose Identifier field hid behind Show all 12 fields. Every
  competitor puts that fact under the title. This row is it — the case
  number, the case type, the status, the handler, the deadline and the way
  back to the list, all above the fold.

  It is a `custom` widget rather than the built-in `header` one because that
  built-in is a dashboard banner (title, subtitle, call to action): it binds
  neither a $ref status nor a countdown.

  The breadcrumb and the identity line are RENDERED here rather than declared
  on the page, because CnDetailPage 2.41.0 reads neither a `breadcrumbs` page
  key nor `subtitleField` (its own `subtitle` prop is the sidebar header's).
  Both keys are declared on the page config anyway, as the shape the host will
  read once it exists, and this widget takes the breadcrumb trail from that
  same declaration through `widget.props` so the two cannot drift.

  @spec openspec/changes/case-header/specs/case-dashboard-view/spec.md
-->
<template>
	<div class="case-header" data-testid="case-header">
		<CnBreadcrumbs
			v-if="crumbs.length > 0"
			class="case-header__crumbs"
			data-testid="case-header-breadcrumbs"
			:crumbs="crumbs"
			:ariaLabel="t('dossiq', 'Case breadcrumb')" />

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
import { CnBreadcrumbs, CnStatusBadge } from '@conduction/nextcloud-vue'
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

	components: { CnBreadcrumbs, CnStatusBadge },

	props: {
		/**
		 * The manifest widget definition, handed to every `widget-<id>` slot
		 * by CnDetailPage. Its `props` carry the breadcrumb trail and the
		 * countdown thresholds.
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

		/**
		 * The breadcrumb trail in CnBreadcrumbs' own shape.
		 *
		 * The manifest declares it as `{ label, route }` for a named page and
		 * `{ field }` for a value off the record — the shape a `breadcrumbs`
		 * page key would take. This maps that onto `{ label, to, icon }`, and
		 * drops the LAST crumb's target, because CnBreadcrumbs renders the
		 * final crumb unlinked with `aria-current="page"` regardless and a
		 * `to` on it would only be dead config.
		 *
		 * @return {Array<object>} The crumbs, root first, current location last.
		 */
		/**
		 * The query the crumb back to the list carries.
		 *
		 * The Cases lenses are chip state today, not a query parameter, so
		 * there is nothing on the URL to carry back and this is usually empty.
		 * It is passed through anyway: the moment a lens (or a search, or a
		 * page number) becomes a query parameter, the trip out to a case and
		 * back keeps it, rather than dropping the reader on a reset list.
		 *
		 * @return {object} The route query to hand the list.
		 *
		 * @spec openspec/changes/case-header/specs/case-dashboard-view/spec.md
		 */
		listQuery() {
			const query = this.$route?.query
			return query && typeof query === 'object' ? { ...query } : {}
		},

		/** @spec openspec/changes/case-header/specs/case-dashboard-view/spec.md */
		crumbs() {
			const declared = this.widget?.props?.breadcrumbs
			if (!Array.isArray(declared)) {
				return []
			}
			return declared
				.map((crumb, index) => {
					const label = crumb.field
						? String(this.caseObject[crumb.field] ?? '')
						: t('dossiq', String(crumb.label ?? ''))
					const isLast = index === declared.length - 1
					return {
						label,
						...(crumb.icon ? { icon: crumb.icon } : {}),
						...(crumb.route && !isLast
							? { to: { name: crumb.route, query: this.listQuery } }
							: {}),
					}
				})
				.filter((crumb) => crumb.label !== '' || crumb.icon)
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
 * `safe center`, not `center`.
 *
 * This box is a fixed-height grid cell (`gridHeight: 2`) that is also a
 * scroll container: `overflow-x: auto` leaves the other axis `visible`, and
 * CSS resolves that pair by computing `overflow-y` to `auto` as well. A
 * column flex box that overflows and centres its content pushes the overflow
 * out of BOTH ends, and the half above the box is unreachable, because
 * `scrollTop` cannot go below zero.
 *
 * The breadcrumb trail is the first child, so it is the half that was lost.
 * Measured on a running instance at 860px, before the change: the box starts
 * at y=147 and the trail renders at y=135 — twelve pixels above its own
 * container — with `scrollHeight` 152 against `clientHeight` 136 and a scroll
 * range of 0 to 16 that only ever reveals the BOTTOM. So the Cases crumb was
 * painted, hit-tested against whatever sat behind it, and could not be
 * clicked. `case-header.spec.ts` reported it as fifteen seconds of "element
 * is visible, enabled and stable" followed by three different elements
 * intercepting the click, which reads as a flaky test rather than a control
 * that is out of reach.
 *
 * `safe center` is the keyword for exactly this: it centres while the content
 * fits and falls back to start-alignment the moment it does not. Measured on
 * the same instance, same case: content that fits is centred identically
 * (4px above, 4px below, unchanged), and content that overflows now starts
 * 4px INSIDE the top with the whole 31px of overflow at the bottom, where the
 * scroll range can reach it.
 *
 * Growing the box instead (`height: auto; min-height: 100%`) also frees the
 * crumb, and was rejected: it spills 47px over the widget in the cell below,
 * which has `overflow: visible`.
 */
.case-header {
	display: flex;
	flex-direction: column;
	gap: 4px;
	justify-content: safe center;
	height: 100%;
	padding: 4px 12px;
	overflow-x: auto;
}

.case-header__identity {
	display: flex;
	flex-wrap: wrap;
	gap: 4px 24px;
	align-items: baseline;
	margin: 0;
}

.case-header__field {
	display: flex;
	gap: 6px;
	align-items: baseline;
}

.case-header__field dt {
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
}

.case-header__field dd {
	margin: 0;
	font-weight: 500;
}

.case-header__countdown.is-warning {
	color: var(--color-warning-text, var(--color-warning));
}

.case-header__countdown.is-danger {
	color: var(--color-error-text, var(--color-error));
}
</style>
