// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Cell-widget registry for dossiq's manifest-driven index pages.
//
// Each entry is a Vue component, referenced by id from
// `pages[].config.columns[].widget` in src/manifest.json and resolved by
// CnCellRenderer through the `cnCellWidgets` inject that CnAppRoot provides
// from its `cellWidgets` prop. The component receives
// `{ value, row, property, formatted, ...widgetProps }`.
//
// This is the sibling of `formatters.js`, and the difference between the two
// is the reason this file exists: a FORMATTER returns a string, so it can
// shape a value but cannot say anything about it. A cell that has to carry
// STATE — an overdue deadline in the signalering red, a colour or an icon
// beside the text — needs markup, and markup means a component. Reach for a
// formatter first; add an entry here only when the cell has a state to show.

import DeadlineCountdownCell from '../components/cells/DeadlineCountdownCell.vue'
import StatusBadgeCell from '../components/cells/StatusBadgeCell.vue'

export default {
	// The Deadline column on the Cases index: days left, days overdue past
	// the deadline, empty when the case has none.
	// @spec openspec/changes/one-case-list/specs/signalering-widgets/spec.md
	deadlineCountdown: DeadlineCountdownCell,

	// The Status column on the Cases index: the status name in the colour its
	// status type carries.
	// @spec openspec/specs/case-types/spec.md
	statusBadge: StatusBadgeCell,
}
