<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  One tab that holds several panels, stacked, each under its own heading.

  WHY THIS EXISTS. The case page carried fourteen tabs. Four of the folds that
  bring it back to six put two panels behind one label: the documents list and
  the case folder, the parties and the contact with them, the tasks and the
  appointments, the objects and the locations. `CnTabsWidget` 2.41.0 binds one
  `widgetId` per tab, so without this component each of those four folds needs
  a tab of its own, and the count stays at ten.

  WHY IT IS NOT A WORKAROUND. `container: true` is the library's own flag for a
  widget that renders other widgets, and `registerDashboardWidget` is the
  documented way a consuming app extends the shared registry. This component
  renders every child through `CnDetailWidgetHost`, the same dispatch
  `CnTabsWidget` uses, so a child behaves in a section exactly as it did in a
  tab. Nothing here reimplements a renderer.

  WHY THE SECTIONS CARRY THEIR WIDGET INLINE RATHER THAN BY ID. `CnTabsWidget`
  passes its panel host every context prop except `availableWidgets`, so a
  container nested inside a tab is handed an empty sibling list and can resolve
  no id. Naming the widget inline sidesteps that gap instead of routing around
  it: when the library forwards the list, `content.sections[].widget` becomes
  `content.sections[].widgetId` and this comment goes with it.

  WHY STACKED AND NOT NESTED TABS. A tab strip inside a tab panel is the
  complexity this change exists to remove, one level further down.

  @spec openspec/changes/the-case-page-finished/specs/case-dashboard-view/spec.md
-->
<template>
	<div class="case-sections">
		<section
			v-for="section in sections"
			:key="section.key"
			class="case-sections__section"
			:class="{ 'case-sections__section--empty': !filled[section.key] }"
			:data-testid="`case-section-${section.key}`">
			<!-- The heading waits for content. A section whose widget type does
			     not resolve renders NOTHING, and a heading over that is a label
			     on a void: worse than what it replaced, because as a whole tab
			     an unresolvable panel was merely an empty tab. -->
			<h3 v-if="filled[section.key]" class="case-sections__heading">
				{{ section.label }}
			</h3>
			<!-- The HOST always mounts, even while the section reads as empty.
			     Gating the host on `filled` instead would deadlock: a widget
			     that has not mounted cannot fetch, so it could never gain the
			     content that would let it render. -->
			<div :ref="(el) => setHostRef(section.key, el)">
				<CnDetailWidgetHost
					:widget="section.widget"
					chrome="bare"
					:objectId="objectId"
					:object="objectData"
					:objectType="objectType"
					:schemaObject="schemaObject"
					:register="register"
					:schema="schema"
					:store="store"
					:surface="surface"
					:integrationContext="integrationContext"
					:cnRegistry="cnRegistry" />
			</div>
		</section>
	</div>
</template>

<script>
import { CnDetailWidgetHost } from '@conduction/nextcloud-vue'

/**
 * CaseSectionsWidget: a tab panel that holds more than one widget.
 *
 * Configured from the manifest as
 * `{ type: 'case-sections', content: { sections: [{ label, widget }] } }`,
 * where `widget` is an ordinary widget definition of any type the shared
 * dispatch understands.
 */
