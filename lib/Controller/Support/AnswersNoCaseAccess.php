<?php

/**
 * Dossiq AnswersNoCaseAccess support trait.
 *
 * The translated 403 for a caller without access to the case. The using class provides `$l10n` (IL10N).
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
 * @spec exclude shared plumbing of several controller classes, covered by their own specs
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller\Support;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;

/**
 * The translated 403 for a caller without access to the case.
 *
 * @spec exclude shared plumbing of several controller classes, covered by their own specs
 */
trait AnswersNoCaseAccess {

	/**
	 * The refusal of a user without access to the case.
	 *
	 * @return JSONResponse The refusal.
	 */
	private function noAccess(): JSONResponse {
		return new JSONResponse(
			['error' => 'no-case-access', 'message' => $this->l10n->t('You do not have access to this case')],
			Http::STATUS_FORBIDDEN
		);
	}//end noAccess()
}//end trait
