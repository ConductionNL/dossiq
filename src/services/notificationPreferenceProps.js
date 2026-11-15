/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The platform's answer, shaped for the shared preferences screen.
 *
 * dossiq had a second notification settings screen of its own. It said which
 * layer decided each value, which was the right idea, but it could only say
 * three layers and it had no way to show a channel an administrator has
 * forced. `CnNotificationMatrix` shows four layers and a refusal, so this
 * maps onto that rather than growing a second implementation beside it.
 *
 * 🔴 A LAYER NOBODY ANSWERED FOR IS NOT GUESSED. The platform returns the
 * EFFECTIVE value and the source that decided it. When somebody has overridden
 * a notification, the shipped default underneath is not in the answer, and
 * writing the effective value into `appDefault` would put a made-up value on a
 * layer the screen displays. It is left false and corrected by the next read,
 * which is what happens the moment anybody clears their own value.
 *
 * 🔴 THE CHANNEL AXIS IS THE PLATFORM'S, NOT THIS FILE'S. Each entry says
 * which channels its rule can deliver on (`availableChannels`, the default
 * ones then the opt-in ones such as e-mail); those are the columns, and a
 * cell is on when the entry's effective `channels` hold it. A platform that
 * answers without them gets ONE column, called what it is. Fabricating a mail
 * column would tell a handler they can choose when the platform stores no
 * such choice, and their click would be silently collapsed onto the single
 * value that does exist.
 *
 * Pure: no axios, no Vue, no store.
 *
 * @spec openspec/specs/case-management/spec.md
 */

/** The column used when the platform answers without a channel axis. */
export const SINGLE_CHANNEL_ID = 'notification'

/** What the platform calls a value somebody set for themselves. */
export const SOURCE_PERSONAL = 'user-override'

/** What the platform calls a value a team lead set. */
export const SOURCE_GROUP = 'group-default'

/**
 * A call's options with its defaults filled in: a default applies to an
 * option that is left out or undefined, as a destructuring default does.
 *
 * @param {object} options The call.
 * @param {object} defaults The defaults by option.
 * @return {object} The options to read.
 */
function withDefaults(options, defaults) {
	const given = Object.entries(options).filter(([, value]) => value !== undefined)
	return { ...defaults, ...Object.fromEntries(given) }
}

/**
 * A stable id for one notification.
 *
 * Schema and key together, because the same key can exist on two schemas and
 * a collision would merge two rows into one switch.
 *
 * @param {object} entry - One entry from the platform.
 * @return {string} The id.
 * @spec openspec/specs/case-management/spec.md
 */
export function keyFor(entry) {
	return `${String(entry?.schema ?? '')}-${String(entry?.notification ?? '')}`
}

/**
 * The columns to render.
 *
 * @param {object} options - The call.
 * @param {Array<object>} [options.channels] - What the platform answered with.
 * @param {string} [options.singleChannelLabel] - What to call the one column
 *   when the platform has no channel axis.
 *
 * @return {Array<object>} `{ id, label, configured, unconfiguredReason }`.
 * @spec openspec/specs/case-management/spec.md
 */
export function channelsFrom(options = {}) {
	const call = withDefaults(options, { channels: [], singleChannelLabel: 'Notifications' })
	if (isFilledList(call.channels)) {
		return call.channels.map(channelColumn)
	}

	return [
		{
			id: SINGLE_CHANNEL_ID,
			label: call.singleChannelLabel,
			configured: true,
			unconfiguredReason: '',
		},
	]
}

/**
 * Whether a value is a list with something in it.
 *
 * @param {unknown} value The value.
 * @return {boolean} True for a non-empty array.
 */
function isFilledList(value) {
	return Array.isArray(value) === true && value.length > 0
}

/**
 * One column, from one channel the platform named.
 *
 * @param {object} channel The platform's channel.
 * @return {object} `{ id, label, configured, unconfiguredReason }`.
 */
function channelColumn(channel) {
	return {
		id: String(channel?.id ?? ''),
		label: String(channel?.label ?? channel?.id ?? ''),
		configured: channel?.configured !== false,
		unconfiguredReason: String(channel?.unconfiguredReason ?? ''),
	}
}

/**
 * The channels the entries can deliver on, in the order the platform names
 * them: every entry's default channels, then its opt-in ones.
 *
 * @param {Array<object>} entries - The platform's entries.
 * @return {Array<string>} The channel ids, or none when the platform answered without them.
 * @spec openspec/specs/case-management/spec.md#requirement-a-person-can-switch-e-mail-on-per-notification
 */
