/**
 * SPDX-FileCopyrightText: 2026 Conduction / Procest Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Lightweight stub for @nextcloud/axios used by the Vitest unit suite.
 *
 * The real package is a thin wrapper around axios that injects the Nextcloud
 * CSRF token and base URL from the browser runtime — neither exists under
 * Vitest's node environment. The pdokService shim only uses `axios.get`, so
 * the stub exposes a `get` that tests replace with `vi.fn()` mock
 * implementations via `axios.get.mockImplementation(...)`.
 */

import { vi } from 'vitest'

const axios = {
	get: vi.fn(),
}

export default axios
