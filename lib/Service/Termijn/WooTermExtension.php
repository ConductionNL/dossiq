<?php

/**
 * Dossiq Woo term extension.
 *
 * Woo art. 4.4 lid 2 allows a decision term one extension of at most two
 * weeks. The extension moves the case's statutory term instance through the
 * one extension path, which rolls the date and holds the ceiling, and a second
 * request is refused with a 409 (REQ-WTR-003).
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Termijn
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
 * @spec openspec/specs/woo-case-type/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Termijn;

use InvalidArgumentException;
use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Exception\ExtensionCeilingReachedException;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\DeadlineExtensionService;
use OCA\Dossiq\Service\TermDeclarationReader;
use OCA\Dossiq\Service\TermijnService;
use OCA\Dossiq\Service\TermKind;
use Psr\Log\LoggerInterface;

/**
 * Extend a case's Woo term once, through the term engine.
 *
 * @spec openspec/specs/woo-case-type/spec.md
 */
class WooTermExtension {
	/**
	 * WOO extension period in days (WOO Art. 4.4 verdaging).
	 */
	private const EXTENSION_PERIOD_DAYS = 14;

	/**
	 * Which kind a term instance is.
	 *
	 * @var TermKindClassifier
	 */
	private readonly TermKindClassifier $kinds;

	/**
	 * Constructor.
	 *
	 * @param TermijnService             $termService  The case's term instances.
	 * @param DeadlineExtensionService   $extension    The one extension path, which rolls
	 *        the date and holds the ceiling (REQ-WTR-003).
	 * @param LoggerInterface            $logger       Logger.
	 * @param TermDeclarationReader|null $declarations The case type's `extensionPeriod`.
	 * @param TermKindClassifier|null    $kinds        Which kind a term is; the rule's own class when absent.
	 * @param ExtensionNotice|null       $notice       Tells the requester, with the reason and the new end.
	 *        Absent, the answer says the requester was not told.
	 */
	public function __construct(
		private readonly TermijnService $termService,
		private readonly DeadlineExtensionService $extension,
		private readonly LoggerInterface $logger,
		private readonly ?TermDeclarationReader $declarations = null,
		?TermKindClassifier $kinds = null,
		private readonly ?ExtensionNotice $notice = null,
	) {
		$this->kinds = ($kinds ?? new TermKindClassifier());
	}//end __construct()

