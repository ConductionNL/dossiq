<?php

/**
 * Contract of Notifier
 *
 * The public surface of Notifier that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Dossiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/dossiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Kernel\Contracts;

/**
 * Contract of Notifier.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface NotifierInterface {

	/**
	 * The subject key CaseReassignmentService dispatches for a transfer digest.
	 */
	public const SUBJECT_CASES_REASSIGNED = 'cases_reassigned';

	/**
	 * A case's statutory term reached a rung its term definition tells the handler about.
	 */
	public const SUBJECT_TERM_NEARING = 'term_nearing';

	/**
	 * A case's statutory term ran out, and its term definition tells the handler about that.
	 */
	public const SUBJECT_TERM_EXCEEDED = 'term_exceeded';

	/**
	 * A StUF endpoint was taken out of service by the circuit breaker.
	 */
	public const SUBJECT_STUF_CIRCUIT_OPEN = 'stuf_circuit_open';

	/**
	 * A StUF message went unanswered.
	 */
	public const SUBJECT_STUF_TIMEOUT = 'stuf_timeout';

	/**
	 * A StUF message came back refused.
	 */
	public const SUBJECT_STUF_PERMANENT_ERROR = 'stuf_permanent_error';

	/**
	 * The applicant changed, added to or answered on their case in the portal.
	 */
	public const SUBJECT_APPLICANT_RESPONDED = 'applicant_responded';

	/**
	 * The applicant withdrew their case in the portal.
	 */
	public const SUBJECT_APPLICANT_WITHDREW = 'applicant_withdrew';

	/**
	 * The background jobs have no usable service account, so they wrote nothing.
	 */
	public const SUBJECT_BACKGROUND_ACCOUNT_MISSING = 'background_service_account_missing';

	/**
	 * The subject key QuotaGate dispatches when a tenant crosses its soft limit.
	 */
	public const SUBJECT_QUOTA_SOFT_LIMIT = 'tenant_quota_soft_limit';
}//end interface
