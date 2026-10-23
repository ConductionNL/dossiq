<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The listener runs a case type's intake on a received case before it is
 * stored, leaves a draft alone, and queues the dossier link once the case
 * exists.
 *
 * @spec openspec/changes/case-intake-per-case-type/specs/case-intake-destination/spec.md#requirement-a-case-type-may-say-what-its-intake-does-to-a-received-case
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Listener\CaseTypeIntakeListener;
use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\Intake\CollectionLinkQueue;
use OCA\Dossiq\Service\SettingsService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Listener\CaseTypeIntakeListener
 * @uses \OCA\Dossiq\Service\Intake\CaseTypeIntake
 * @uses \OCA\Dossiq\Service\Intake\CaseTypeProperties
 * @uses \OCA\Dossiq\Service\Intake\ReceiptMoment
 */
class CaseTypeIntakeListenerTest extends TestCase {

	private const CASE_SCHEMA = 'case-schema-id';

	/**
	 * Links queued: register, case, dossier, subject.
	 *
	 * @var array<int, array<int, string>>
	 */
	private array $queued = [];

	/**
	 * The listener over a Woo case type with an intake.
	 *
	 * @return CaseTypeIntakeListener
	 */
	private function listener(): CaseTypeIntakeListener {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => ['case_schema' => self::CASE_SCHEMA, 'register' => 'dossiq'][$key] ?? ''
		);
		$settings->method('getObjectService')->willReturn(null);

		$types = $this->createMock(originalClassName: CaseTypeResolver::class);
		$types->method('effectiveCaseType')->willReturnCallback(
			static function (string $caseTypeId): array {
				if ($caseTypeId !== 'ct-woo') {
					return ['title' => 'Bezwaar'];
				}

				return ['portalIntake' => ['answersAt' => 'wooRequest', 'origin' => 'portal', 'party' => true, 'dossierAnswer' => 'collectionId']];
			}
		);

		$queue = $this->createMock(originalClassName: CollectionLinkQueue::class);
		$queue->method('queue')->willReturnCallback(
			function (string $register, string $caseId, string $collectionId, string $subjectRef): void {
				$this->queued[] = [$register, $caseId, $collectionId, $subjectRef];
			}
		);

		return new CaseTypeIntakeListener(
			settingsService: $settings,
			caseTypeResolver: $types,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			linkQueue: $queue
		);
	}//end listener()

	/**
	 * A case entity.
	 *
	 * @param array<string, mixed> $payload The case fields.
	 * @param string|null          $status  Its status.
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $payload, ?string $status = null): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setObject($payload);
		$entity->setSchema(self::CASE_SCHEMA);
		$entity->setUuid('case-1');
		$entity->setStatus($status);

		return $entity;
	}//end entity()

	/**
	 * A received case of the type is completed before it is stored.
	 *
	 * @return void
	 */
	public function testAReceivedCaseIsCompletedBeforeItIsStored(): void {
		$entity = $this->entity(payload: ['caseType' => 'ct-woo', 'title' => 'T', 'portalSubject' => 'sanne', 'wooRequest' => ['collectionId' => 'd-1']]);

		$this->listener()->handle(new ObjectCreatingEvent($entity));

		$this->assertSame(expected: 'portal', actual: $entity->getObject()['wooRequest']['origin']);
		$this->assertSame(expected: 'subject:sanne', actual: $entity->getObject()['portalParty']);
	}//end testAReceivedCaseIsCompletedBeforeItIsStored()

	/**
	 * A draft, and a case of a type without an intake, are left alone.
	 *
	 * @return void
	 */
	public function testADraftAndAnotherTypeAreLeftAlone(): void {
		$draft = $this->entity(payload: ['caseType' => 'ct-woo', 'portalSubject' => 'sanne', 'wooRequest' => []], status: 'draft');
		$other = $this->entity(payload: ['caseType' => 'ct-bezwaar', 'portalSubject' => 'sanne']);

		$this->listener()->handle(new ObjectCreatingEvent($draft));
		$this->listener()->handle(new ObjectCreatingEvent($other));

		$this->assertArrayNotHasKey(key: 'portalParty', array: $draft->getObject());
		$this->assertArrayNotHasKey(key: 'portalParty', array: $other->getObject());
	}//end testADraftAndAnotherTypeAreLeftAlone()

	/**
	 * A stored case is linked to the dossier its answers name.
	 *
	 * @return void
	 */
	public function testAStoredCaseIsLinkedToItsDossier(): void {
		$entity = $this->entity(payload: ['caseType' => 'ct-woo', 'portalSubject' => 'sanne', 'wooRequest' => ['collectionId' => 'd-1']]);

		$this->listener()->handle(new ObjectCreatedEvent($entity));

		$this->assertSame(expected: [['dossiq', 'case-1', 'd-1', 'sanne']], actual: $this->queued);
	}//end testAStoredCaseIsLinkedToItsDossier()
}//end class