export function channelAxisFrom(entries = []) {
	const ids = []
	for (const entry of entries) {
		addNewChannelIds(ids, availableChannelsOf(entry))
	}
	return ids
}

/**
 * The channels one entry can deliver on, or none when it names none.
 *
 * @param {object} entry One entry.
 * @return {Array} Its `availableChannels`.
 */
function availableChannelsOf(entry) {
	return Array.isArray(entry?.availableChannels) ? entry.availableChannels : []
}

/**
 * Add each channel id that is a non-empty string and not in the list yet.
 *
 * @param {Array<string>} ids The ids so far, added to in place.
 * @param {Array} candidates The ids an entry names.
 */
function addNewChannelIds(ids, candidates) {
	for (const id of candidates) {
		if (typeof id === 'string' && id !== '' && ids.includes(id) === false) {
			ids.push(id)
		}
	}
}

/**
 * The channels one entry delivers on now: none while it is off, else its
 * effective channels, else its default (non-opt-in) ones.
 *
 * @param {object} entry - One entry.
 * @return {Array<string>} The channel ids that are on.
 * @spec openspec/specs/case-management/spec.md#requirement-a-person-can-switch-e-mail-on-per-notification
 */
export function channelsOnFor(entry) {
	if (entry?.enabled !== true) {
		return []
	}
	if (Array.isArray(entry?.channels) === true) {
		return entry.channels.map(String)
	}
	const optIn = Array.isArray(entry?.optInChannels) ? entry.optInChannels : []
	const available = Array.isArray(entry?.availableChannels) ? entry.availableChannels : []
	return available.filter((id) => optIn.includes(id) === false).map(String)
}

/**
 * One boolean per channel.
 *
 * @param {Array<string>} channelIds - The columns.
 * @param {(id: string) => boolean} on - Whether a column is on.
 * @return {object} `{ [channelId]: boolean }`.
 */
function perChannel(channelIds, on) {
	return Object.fromEntries(channelIds.map((id) => [id, on(id)]))
}

/**
 * The rows to render.
 *
 * @param {object} options - The call.
 * @param {Array<object>} [options.entries] - The platform's entries.
 * @param {(entry: object) => string} [options.label] - What to call one entry on screen.
 * @param {(title: string) => string} [options.groupLabel] - What to call a group on screen,
 *   given the schema's title; the title as it is when absent.
 * @param {Array<string>} [options.channelIds] - The columns, when the platform names its channels.
 *
 * @return {Array<object>} `{ id, label, group, groupLabel, appDefault, immediate }`.
 * @spec openspec/specs/case-management/spec.md
 */
export function eventsFrom(options = {}) {
	const { entries, label, groupLabel, channelIds } = withDefaults(options, {
		entries: [],
		label: (entry) => keyFor(entry),
		groupLabel: (title) => title,
		channelIds: [],
	})
	return entries.map((entry) => ({
		id: keyFor(entry),
		label: String(label(entry)),
		group: String(entry?.schema ?? ''),
		groupLabel: String(groupLabel(String(entry?.schemaTitle ?? entry?.schema ?? ''))),
		// Only when the platform says so. See the header: an effective value
		// is not the shipped default, and a guess would render as one.
		appDefault: channelIds.length > 0 ? appDefaultPerChannel(entry, channelIds) : appDefaultOf(entry),
		immediate: entry?.immediate === true,
	}))
}

/**
 * The shipped default, when it is knowable.
 *
 * @param {object} entry - One entry.
 * @return {boolean} The default, or false when nobody answered for it.
 */
function appDefaultOf(entry) {
	if (typeof entry?.appDefault === 'boolean') {
		return entry.appDefault
	}

	// The effective value IS the default when no layer above it decided.
	const source = String(entry?.source ?? '')
	if (source !== SOURCE_PERSONAL && source !== SOURCE_GROUP) {
		return entry?.enabled === true
	}

	return false
}

/**
 * The shipped default per channel. An opt-in channel is off by default; a
 * default channel follows the shipped on/off, when that is knowable.
 *
 * @param {object} entry - One entry.
 * @param {Array<string>} channelIds - The columns.
 * @return {object} `{ [channelId]: boolean }`.
 */
function appDefaultPerChannel(entry, channelIds) {
	const optIn = Array.isArray(entry?.optInChannels) ? entry.optInChannels : []
	const available = Array.isArray(entry?.availableChannels) ? entry.availableChannels : []
	const on = appDefaultOf(entry)
	return perChannel(channelIds, (id) => on && available.includes(id) && optIn.includes(id) === false)
}

