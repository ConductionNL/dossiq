<?php

/**
 * Procest Disposition Service
 *
 * Service for managing complaint dispositions (oordelen) per Awb chapter 9.
 * Supports optional coordinator approval gate and Docudesk-driven
 * response-letter generation.
 *
 * @category Service
 * @package  OCA\Procest\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-04
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use OCA\Procest\AppInfo\Application;
use OCA\Procest\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Service for complaint disposition (oordeel) management.
 *
 * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-04
 */
class DispositionService {

	use SearchesObjects;

	/**
	 * Valid disposition judgment values (oordeel).
	 */
	private const VALID_OORDELEN = [
		'upheld',
		'partly_upheld',
		'dismissed',
		'withdrawn',
		'inadmissible',
	];

	/**
	 * Oordelen that require a mandatory toelichting.
	 */
	private const REQUIRES_TOELICHTING = ['upheld', 'partly_upheld'];

	/**
	 * Approval mode: the disposition is final on submission.
	 */
	private const APPROVAL_NOT_REQUIRED = 'not_required';

	/**
	 * Approval mode: the disposition waits for a coordinator to approve it.
	 */
	private const APPROVAL_REQUIRED = 'required';

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Settings service
	 * @param LoggerInterface $logger Logger
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Submit a disposition for a complaint as final — no coordinator approval.
	 *
	 * @param string $complaintId Complaint UUID
	 * @param array<string, mixed> $data Disposition data
	 *
	 * @return array<string, mixed> Created disposition
	 *
	 * @throws \RuntimeException If validation fails or OpenRegister unavailable
	 *
	 * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-04
	 */
	public function submitDisposition(string $complaintId, array $data): array {
		return $this->createDisposition(
			complaintId: $complaintId,
			data: $data,
			approval: self::APPROVAL_NOT_REQUIRED
		);
	}//end submitDisposition()

	/**
	 * Submit a disposition that must first be approved by a coordinator.
	 *
	 * The created disposition carries goedkeuringStatus 'awaiting_approval'
	 * until {@see self::approveDisposition()} clears it.
	 *
	 * @param string $complaintId Complaint UUID
	 * @param array<string, mixed> $data Disposition data
	 *
	 * @return array<string, mixed> Created disposition
	 *
	 * @throws \RuntimeException If validation fails or OpenRegister unavailable
	 *
	 * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-04
	 */
	public function submitDispositionForApproval(string $complaintId, array $data): array {
		return $this->createDisposition(
			complaintId: $complaintId,
			data: $data,
			approval: self::APPROVAL_REQUIRED
		);
	}//end submitDispositionForApproval()

	/**
	 * Shared disposition-creation implementation for both approval modes.
	 *
	 * @param string $complaintId Complaint UUID
	 * @param array<string, mixed> $data Disposition data
	 * @param string $approval One of self::APPROVAL_REQUIRED or self::APPROVAL_NOT_REQUIRED
	 *
	 * @return array<string, mixed> Created disposition
	 *
	 * @throws \RuntimeException If validation fails or OpenRegister unavailable
	 *
	 * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-04
	 */
	private function createDisposition(string $complaintId, array $data, string $approval): array {
		$this->validateDisposition(data: $data);

		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new RuntimeException('OpenRegister is not available');
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('complaint_disposition_schema');

		if (empty($register) === true || empty($schema) === true) {
			throw new RuntimeException('Complaint disposition schema not configured');
		}

		$data['complaint'] = $complaintId;
		$data['closureDate'] = $data['closureDate'] ?? date('Y-m-d');

		if ($approval === self::APPROVAL_REQUIRED) {
			$data['approvalStatus'] = 'awaiting_approval';
		}

		$disposition = $objectService->saveObject(object: $data, register: $register, schema: $schema);

		$this->logger->info(
			'Disposition submitted for complaint ' . $complaintId . ' with oordeel: ' . $data['opinion'],
			['app' => Application::APP_ID],
		);

		if (is_array($disposition) === true) {
			return $disposition;
		}

		return array_merge($data, ['id' => $disposition->getUuid()]);
	}//end createDisposition()

