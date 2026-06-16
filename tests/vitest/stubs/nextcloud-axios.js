/**
 * SPDX-FileCopyrightText: 2026 Conduction / Procest Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Lightweight stub for @nextcloud/axios used by the Vitest unit suite.
 *
 * The real package is a thin wrapper around axios that injects the Nextcloud
 * CSRF token and base URL from the browser runtime — neither exists under
 * Vitest's node environment. Consumers (pdokService, casesOnMapApi, …) use
 * `axios.get` / `axios.post`, so the stub exposes both as `vi.fn()` that tests
 * replace via `axios.get.mockImplementation(...)` / `axios.post.mock...`.
 */

import { vi } from 'vitest'

const axios = {
	get: vi.fn(),
	post: vi.fn(),
}

export default axios