	/**
	 * Extend the Woo decision term of a case once, through the term engine.
	 *
	 * Woo art. 4.4 lid 2 allows one extension of at most two weeks. The new
	 * end is the term instance's current end plus the case type's
	 * `extensionPeriod` (P14D for the Woo case type), handed to
	 * `DeadlineExtensionService::requestExtension()`, which rolls it by the
	 * Algemene termijnenwet, enforces the definition's ceiling and records the
	 * reason as the `verleng` event's rationale. The case `deadline` follows
	 * the instance through the term write path. Nothing is written onto the
	 * case here: the keys this used to write (`expectedResolution`,
	 * `deadlineVerlengd`, `verdagingReden`) are not declared on the case
	 * schema, so the one-extension cap that read them back did not hold
	 * (REQ-WTR-003).
	 *
	 * @param string $caseId The case UUID
	 * @param string $reason Mandatory reason for the extension
	 *
	 * @return array<string, mixed> The extension and whether the requester was told (noticeStatus, noticeChannel,
	 *         noticeReasonCode, noticeReason).
	 *
	 * @throws \InvalidArgumentException If the reason is empty
	 * @throws RefusedException When the case has no statutory term or its extension is used up (409)
	 * @throws \RuntimeException When the term engine is not available
	 *
	 * @spec openspec/specs/woo-case-type/spec.md
	 * @spec openspec/changes/woo-requester-notices-really-go-out/specs/burger-notifications/spec.md#requirement-an-extension-reaches-the-requester-with-its-reason-req-wrn-005
	 */
	public function extend(string $caseId, string $reason): array {
		if (trim($reason) === '') {
			throw new InvalidArgumentException('A reason is required for deadline extension');
		}

		$instance = $this->statutoryInstance(caseId: $caseId);
		if ($instance === null) {
			throw new RefusedException(
				rule: 'woo-term-missing',
				sentence: 'This case has no statutory term to extend.',
				status: RefusedException::STATUS_REFUSED,
			);
		}

		$previous = substr((string)($instance['endDateCurrent'] ?? ''), 0, 10);

		try {
			$updated = $this->extension->requestExtensionByDays(
				termInstanceId: (string)($instance['id'] ?? ''),
				rationale: $reason,
				fromDate: $previous,
				days: $this->extensionPeriodDays(caseId: $caseId)
			);
		} catch (ExtensionCeilingReachedException $e) {
			throw new RefusedException(
				rule: 'woo-one-extension',
				sentence: 'This term was already extended. Woo art. 4.4 lid 2 allows one extension of at most two weeks.',
				status: RefusedException::STATUS_REFUSED,
				previous: $e,
			);
		}

		$deadline = substr((string)($updated['endDateCurrent'] ?? ''), 0, 10);

		$this->logger->info(
			'WOO deadline extended for case ' . $caseId . ' to ' . $deadline,
			['app' => Application::APP_ID],
		);

		$extended = [
			'caseId' => $caseId,
			'previousDeadline' => $previous,
			'deadline' => $deadline,
			'extensionReason' => $reason,
			'countExtensions' => (int)($updated['countExtensions'] ?? 0),
			'termInstanceId' => (string)($instance['id'] ?? ''),
		];

		// The extension stands whatever becomes of the notice: the answer and
		// the case say whether the requester was told (REQ-WRN-005).
		return $extended + $this->tell(extended: $extended);
	}//end extend()

	/**
	 * Tell the requester about the extension, or say why nobody did.
	 *
	 * @param array<string, mixed> $extended The extension.
	 *
	 * @return array{noticeStatus: string, noticeChannel: string, noticeReasonCode: string, noticeReason: string}
	 *
	 * @spec openspec/changes/woo-requester-notices-really-go-out/specs/burger-notifications/spec.md#requirement-an-extension-reaches-the-requester-with-its-reason-req-wrn-005
	 */
	private function tell(array $extended): array {
		if ($this->notice === null) {
			return [
				'noticeStatus' => 'not-sent',
				'noticeChannel' => '',
				'noticeReasonCode' => 'notice-not-wired',
				'noticeReason' => 'No sender is wired, so the requester was not told.',
			];
		}

		return $this->notice->tell(
			caseId: (string)$extended['caseId'],
			instanceId: (string)$extended['termInstanceId'],
			reason: (string)$extended['extensionReason'],
			newEnd: (string)$extended['deadline'],
		);
	}//end tell()

	/**
	 * The case's statutory term instance, newest first.
	 *
	 * @param string $caseId The case UUID
	 *
	 * @return array<string, mixed>|null The instance, or null when the case has none.
	 */
	private function statutoryInstance(string $caseId): ?array {
		foreach ($this->termService->instancesForCase(caseId: $caseId) as $instance) {
			if (is_array($instance) === true && $this->kinds->ofInstance(instance: $instance) === TermKind::STATUTORY) {
				return $instance;
			}
		}

		return null;
	}//end statutoryInstance()

	/**
	 * The extension the case type declares, in days; the Woo two weeks when it declares none.
	 *
	 * @param string $caseId The case UUID
	 *
	 * @return int The extension period in days.
	 */
	private function extensionPeriodDays(string $caseId): int {
		$declared = (int)($this->declarations?->forCase(caseId: $caseId)['extensionPeriodDays'] ?? 0);
		if ($declared > 0) {
			return $declared;
		}

		return self::EXTENSION_PERIOD_DAYS;
	}//end extensionPeriodDays()
}//end class