/**
 * The four levels, and the refusals, keyed the way the screen wants them.
 *
 * @param {object} options - The call.
 * @param {Array<object>} [options.entries] - The platform's entries.
 * @param {string} [options.channelId] - The column these values sit in, with one column.
 * @param {Array<string>} [options.channelIds] - The columns, one per channel, when the platform names them.
 *
 * @return {object} `{ groupValues, personalValues, forcedValues, refusals }`.
 * @spec openspec/specs/case-management/spec.md
 */
export function valuesFrom(options = {}) {
	const call = withDefaults(options, { entries: [], channelId: SINGLE_CHANNEL_ID, channelIds: [] })
	const run = {
		channelId: call.channelId,
		channelIds: call.channelIds,
		columns: call.channelIds.length > 0 ? call.channelIds : [call.channelId],
		values: { groupValues: {}, personalValues: {}, forcedValues: {}, refusals: {} },
	}

	for (const entry of call.entries) {
		readEntry(run, entry)
	}

	return run.values
}

/**
 * Which of the values a row's source is kept in.
 */
const VALUES_BY_SOURCE = new Map([
	[SOURCE_PERSONAL, 'personalValues'],
	[SOURCE_GROUP, 'groupValues'],
])

/**
 * Keep what one entry says: its on/off per column under its source, and
 * whether it is forced or refused.
 *
 * @param {object} run The values being read, with the columns they sit in.
 * @param {object} entry One entry.
 */
function readEntry(run, entry) {
	const row = { id: keyFor(entry), entry }
	keepOn(run, row)
	keepForced(run, row)
	keepRefused(run, row)
}

/**
 * Keep an entry's on/off per column under its source, when its source is
 * a group or a personal value.
 *
 * @param {object} run The values being read, with the columns they sit in.
 * @param {{id: string, entry: object}} row The entry and its id.
 */
function keepOn(run, row) {
	const source = String(row.entry?.source ?? '')

	const on = onPerColumn(run, row.entry)
	const kept = VALUES_BY_SOURCE.get(source)
	if (kept !== undefined) {
		run.values[kept][row.id] = on
	}
}

/**
 * Keep an entry's forced value per column, when it carries one.
 *
 * @param {object} run The values being read, with the columns they sit in.
 * @param {{id: string, entry: object}} row The entry and its id.
 */
function keepForced(run, row) {
	const forced = row.entry?.forced
	if (forced !== undefined && forced !== null) {
		run.values.forcedValues[row.id] = forcedPerColumn(run, forced)
	}
}

/**
 * Keep an entry's refusal per column, when it carries one.
 *
 * @param {object} run The values being read, with the columns they sit in.
 * @param {{id: string, entry: object}} row The entry and its id.
 */
function keepRefused(run, row) {
	const refused = row.entry?.refused
	if (refused !== undefined && refused !== null) {
		run.values.refusals[row.id] = perChannel(run.columns, () => ({ reason: String(refused?.reason ?? '') }))
	}
}

/**
 * Whether one entry is on, per column: per named channel, or in the one column.
 *
 * @param {object} run The values being read, with the columns they sit in.
 * @param {object} entry One entry.
 * @return {object} `{ [channelId]: boolean }`.
 */
function onPerColumn(run, entry) {
	return run.channelIds.length > 0
		? perChannel(run.channelIds, (channel) => channelsOnFor(entry).includes(channel))
		: { [run.channelId]: entry?.enabled === true }
}

/**
 * A forced row's cell, per column.
 *
 * FORCED CAN FORCE A CHANNEL OFF. Reading a forced row as "on" would switch
 * on a channel an administrator forbade, so the value is taken from the row
 * and never assumed.
 *
 * @param {object} run The values being read, with the columns they sit in.
 * @param {object} forced The row's forced value.
 * @return {object} `{ [channelId]: { value, by, reason } }`.
 */
function forcedPerColumn(run, forced) {
	const cell = {
		value: forced?.value === true,
		by: String(forced?.by ?? ''),
		reason: String(forced?.reason ?? ''),
	}
	return perChannel(run.columns, () => ({ ...cell }))
}

/**
 * Everything the screen needs, from one read.
 *
 * @param {object} options - The call.
 * @param {Array<object>} [options.entries] - The platform's entries.
 * @param {Array<object>} [options.channels] - The platform's channels, if any.
 * @param {(entry: object) => string} [options.label] - What to call one entry.
 * @param {(title: string) => string} [options.groupLabel] - What to call a group.
 * @param {string} [options.singleChannelLabel] - The one column's name.
 * @param {(id: string) => string} [options.channelLabel] - What to call a channel column.
 *
 * @return {object} The component's props.
 * @spec openspec/specs/case-management/spec.md
 */
