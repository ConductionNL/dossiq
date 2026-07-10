// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Set webpack's runtime public path so dynamically-imported chunks load from
 * the directory Nextcloud actually serves the app's JS from — e.g.
 * `/custom_apps/procest/js/` in dev, `/apps/procest/js/` in a standard install.
 * Without this, webpack's default `'auto'` publicPath resolves lazy chunks
 * (e.g. the Leaflet bundle behind the map view, or the manifest validator) to a
 * path Nextcloud answers with the app-shell HTML, producing
 * "Refused to execute script (MIME type text/html)".
 *
 * `generateFilePath(app, type, file)` resolves against Nextcloud's per-app web
 * root (`OC.appswebroots`), so it is correct for both `apps/` and `custom_apps/`
 * layouts. This module MUST be imported before any `import()` that triggers
 * chunk loading, so it is the first import in the webpack entry (main.js).
 */

import { generateFilePath } from '@nextcloud/router'

// eslint-disable-next-line camelcase, no-undef
__webpack_public_path__ = generateFilePath('procest', 'js', '')
