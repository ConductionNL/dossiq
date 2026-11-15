<?php

/**
 * Dossiq AnswersSignedOutCallers support trait.
 *
 * The translated 401 several case controllers answer a caller without a
 * session. The using class provides `$l10n` (IL10N).
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec exclude shared 401 of several case controllers, covered by their own specs
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller\Support;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;

/**
 * The 401 "You are not signed in.", translated.
 *
 * @spec exclude shared 401 of several case controllers, covered by their own specs
 */
trait AnswersSignedOutCallers {

	/**
	 * The 401 for a caller without a session.
	 *
	 * @return JSONResponse
	 */
	private function notSignedIn(): JSONResponse {
		return new JSONResponse(
			['error' => $this->l10n->t('You are not signed in.')],
			Http::STATUS_UNAUTHORIZED
		);
	}//end notSignedIn()
}//end trait
