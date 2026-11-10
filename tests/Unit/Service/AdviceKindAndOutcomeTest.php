<?php

/**
 * AdviceService: the kind and subject of a request, and the outcome of a received advice.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\Advice\AdviceAuthorizationGuard;
use OCA\Dossiq\Service\Advice\AdviceNotifier;
use OCA\Dossiq\Service\Advice\AdviceRepository;
use OCA\Dossiq\Service\AdviceDelegationService;
use OCA\Dossiq\Service\AdviceService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\MakesCaseDateNormaliser;
use OCA\OpenRegister\Service\Object\ObjectSaveOptions;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * An object store double for the advice writes.
 */
interface AdviceKindObjectStoreStub {
	/**
	 * Save one object.
	 *
	 * @param array<string, mixed> $object   The object.
	 * @param string               $register The register.
	 * @param string               $schema   The schema.
	 * @param string|null          $uuid     The uuid when updating.
	 *
	 * @return array<string, mixed>
	 */
	public function saveObject(array $object,ObjectSaveOptions $options = new ObjectSaveOptions()): array;
}//end interface

/**
 * @covers \OCA\Dossiq\Service\AdviceService
 */
class AdviceKindAndOutcomeTest extends TestCase {
	use MakesCaseDateNormaliser;

	/**
	 * The notifier mock.
	 *
	 * @var AdviceNotifier|MockObject
	 */
	private AdviceNotifier $notifier;

	/**
	 * The repository mock.
	 *
	 * @var AdviceRepository|MockObject
	 */
	private AdviceRepository $repository;

	/**
	 * What the store was asked to save, by schema.
	 *
	 * @var array<int, array{schema: string, object: array<string, mixed>}>
	 */
	private array $saved = [];

	/**
	 * Build the service.
	 *
	 * @return AdviceService
	 */
	private function service(): AdviceService {
		$store = $this->createMock(AdviceKindObjectStoreStub::class);
		$store->method('saveObject')->willReturnCallback(
			function (array $object,ObjectSaveOptions $options = new ObjectSaveOptions()): array {
				$schema = $options->target->schema;
				$this->saved[] = ['schema' => $schema, 'object' => $object];

				return $object + ['id' => 'advice-1'];
			}
		);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($store);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => ['register' => 'dossiq', 'advies_aanvraag_schema' => 'adviesAanvraag'][$key] ?? ''
		);
		$delegation = $this->createMock(AdviceDelegationService::class);
		$delegation->method('raiseAdviceDecision')->willReturn('decision-1');
		$this->notifier   = $this->createMock(AdviceNotifier::class);
		$this->repository = $this->createMock(AdviceRepository::class);

		return new AdviceService(
			settingsService: $settings,
			userSession: $this->createMock(IUserSession::class),
			logger: $this->createMock(LoggerInterface::class),
			adviceDelegation: $delegation,
			repository: $this->repository,
			guard: $this->createMock(AdviceAuthorizationGuard::class),
			notifier: $this->notifier,
			dates: $this->caseDates(),
		);
	}//end service()

	/**
	 * An external request stores its kind and subject and notifies nobody: the advisor is an organisation.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dialogs-answer-what-the-boards-draw/specs/advice-management/spec.md#requirement-an-advice-request-keeps-its-kind-subject-and-outcome
	 */
	public function testAnExternalRequestKeepsItsKindAndNotifiesNobody(): void {
		$service = $this->service();
		$this->notifier->expects($this->never())->method('notifyAdviseur');

		$service->requestAdvice(
			caseId: 'case-1',
			data: ['kind' => 'external', 'advisor' => 'Veiligheidsregio Zuid', 'subject' => 'Brandveiligheid', 'deadline' => '2026-11-20', 'question' => 'Is de vluchtroute voldoende?'],
			requestedBy: 'pieter'
		);

		self::assertSame('adviesAanvraag', $this->saved[0]['schema']);
		self::assertSame('extern', $this->saved[0]['object']['type']);
		self::assertSame('Brandveiligheid', $this->saved[0]['object']['subject']);
		self::assertSame('Veiligheidsregio Zuid', $this->saved[0]['object']['advisor']);
		self::assertSame('case-1', $this->saved[0]['object']['case']);
		self::assertSame('requested', $this->saved[0]['object']['status']);
	}//end testAnExternalRequestKeepsItsKindAndNotifiesNobody()

	/**
	 * An internal request notifies its advisor.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dialogs-answer-what-the-boards-draw/specs/advice-management/spec.md#requirement-an-advice-request-keeps-its-kind-subject-and-outcome
	 */
	public function testAnInternalRequestNotifiesItsAdvisor(): void {
		$service = $this->service();
		$this->notifier->expects($this->once())->method('notifyAdviseur');

		$service->requestAdvice(caseId: 'case-1', data: ['kind' => 'internal', 'advisor' => 'hanna'], requestedBy: 'pieter');

		self::assertSame('intern', $this->saved[0]['object']['type']);
	}//end testAnInternalRequestNotifiesItsAdvisor()

	/**
	 * Receiving stores the conclusion, the text and the conditions, and the notification carries the summary.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dialogs-answer-what-the-boards-draw/specs/advice-management/spec.md#requirement-an-advice-request-keeps-its-kind-subject-and-outcome
	 */
	public function testReceivingStoresTheOutcome(): void {
		$service = $this->service();
		$this->repository->method('find')->willReturn(['id' => 'advice-1', 'status' => 'requested']);
		$this->repository->expects($this->once())->method('save')
			->with(
				$this->callback(
					static fn (array $update): bool => $update['status'] === 'received'
						&& $update['conclusion'] === 'positive-with-conditions'
						&& $update['adviceText'] === 'Akkoord met voorwaarden.'
						&& $update['conditionsChecked'] === ['Vluchtroute vrij']
				),
				'advice-1'
			)
			->willReturn(['id' => 'advice-1']);
		$this->notifier->expects($this->once())->method('fireTransitionNotification')
			->with('received', $this->anything(), 'advice-1', $this->anything(), 'positive-with-conditions: Akkoord met voorwaarden.');

		$service->transitionStatus(
			adviceId: 'advice-1',
			to: 'received',
			payload: ['conclusion' => 'positive-with-conditions', 'adviceText' => 'Akkoord met voorwaarden.', 'conditionsChecked' => ['Vluchtroute vrij', 42]]
		);
	}//end testReceivingStoresTheOutcome()
}//end class
