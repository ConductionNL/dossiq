/**
 * SPDX-FileCopyrightText: 2026 Conduction / Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Lightweight stub for @nextcloud/axios used by the Vitest unit suite.
 *
 * The real package is a thin wrapper around axios that injects the Nextcloud
 * CSRF token and base URL from the browser runtime — neither exists under
 * Vitest's node environment. Consumers (pdokService, casesOnMapApi, …) use
 * `axios.get` / `axios.post` / `axios.delete` / `axios.request`, so the stub
 * exposes each as `vi.fn()` that tests replace via
 * `axios.get.mockImplementation(...)` / `axios.request.mock...`.
 */

import { vi } from 'vitest'

const axios = {
	get: vi.fn(),
	post: vi.fn(),
	delete: vi.fn(),
	// The engine's checklist toggle is a PATCH with the flag in the query
	// string (`task#checkItem`), which has no POST equivalent.
	patch: vi.fn(),
	// `request` carries the WebDAV verbs: the version panel PROPFINDs the
	// versions endpoint and a restore MOVEs onto it, and neither has an axios
	// convenience method.
	request: vi.fn(),
}

export default axios
