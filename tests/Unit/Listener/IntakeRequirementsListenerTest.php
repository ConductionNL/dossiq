<?php

/**
 * The enforcement point, driven through the real pre-persist event.
 *
 * This is the half the whole change stands on. The declarations are readable on
 * their own and tested on their own; what has to be proved here is that a save
 * is actually STOPPED, through the same `ObjectCreatingEvent` OpenRegister
 * dispatches, and that propagation is not stopped on a save that is fine.
 *
 * The update event is driven separately, because the two rules differ there on
 * purpose: who may hold a case is a rule about the case at rest and is checked
 * again, while what had to be answered before the case existed is not, or an
 * administrator adding a field to the declaration would make every existing
 * case unsavable, including by the very edit that fills the field in.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Listener
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
 * @spec openspec/changes/intake-triage-and-refusal/specs/semantic-case-intake/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Listener\IntakeRequirementsListener;
use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\Intake\AssigneeNarrowing;
use OCA\Dossiq\Service\Intake\CaseClassification;
use OCA\Dossiq\Service\Intake\ClassificationSchemes;
use OCA\Dossiq\Service\Intake\DuplicatePolicy;
use OCA\Dossiq\Service\Intake\IntakeRequirements;
use OCA\Dossiq\Service\SettingsService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Unit tests for the pre-persist enforcement of the intake declarations.
 *
 * @covers \OCA\Dossiq\Listener\IntakeRequirementsListener
 *
 * @uses \OCA\Dossiq\Service\Intake\AssigneeNarrowing
 * @uses \OCA\Dossiq\Service\Intake\CaseClassification
 * @uses \OCA\Dossiq\Service\Intake\ClassificationSchemes
 * @uses \OCA\Dossiq\Service\Intake\IntakeRequirements
 * @uses \OCA\Dossiq\Exception\RefusedException
 */
class IntakeRequirementsListenerTest extends TestCase {

	/**
	 * The schema id the settings service answers with.
	 */
	private const CASE_SCHEMA = 'case-schema-id';

	/**
	 * What the case type declares.
	 *
	 * @var array<string, mixed>
	 */
	private array $caseType = [];

	/**
	 * The listener under test.
	 *
	 * @var IntakeRequirementsListener
	 */
	private IntakeRequirementsListener $listener;

	/**
	 * Wire the listener over the declarations.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->caseType = [
			'title' => 'Melding openbare ruimte',
			'intakeRequirements' => [
				'requiredBeforeCreation' => IntakeRequirements::DEFAULT_FOR_A_NEW_CASE_TYPE,
			],
		];

		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getConfigValue')->willReturnCallback(
			static function (string $key, string $default = ''): string {
				if ($key === 'case_schema') {
					return self::CASE_SCHEMA;
				}

				return $default;
			}
		);

		$resolver = $this->createMock(originalClassName: CaseTypeResolver::class);
		$resolver->method('effectiveCaseType')->willReturnCallback(fn (): array => $this->caseType);

		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $default
		);

		$this->listener = new IntakeRequirementsListener(
			settingsService: $settings,
			caseTypeResolver: $resolver,
			requirements: new IntakeRequirements(),
			classification: new CaseClassification(
				schemes: new ClassificationSchemes(appConfig: $appConfig)
			),
			narrowing: new AssigneeNarrowing(),
			duplicates: new DuplicatePolicy(
				container: $this->createMock(originalClassName: ContainerInterface::class),
				groupManager: $this->createMock(originalClassName: IGroupManager::class),
				logger: new NullLogger()
			),
			userSession: $this->createMock(originalClassName: IUserSession::class),
			logger: new NullLogger(),
		);
	}//end setUp()

	/**
	 * Build a case entity with the given payload.
	 *
	 * @param array<string, mixed> $payload  The case fields.
	 * @param string               $schemaId The schema id.
	 *
	 * @return ObjectEntity The entity.
	 */
	private function entity(array $payload, string $schemaId = self::CASE_SCHEMA): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setObject($payload);
		$entity->setSchema($schemaId);
		$entity->setUuid('11111111-1111-1111-1111-111111111111');

