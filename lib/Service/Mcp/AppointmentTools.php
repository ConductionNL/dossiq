<?php

/**
 * Curated tools an assistant may call on an appointment for a case.
 *
 * Booking and cancelling reach the resident, so both tools declare `external`
 * reach. Each runs the case check AppointmentController runs and then calls
 * AppointmentService, the controller's own path.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Mcp
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
 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-integration/spec.md#requirement-req-mcp-204-guard-enforcing-write-tools-one-per-action-on-the-owning-service
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Mcp;

use OCA\Dossiq\Service\AppointmentService;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\OpenRegister\Mcp\Attribute\McpTool;
use OCP\IUserSession;

/**
 * Tools on appointments: book and cancel.
 *
 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-integration/spec.md#requirement-req-mcp-204-guard-enforcing-write-tools-one-per-action-on-the-owning-service
 */
class AppointmentTools {

	use ToolEnvelopes;

	/**
	 * Constructor.
	 *
	 * @param IUserSession       $userSession  The caller's session.
	 * @param CaseAccessGuard    $caseAccess   Answers whether the caller may change the case.
	 * @param AppointmentService $appointments Owns booking and cancelling.
	 */
	public function __construct(
		private readonly IUserSession $userSession,
		private readonly CaseAccessGuard $caseAccess,
		private readonly AppointmentService $appointments,
	) {
	}//end __construct()

	/**
	 * Book an appointment for a case.
	 *
	 * @param string $caseId       The case UUID.
	 * @param string $productId    The appointment product.
	 * @param string $locationId   The location.
	 * @param string $dateTime     The start, ISO 8601 with offset.
	 * @param int    $duration     Minutes.
	 * @param string $citizenName  The resident's name.
	 * @param string $citizenEmail The resident's e-mail address.
	 * @param string $citizenPhone The resident's phone number.
	 * @param string $notes        Notes for the appointment.
	 *
	 * @return array<string, mixed> The booked appointment, or an error envelope.
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) The appointment fields the controller takes, one scalar each, so the scanner can describe them.
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-integration/spec.md#requirement-req-mcp-204-guard-enforcing-write-tools-one-per-action-on-the-owning-service
	 */
	#[McpTool(
		name: 'scheduleAppointment',
		description: 'Book an appointment with the resident for a case, in a free slot of a product at a location. The resident is told.',
		readOnlyHint: false,
		destructiveHint: false,
		scope: 'create',
		reach: 'external',
		subject: 'appointment',
		action: 'schedule'
	)]
	public function scheduleAppointment(
		string $caseId,
		string $productId,
		string $locationId,
		string $dateTime,
		int $duration = 30,
		string $citizenName = '',
		string $citizenEmail = '',
		string $citizenPhone = '',
		string $notes = '',
	): array {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return $this->error(code: 'not_authenticated', message: 'No user session.');
		}

		// AppointmentController::create() runs the same check: a booking
		// writes onto the case and stores the resident's contact details.
		if ($this->caseAccess->hasCaseMutationAccess(caseId: $caseId, user: $user) === false) {
			return $this->error(code: 'forbidden', message: 'You may not change this case.');
		}

		if (trim($productId) === '' || trim($locationId) === '' || trim($dateTime) === '') {
			return $this->error(code: 'slot_required', message: 'Name the product, the location and the time.');
		}

		$result = $this->appointments->bookAppointment(
			$caseId,
			[
				'productId' => $productId,
				'locationId' => $locationId,
				'dateTime' => $dateTime,
				'duration' => $duration,
				'citizenName' => $citizenName,
				'citizenEmail' => $citizenEmail,
				'citizenPhone' => $this->orNull(value: $citizenPhone),
				'notes' => $this->orNull(value: $notes),
			]
		);

		return $this->answer(result: $result);
	}//end scheduleAppointment()

	/**
	 * Cancel an appointment.
	 *
	 * @param string $appointmentId The appointment id.
	 *
	 * @return array<string, mixed> The cancelled appointment, or an error envelope.
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-integration/spec.md#requirement-req-mcp-204-guard-enforcing-write-tools-one-per-action-on-the-owning-service
	 */
	#[McpTool(
		name: 'cancelAppointment',
		description: 'Cancel an appointment booked for a case. The resident is told.',
		readOnlyHint: false,
		destructiveHint: false,
		scope: 'update',
		reach: 'external',
		subject: 'appointment',
		action: 'cancel'
	)]
	public function cancelAppointment(string $appointmentId): array {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return $this->error(code: 'not_authenticated', message: 'No user session.');
		}

		// AppointmentController::mayMutateAppointment(): an appointment that
		// resolves to no case is refused, never treated as free to change.
		$caseId = $this->appointments->getCaseIdForAppointment($appointmentId);
		if ($caseId === null || $this->caseAccess->hasCaseMutationAccess(caseId: $caseId, user: $user) === false) {
			return $this->error(code: 'forbidden', message: 'You may not change this appointment.');
		}

		return $this->answer(result: $this->appointments->cancelAppointment($appointmentId));
	}//end cancelAppointment()

	/**
	 * The controller's success shape, or an envelope for the service's error payload.
	 *
	 * @param array<string, mixed> $result The service result.
	 *
	 * @return array<string, mixed>
	 */
	private function answer(array $result): array {
		if (isset($result['error']) === true) {
			return $this->error(code: 'refused', message: (string)$result['error']);
		}

		return ['success' => true, 'appointment' => $result];
	}//end answer()

	/**
	 * An optional argument as the controller passes it: null when empty.
	 *
	 * @param string $value The argument.
	 *
	 * @return string|null
	 */
	private function orNull(string $value): ?string {
		if (trim($value) === '') {
			return null;
		}

		return $value;
	}//end orNull()
}//end class