export function propsFor(options = {}) {
	const call = withDefaults(options, { entries: [], channels: [], channelLabel: (id) => id })
	const { entries, label, groupLabel, singleChannelLabel } = call
	const axis = channelAxisFrom(entries)
	const columns = channelsFrom({ channels: declaredChannels(axis, call), singleChannelLabel })
	const channelIds = axis.length > 0 ? columns.map((column) => column.id) : []

	return {
		events: eventsFrom({ entries, label, groupLabel, channelIds }),
		channels: columns,
		...valuesFrom({ entries, channelId: columns[0].id, channelIds }),
	}
}

/**
 * The channels to draw: the given ones, or, when the entries name an axis and
 * none are given, one per axis channel, labelled by `channelLabel`.
 *
 * @param {Array<string>} axis The channel ids the entries name.
 * @param {{channels: unknown, channelLabel: function(string): string}} call The call's channels and label.
 * @return {unknown} The channels for `channelsFrom`.
 */
function declaredChannels(axis, call) {
	if (axis.length > 0 && isFilledList(call.channels) === false) {
		return axis.map((id) => ({ id, label: call.channelLabel(id) }))
	}
	return call.channels
}

/**
 * The write behind one change on the screen.
 *
 * 🔴 THE ID IS SPLIT AT THE FIRST DASH, NOT THE LAST. A notification key may
 * contain dashes; a schema name is one word. Splitting at the last would move
 * half the key into the schema and write to a notification nobody has.
 *
 * There is no clear here. `CnNotificationMatrix` emits true or false and
 * has no third state, so the per-row "use the setting from my team" that
 * dossiq's own list carried has no equivalent yet. That loss is named in the
 * change rather than papered over with an unreachable branch: the store behind
 * the shared screen already accepts a null to clear, so the control belongs in
 * the component, and `clearPreference()` in the API service is waiting for it.
 *
 * @param {object} options - The call.
 * @param {string} options.eventId - The row's id, as `keyFor` made it.
 * @param {string} [options.scope] - The domain, or the empty string.
 * @param {boolean} options.value - The new value.
 * @param {string} [options.channelId] - The column the cell is in.
 * @param {?Array<string>} [options.current] - The row's channels that are on now, with several columns.
 *
 * @return {?object} `{ schema, notification, enabled, scope, channels? }`, or null when
 *   the id is not one this screen made.
 *
 * @spec openspec/specs/case-management/spec.md
 */
export function writeFor(options = {}) {
	const call = withDefaults(options, { scope: '', channelId: SINGLE_CHANNEL_ID, current: null })
	const write = notificationWrite(call.eventId, call.value)
	if (write === null) {
		return null
	}

	// One cell of several channels: the write is the row's whole channel set
	// with this one switched, and the row is on while any channel is.
	if (isChannelCell(call)) {
		Object.assign(write, switchedChannels({ channelId: call.channelId, current: call.current }, call.value))
	}

	if (call.scope !== '') {
		write.scope = `domain:${call.scope}`
	}

	return write
}

/**
 * Whether a write is for one cell of several channel columns, with the row's
 * current channels known.
 *
 * @param {{channelId: string, current: ?Array<string>}} call The write's column and current channels.
 * @return {boolean} True for a cell of a named channel with a current list.
 */
function isChannelCell(call) {
	return call.channelId !== SINGLE_CHANNEL_ID && Array.isArray(call.current) === true
}

/**
 * The write for one notification id switched on or off, null for an id
 * this file did not produce.
 *
 * @param {string} eventId The `schema-notification` id.
 * @param {boolean} value Whether the switch is on.
 * @return {object|null} `{ schema, notification, enabled }`.
 */
function notificationWrite(eventId, value) {
	const at = String(eventId ?? '').indexOf('-')
	if (at < 1) {
		// Not an id this file produced. Returning null rather than writing a
		// half-parsed row: a write to the wrong notification is silent and
		// permanent.
		return null
	}

	return {
		schema: String(eventId).slice(0, at),
		notification: String(eventId).slice(at + 1),
		enabled: value === true,
	}
}

/**
 * The row's channel set with one channel switched, and whether any is on.
 *
 * @param {{channelId: string, current: Array<string>}} cell The channel and the row's current set.
 * @param {boolean} value Whether the channel is switched on.
 * @return {{channels: Array<string>, enabled: boolean}} The channel fields of the write.
 */
function switchedChannels(cell, value) {
	const others = cell.current.filter((id) => id !== cell.channelId)
	const channels = value === true ? [...others, cell.channelId] : others
	return { channels, enabled: channels.length > 0 }
}