		return $entity;
	}//end entity()

	/**
	 * A creation missing a declared field is stopped, with the field named.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/semantic-case-intake/spec.md#requirement-a-case-type-declares-what-must-be-answered-before-a-case-exists-req-triage-01
	 */
	public function testACreationMissingTheChannelIsStopped(): void {
		$event = new ObjectCreatingEvent(
			$this->entity(payload: ['caseType' => 'ct-1', 'confidentiality' => 'openbaar'])
		);

		$this->listener->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertStringContainsString('communicationChannel', (string)$event->getErrors()['message']);
		$this->assertSame(IntakeRequirements::RULE_MISSING_FIELD, $event->getErrors()['error']);
	}//end testACreationMissingTheChannelIsStopped()

	/**
	 * A creation that answers everything is not stopped.
	 *
	 * The control: without it, a listener that stopped every save would pass
	 * every refusal test in this file.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/semantic-case-intake/spec.md#requirement-a-case-type-declares-what-must-be-answered-before-a-case-exists-req-triage-01
	 */
	public function testACreationThatAnswersEverythingIsNotStopped(): void {
		$event = new ObjectCreatingEvent(
			$this->entity(
				payload: [
					'caseType' => 'ct-1',
					'confidentiality' => 'openbaar',
					'communicationChannel' => 'https://example.gemeente.nl/portaal',
				]
			)
		);

		$this->listener->handle($event);

		$this->assertFalse($event->isPropagationStopped());
		$this->assertSame([], $event->getErrors());
	}//end testACreationThatAnswersEverythingIsNotStopped()

	/**
	 * An unclassified case is stopped where the classification is the rule.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/semantic-case-intake/spec.md#requirement-a-classification-that-is-an-access-rule-is-required-before-the-case-exists-req-triage-02
	 */
	public function testAnUnclassifiedCaseIsStopped(): void {
		$this->caseType = [
			'intakeRequirements' => ['requiredBeforeCreation' => []],
			'caseClassification' => [
				'scheme' => 'vertrouwelijkheidaanduiding',
				'classificationIsAccessRule' => true,
			],
		];
		$event = new ObjectCreatingEvent($this->entity(payload: ['caseType' => 'ct-1']));

		$this->listener->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame(CaseClassification::RULE_UNCLASSIFIED, $event->getErrors()['error']);
	}//end testAnUnclassifiedCaseIsStopped()

	/**
	 * The API refuses a team the picker never offered.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/kcc-routing/spec.md#requirement-a-case-type-narrows-who-may-be-assigned-at-creation-req-triage-03
	 */
	public function testATeamOutsideTheNarrowingIsStopped(): void {
		$this->caseType = [
			'intakeRequirements' => ['requiredBeforeCreation' => []],
			'assigneeNarrowing' => ['allowedGroups' => ['handhaving', 'juridische-zaken']],
		];
		$event = new ObjectCreatingEvent(
			$this->entity(payload: ['caseType' => 'ct-1', 'assignedGroup' => 'burgerzaken'])
		);

		$this->listener->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame(AssigneeNarrowing::RULE_GROUP_OUTSIDE, $event->getErrors()['error']);
	}//end testATeamOutsideTheNarrowingIsStopped()

	/**
	 * The narrowing is enforced on an update too.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/kcc-routing/spec.md#requirement-a-case-type-narrows-who-may-be-assigned-at-creation-req-triage-03
	 */
	public function testTheNarrowingIsEnforcedOnAnUpdate(): void {
		$this->caseType = ['assigneeNarrowing' => ['allowedGroups' => ['handhaving']]];
		$entity = $this->entity(payload: ['caseType' => 'ct-1', 'assignedGroup' => 'burgerzaken']);
		$event = new ObjectUpdatingEvent($entity, $entity);

		$this->listener->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame(AssigneeNarrowing::RULE_GROUP_OUTSIDE, $event->getErrors()['error']);
	}//end testTheNarrowingIsEnforcedOnAnUpdate()

	/**
	 * An update is not refused for a field required before the case existed.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/semantic-case-intake/spec.md
	 */
	public function testAnUpdateIsNotRefusedForACreationOnlyRequirement(): void {
		$entity = $this->entity(payload: ['caseType' => 'ct-1']);
		$event = new ObjectUpdatingEvent($entity, $entity);

		$this->listener->handle($event);

		$this->assertFalse($event->isPropagationStopped());
	}//end testAnUpdateIsNotRefusedForACreationOnlyRequirement()

	/**
	 * A save of something that is not a case is left alone.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/semantic-case-intake/spec.md
	 */
	public function testASaveOfAnotherSchemaIsLeftAlone(): void {
		$event = new ObjectCreatingEvent(
			$this->entity(payload: ['caseType' => 'ct-1'], schemaId: 'contact-schema-id')
		);

		$this->listener->handle($event);

		$this->assertFalse($event->isPropagationStopped());
	}//end testASaveOfAnotherSchemaIsLeftAlone()

	/**
	 * A case type that cannot be resolved leaves the save alone, on purpose.
	 *
	 * 🔴 THIS IS THE ONE DOOR THIS LISTENER LEAVES OPEN, AND IT IS DELIBERATE.
	 * `CaseTypeResolver` answers `[]` both for "there is no such case type" and
	 * for "the store could not be read", because `CaseTypeStore::readCaseType()`
	 * never throws. Refusing on `[]` would stop every case creation on the
	 * instance the moment OpenRegister hiccups, which is a worse failure than
	 * the one it would prevent. Closing it properly needs the store to tell the
	 * two apart, which is an openregister change, not a dossiq one.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/semantic-case-intake/spec.md
	 */
	public function testAnUnresolvableCaseTypeLeavesTheSaveAlone(): void {
		$this->caseType = [];
		$event = new ObjectCreatingEvent($this->entity(payload: ['caseType' => 'ct-gone']));

		$this->listener->handle($event);

		$this->assertFalse($event->isPropagationStopped());
	}//end testAnUnresolvableCaseTypeLeavesTheSaveAlone()

	/**
	 * A case type that declared nothing refuses nothing.
	 *
	 * The blast-radius control at the enforcement point: the payload every
	 * existing creation path writes is a title, a type and a date, and an
	 * upgrade must not start refusing it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/semantic-case-intake/spec.md
	 */
	public function testACaseTypeThatDeclaredNothingRefusesNothing(): void {
		$this->caseType = ['title' => 'Melding openbare ruimte'];
		$event = new ObjectCreatingEvent(
			$this->entity(
				payload: [
					'title' => 'Melding',
					'caseType' => 'ct-1',
					'startDate' => '2026-09-15',
				]
			)
		);

		$this->listener->handle($event);

		$this->assertFalse($event->isPropagationStopped());
	}//end testACaseTypeThatDeclaredNothingRefusesNothing()

	/**
	 * A case naming no case type is left alone.
	 *
	 * Nothing declared anything about it, so there is nothing to enforce and
	 * refusing would block every case written before its type is chosen.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/semantic-case-intake/spec.md
	 */
	public function testACaseWithNoCaseTypeIsLeftAlone(): void {
		$event = new ObjectCreatingEvent($this->entity(payload: ['title' => 'Concept']));

		$this->listener->handle($event);

		$this->assertFalse($event->isPropagationStopped());
	}//end testACaseWithNoCaseTypeIsLeftAlone()
}//end class
