// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The Integrations page's Add integration header action, registered in
// registry.js as a `kind: 'handler'` entry.
//
// @spec openspec/changes/adopt-connection-registry/specs/admin-settings/spec.md

import { generateUrl } from '@nextcloud/router'

/**
 * Where Add integration lands: integriq's overview, preset and linking.
 */
export const INTEGRIQ_CONNECTIONS_PATH =
	'/apps/integriq/connections?app=dossiq&link=1'

/**
 * The Integrations page's Add integration header action.
 *
 * A connection row is integriq's, and a source is linked to it on integriq's
 * Connections overview (hydra connection-registry D9). `link=1` opens the
 * link-a-source dialog there, pre-filtered to dossiq's connections.
 *
 * A FUNCTION handler because a header action's `navigate` keyword only pushes
 * a route name inside this app's router, which cannot leave the app.
 *
 * The route is the one hydra connection-registry D9 names.
 *
 * @return {void}
 *
 * @spec openspec/changes/adopt-connection-registry/specs/admin-settings/spec.md
 */
export function openIntegriqConnections() {
	window.location.assign(generateUrl(INTEGRIQ_CONNECTIONS_PATH))
}