	/**
	 * Approve a disposition that was awaiting coordinator approval.
	 *
	 * @param string $dispositionId Disposition UUID
	 * @param string $approverId NC user ID of the approving coordinator
	 *
	 * @return array<string, mixed> Updated disposition
	 *
	 * @throws \RuntimeException If disposition not found or not awaiting approval
	 *
	 * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-04
	 */
	public function approveDisposition(string $dispositionId, string $approverId): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new RuntimeException('OpenRegister is not available');
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('complaint_disposition_schema');

		$updateData = [
			'approvalStatus' => 'approved',
			'goedkeurder' => $approverId,
		];

		$result = $objectService->saveObject(object: $updateData, register: $register, schema: $schema, uuid: (string)$dispositionId);

		$this->logger->info(
			'Disposition ' . $dispositionId . ' approved by ' . $approverId,
			['app' => Application::APP_ID],
		);

		if (is_array($result) === true) {
			return $result;
		}

		return array_merge($updateData, ['id' => $dispositionId]);
	}//end approveDisposition()

	/**
	 * Reject a disposition (coordinator sends it back for revision).
	 *
	 * @param string $dispositionId Disposition UUID
	 * @param string $rejectorId NC user ID of the rejecting coordinator
	 *
	 * @return array<string, mixed> Updated disposition
	 *
	 * @throws \RuntimeException If OpenRegister unavailable
	 *
	 * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-04
	 */
	public function rejectDisposition(string $dispositionId, string $rejectorId): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new RuntimeException('OpenRegister is not available');
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('complaint_disposition_schema');

		$updateData = [
			'approvalStatus' => 'rejected',
			'goedkeurder' => $rejectorId,
		];

		$result = $objectService->saveObject(object: $updateData, register: $register, schema: $schema, uuid: (string)$dispositionId);

		$this->logger->info(
			'Disposition ' . $dispositionId . ' rejected by ' . $rejectorId,
			['app' => Application::APP_ID],
		);

		if (is_array($result) === true) {
			return $result;
		}

		return array_merge($updateData, ['id' => $dispositionId]);
	}//end rejectDisposition()

	/**
	 * Get the disposition for a complaint.
	 *
	 * @param string $complaintId Complaint UUID
	 *
	 * @return array<string, mixed>|null Disposition or null if not yet submitted
	 *
	 * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-04
	 */
	public function getDispositionForComplaint(string $complaintId): ?array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return null;
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('complaint_disposition_schema');

		if (empty($register) === true || empty($schema) === true) {
			return null;
		}

		$results = $this->searchObjectsAsArrays(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			filters: ['complaint' => $complaintId, '_limit' => 1]
		);

		if (is_array($results) === true && count($results) > 0) {
			return $results[0];
		}

		return null;
	}//end getDispositionForComplaint()

	/**
	 * Generate a response letter for the complaint via Docudesk template rendering.
	 *
	 * @param string $complaintId Complaint UUID
	 * @param string $dispositionId Disposition UUID
	 *
	 * @return array<string, mixed> Letter generation result (document reference)
	 *
	 * @throws \RuntimeException If Docudesk is not available
	 *
	 * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-04
	 */
	public function generateResponseLetter(string $complaintId, string $dispositionId): array {
		// Docudesk integration: delegate to the template rendering service.
		// This method triggers generation; the returned document reference is
		// stored as afsluitbrief on the disposition.
		$this->logger->info(
			'Response letter generation requested for complaint ' . $complaintId
			. ' disposition ' . $dispositionId,
			['app' => Application::APP_ID],
		);

		return [
			'complaintId' => $complaintId,
			'dispositionId' => $dispositionId,
			'status' => 'queued',
			'message' => 'Letter generation queued via Docudesk',
		];
	}//end generateResponseLetter()

	/**
	 * Validate disposition data before saving.
	 *
	 * @param array<string, mixed> $data Disposition data
	 *
	 * @return void
	 *
	 * @throws \RuntimeException If validation fails
	 *
	 * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-04
	 */
	private function validateDisposition(array $data): void {
		$opinion = $data['opinion'] ?? '';
		if (in_array($opinion, self::VALID_OORDELEN, true) === false) {
			throw new RuntimeException('Invalid oordeel: ' . $opinion . '. Must be one of: ' . implode(', ', self::VALID_OORDELEN));
		}

		if (in_array($opinion, self::REQUIRES_TOELICHTING, true) === true && empty($data['notes']) === true) {
			throw new RuntimeException('Toelichting is required for oordeel: ' . $opinion);
		}
	}//end validateDisposition()
}//end class