export default {
	name: 'CaseSectionsWidget',

	components: {
		CnDetailWidgetHost,
	},

	props: {
		/** The widget config: `{ sections: [{ label, widget }] }`. */
		content: {
			type: Object,
			default: () => ({}),
		},

		/** The bound case's id. */
		objectId: {
			type: [String, Number],
			default: '',
		},

		/** The loaded case, or null while it is still being fetched. */
		objectData: {
			type: Object,
			default: null,
		},

		/** The resolved object-type slug. */
		objectType: {
			type: String,
			default: '',
		},

		/** The resolved JSON Schema object, needed by a `data` child. */
		schemaObject: {
			type: Object,
			default: null,
		},

		/** OpenRegister register slug of the surface. */
		register: {
			type: [String, Object],
			default: '',
		},

		/** OpenRegister schema slug of the surface. */
		schema: {
			type: [String, Object],
			default: '',
		},

		/** The effective object store. */
		store: {
			type: Object,
			default: null,
		},

		/** Surface key, forwarded to every child. */
		surface: {
			type: String,
			default: '',
		},

		/** Integration context, needed by an `integration` child. */
		integrationContext: {
			type: Object,
			default: null,
		},

		/** The consuming app's component registry, needed by a custom-type child. */
		cnRegistry: {
			type: Object,
			default: () => ({}),
		},
	},

	data() {
		return {
			/**
			 * Section key to "has this section rendered anything", so the
			 * heading can wait for its widget.
			 *
			 * @type {Record<string, boolean>}
			 */
			filled: {},
		}
	},

	computed: {
		/**
		 * The sections to render, each with a stable key and a heading.
		 *
		 * A section whose `widget` is missing is dropped rather than rendered
		 * empty: unlike a tab, a headed but blank block says nothing about what
		 * went wrong, and `CnDetailWidgetHost` renders nothing for a type it
		 * cannot resolve, so the heading would be the only thing left.
		 *
		 * @return {Array<{key: string, label: string, widget: object}>} the sections.
		 * @spec openspec/changes/the-case-page-finished/specs/case-dashboard-view/spec.md
		 */
		sections() {
			const raw = Array.isArray(this.content?.sections)
				? this.content.sections
				: []
			return raw
				.filter((entry) => entry && entry.widget && entry.widget.type)
				.map((entry, index) => ({
					key: entry.widget.id || `${entry.widget.type}-${index}`,
					label: entry.label || entry.widget.title || '',
					widget: entry.widget,
				}))
		},
	},

	beforeUnmount() {
		this.stopObserving()
	},

	methods: {
		/**
		 * Watch one section's host for content, so its heading can appear when
		 * the widget renders and stay away when it never does.
		 *
		 * A MutationObserver rather than a one-shot check on mount: nearly
		 * every widget here mounts empty and fills after its own fetch, so a
		 * check taken once would record every section as empty and hide every
		 * heading on the page.
		 *
		 * @param {string} key The section key.
		 * @param {HTMLElement|null} el The host wrapper, or null on teardown.
		 * @return {void}
		 * @spec openspec/specs/case-dashboard-view/spec.md#scenario-a-section-whose-widget-does-not-resolve-stays-silent
		 */
		setHostRef(key, el) {
			this.observers = this.observers || {}
			if (this.observers[key]) {
				this.observers[key].disconnect()
				delete this.observers[key]
			}
			if (!el) return

			// Only ASSIGN on a change. `filled` drives the template, so writing
			// it unconditionally on every mutation re-renders, which mutates
			// the DOM, which fires the observer again.
			const measure = () => {
				const next = this.hasContent(el)
				if (this.filled[key] === next) return
				this.filled = { ...this.filled, [key]: next }
			}
			const observer = new MutationObserver(measure)
			observer.observe(el, {
				childList: true,
				subtree: true,
				characterData: true,
			})
			this.observers[key] = observer

			// NOT synchronously. A `:ref` callback runs DURING the patch, and
			// writing reactive state there either loops or is dropped, which
			// left every section reading as empty and every heading hidden.
			this.$nextTick(measure)
		},

		/**
		 * Whether a host has rendered anything a reader would see.
		 *
		 * `CnDetailWidgetHost` renders an EMPTY element for a widget type it
		 * cannot resolve, so "has an element child" is not the question. Text
		 * answers it for almost every widget, including an empty state, which
		 * is content: an empty list still tells the reader the section exists
		 * and holds nothing. The element list catches the purely graphical
		 * case, such as a map or a chart with no labels.
		 *
		 * @param {HTMLElement} el The host wrapper.
		 * @return {boolean} true when the section has something to show.
		 * @spec openspec/specs/case-dashboard-view/spec.md#scenario-a-section-whose-widget-does-not-resolve-stays-silent
		 */
		hasContent(el) {
			if (!el) return false
			if (el.textContent && el.textContent.trim().length > 0) return true
			return (
				el.querySelector('img, svg, canvas, table, input, button') !== null
			)
		},

		/**
		 * Drop every observer, so a destroyed panel leaves none running.
		 *
		 * @return {void}
		 * @spec openspec/specs/case-dashboard-view/spec.md#scenario-a-section-whose-widget-does-not-resolve-stays-silent
		 */
		stopObserving() {
			for (const observer of Object.values(this.observers || {})) {
				observer.disconnect()
			}
			this.observers = {}
		},
	},
}
</script>

<style scoped>
.case-sections {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline, 4px);
}

.case-sections__section + .case-sections__section {
	padding-top: calc(var(--default-grid-baseline, 4px) * 4);
	border-top: 1px solid var(--color-border);
}

/*
 * A section with nothing in it keeps its host mounted, so the widget can still
 * fetch and fill later, but it must not draw a divider or reserve space. An
 * empty block with a rule above it reads as a section that failed to load,
 * which is the thing this component stopped doing when the heading went
 * conditional.
 */
.case-sections__section--empty,
.case-sections__section--empty + .case-sections__section {
	padding-top: 0;
	border-top: none;
}

.case-sections__heading {
	margin: 0 0 calc(var(--default-grid-baseline, 4px) * 2);
	font-size: 1rem;
	font-weight: 600;
	color: var(--color-main-text);
}
</style>
