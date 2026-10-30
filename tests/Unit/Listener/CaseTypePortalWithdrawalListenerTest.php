<?php

/**
 * CaseTypePortalWithdrawalListener unit tests.
 *
 * REQ-PORTAL-014, design D4: a case type is not saved with a portal
 * withdrawal its workflow cannot write. The resolver, the store and the rule
 * are the REAL classes over a fixed object store, because the question is
 * whether the listener reads the STORED statuses and moves and judges the
 * INCOMING block against them. Each refusal is paired with an acceptance:
 * a guard that refuses everything passes every refusal test.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/portal-contribution/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Listener\CaseTypePortalWithdrawalListener;
use OCA\Dossiq\Service\CaseType\PortalWithdrawalTarget;
use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\CaseTypeStore;
use OCA\Dossiq\Service\SettingsService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Listener\CaseTypePortalWithdrawalListener
 * @covers \OCA\Dossiq\Service\CaseType\PortalWithdrawalTarget
 * @uses   \OCA\Dossiq\Service\CaseTypeResolver
 * @uses   \OCA\Dossiq\Service\CaseTypeStore
 * @uses   \OCA\Dossiq\Service\Support\LanguageMapText
 */
class CaseTypePortalWithdrawalListenerTest extends TestCase {

	private const ONTVANGEN = 'st-ontvangen';
	private const IN_BEHANDELING = 'st-in-behandeling';
	private const INGETROKKEN = 'st-ingetrokken';

	/**
	 * The stored statuses of the melding case type.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private const STATUSES = [
		['id' => self::ONTVANGEN, 'name' => 'Ontvangen', 'caseType' => 'melding', 'order' => 1],
		['id' => self::IN_BEHANDELING, 'name' => 'In behandeling', 'caseType' => 'melding', 'order' => 2],
		['id' => self::INGETROKKEN, 'name' => 'Ingetrokken', 'caseType' => 'melding', 'order' => 3, 'isFinal' => true],
	];

	/**
	 * Build the listener over a fixed store.
	 *
	 * @param array<int, array<string, mixed>> $transitions The active template's moves.
	 * @param bool                             $asString    Store the moves as the authoring page does, as JSON.
	 * @param array<int, array<string, mixed>> $statuses    The stored statuses.
	 * @param array<string, array<string, mixed>> $named    Templates readable by id, as a payload names them.
	 *
	 * @return CaseTypePortalWithdrawalListener The listener.
	 */
	private function listener(array $transitions, bool $asString = false, array $statuses = self::STATUSES, array $named = []): CaseTypePortalWithdrawalListener {
		$template = ['id' => 'tpl', 'caseType' => 'melding', 'isActive' => true];
		$template['transitions'] = $transitions;
		if ($asString === true) {
			$template['transitions'] = json_encode($transitions);
		}

		$rows = [
			'status-type-schema-id' => $statuses,
			'workflow-template-schema-id' => ($transitions === [] ? [] : [$template]),
		];

		$objectService = new class($rows, $named) {
			/**
			 * @param array<string, array<int, array<string, mixed>>> $rows  Rows by schema.
			 * @param array<string, array<string, mixed>>             $named Templates by id.
			 */
			public function __construct(private array $rows, private array $named) {
			}

			/**
			 * Read one case type.
			 *
			 * @param string $id       The id.
			 * @param string $register The register.
			 * @param string $schema   The schema.
			 *
			 * @return array<string, mixed> The row.
			 */
			public function find(string $id, string $register, string $schema): array {
				if ($schema === 'workflow-template-schema-id') {
					if (isset($this->named[$id]) === false) {
						throw new \RuntimeException('not found');
					}

					return $this->named[$id];
				}

				return ($id === 'melding' ? ['id' => 'melding', 'title' => 'Melding'] : []);
			}

			/**
			 * Search one schema by `caseType`.
			 *
			 * @param array<string, mixed> $query The query.
			 *
			 * @return array<int, array<string, mixed>> The rows.
			 */
			public function searchObjects(array $query): array {
				$schema = (string)($query['@self']['schema'] ?? '');
				return array_values(
					array_filter(
						($this->rows[$schema] ?? []),
						static fn (array $row): bool => ($row['caseType'] ?? null) === ($query['caseType'] ?? null)
					)
				);
			}
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => match ($key) {
				'register' => 'dossiq',
				'case_type_schema' => 'case-type-schema-id',
				'status_type_schema' => 'status-type-schema-id',
				'workflow_template_schema' => 'workflow-template-schema-id',
				default => $default,
			}
		);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters)
		);

		$store = new CaseTypeStore($settings);

		return new CaseTypePortalWithdrawalListener(
			$settings,
			new CaseTypeResolver($store),
			$store,
			new PortalWithdrawalTarget(),
			$l10n,
			$this->createMock(LoggerInterface::class),
		);
	}//end listener()

	/**
	 * A case type entity as the object API would write it.
	 *
	 * @param array<string, mixed> $payload  Its fields.
	 * @param string               $schemaId Its schema.
	 * @param string               $uuid     Its id.
	 *
	 * @return ObjectEntity The entity.
	 */
	private function entity(array $payload, string $schemaId = 'case-type-schema-id', string $uuid = 'melding'): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setObject($payload);
		$entity->setSchema($schemaId);
		$entity->setUuid($uuid);

		return $entity;
	}//end entity()

	/**
	 * Save the melding type with this withdrawal, as an update.
	 *
	 * @param CaseTypePortalWithdrawalListener $listener   The listener.
	 * @param array<string, mixed>             $withdrawal The block.
	 * @param array<string, mixed>             $extra      More fields of the saved payload.
	 *
	 * @return ObjectUpdatingEvent The event, after the listener ran.
	 */
	private function save(CaseTypePortalWithdrawalListener $listener, array $withdrawal, array $extra = []): ObjectUpdatingEvent {
		$event = new ObjectUpdatingEvent(
			$this->entity(array_merge(['title' => 'Melding', 'portalWithdrawal' => $withdrawal], $extra)),
			$this->entity(['title' => 'Melding'])
		);
		$listener->handle($event);

		return $event;
	}//end save()

	/**
	 * A withdrawal while Ontvangen, onto Ingetrokken.
	 *
	 * @param string $target The target status.
	 *
	 * @return array<string, mixed> The block.
	 */
	private function withdrawal(string $target = self::INGETROKKEN): array {
		return [
			'openStatuses' => [self::ONTVANGEN],
			'targetStatus' => $target,
			'closedReason' => 'Uw melding is al in behandeling.',
			'confirmText' => 'Weet u het zeker?',
		];
	}//end withdrawal()

	/**
	 * The spec's scenario: no move from Ontvangen to Ingetrokken, so the save is refused naming Ingetrokken.
	 *
	 * @return void
	 */
	public function testAnUnreachableWithdrawalStatusIsRefusedNamingIt(): void {
		$listener = $this->listener(
			[
				['id' => 'pick-up', 'fromStatus' => self::ONTVANGEN, 'toStatus' => self::IN_BEHANDELING],
				['id' => 'withdraw-late', 'fromStatus' => self::IN_BEHANDELING, 'toStatus' => self::INGETROKKEN],
			]
		);

		$event = $this->save($listener, $this->withdrawal());

		$this->assertTrue($event->isPropagationStopped());
		$this->assertStringContainsString('Ingetrokken', $event->getErrors()['message']);
		$this->assertStringContainsString('Ontvangen', $event->getErrors()['message']);
		$this->assertSame([CaseTypePortalWithdrawalListener::ERROR_CODE], $event->getErrors()['codes']);
	}//end testAnUnreachableWithdrawalStatusIsRefusedNamingIt()

	/**
	 * Status names stored as language maps name the statuses, not "Array".
	 *
	 * Found live on 11 October 2026: OpenRegister hands a translatable status
	 * name back as `{"nl": ...}`, the listener cast it, and the import was
	 * refused with "Een zaak in Array kan niet naar Array" (N-2).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/translatable-titles-read-as-text/specs/case-types/spec.md
	 */
	public function testStatusNamesStoredAsLanguageMapsAreNamedInTheRefusal(): void {
		$statuses = array_map(
			static fn (array $row): array => array_merge($row, ['name' => ['nl' => $row['name'], 'en' => 'EN ' . $row['name']]]),
			self::STATUSES
		);
		$listener = $this->listener(
			[
				['id' => 'pick-up', 'fromStatus' => self::ONTVANGEN, 'toStatus' => self::IN_BEHANDELING],
			],
			statuses: $statuses
		);

		$message = $this->save($listener, $this->withdrawal())->getErrors()['message'];

		$this->assertStringNotContainsString('Array', $message);
		$this->assertStringContainsString('A case in Ontvangen cannot move to Ingetrokken', $message);
	}//end testStatusNamesStoredAsLanguageMapsAreNamedInTheRefusal()

	/**
	 * A move straight from Ontvangen to Ingetrokken: the save goes through.
	 *
	 * @return void
	 */
	public function testAReachableWithdrawalStatusSaves(): void {
		$listener = $this->listener(
			[
				['id' => 'pick-up', 'fromStatus' => self::ONTVANGEN, 'toStatus' => self::IN_BEHANDELING],
				['id' => 'withdraw', 'fromStatus' => self::ONTVANGEN, 'toStatus' => self::INGETROKKEN],
			]
		);

		$event = $this->save($listener, $this->withdrawal());

		$this->assertFalse($event->isPropagationStopped());
		$this->assertSame([], $event->getErrors());
	}//end testAReachableWithdrawalStatusSaves()

	/**
	 * 🔴 Zuiddrecht, 14 Oct: the save is judged by the flow the payload names, not the stored active one.
	 *
	 * The upgraded instance held an older active basis flow without the move
	 * from Ontvangen to Ingetrokken. The shipped case type names its own flow,
	 * which has that move, and was refused on every upgrade.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/case-types/spec.md
	 */
	public function testTheFlowThePayloadNamesIsJudgedNotTheStoredActiveOne(): void {
		$basis = [['id' => 'pick-up', 'fromStatus' => self::ONTVANGEN, 'toStatus' => self::IN_BEHANDELING]];
		$shipped = [
			'id' => 'shipped-flow',
			'caseType' => 'melding',
			'isActive' => true,
			'transitions' => json_encode([['id' => 'withdraw', 'fromStatus' => self::ONTVANGEN, 'toStatus' => self::INGETROKKEN]]),
		];
		$listener = $this->listener($basis, named: ['shipped-flow' => $shipped]);

		$this->assertTrue(
			$this->save($listener, $this->withdrawal())->isPropagationStopped(),
			'control: judged by the stored active basis flow, the save is refused'
		);
		$this->assertFalse(
			$this->save($listener, $this->withdrawal(), ['workflowDefinition' => 'shipped-flow'])->isPropagationStopped(),
			'the flow the payload names has the move, so the save goes through'
		);
	}//end testTheFlowThePayloadNamesIsJudgedNotTheStoredActiveOne()

	/**
	 * A payload naming a flow the store does not hold is judged by the active one, as before.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/case-types/spec.md
	 */
	public function testADanglingFlowNameFallsBackToTheActiveFlow(): void {
		$listener = $this->listener([['id' => 'pick-up', 'fromStatus' => self::ONTVANGEN, 'toStatus' => self::IN_BEHANDELING]]);

		$this->assertTrue($this->save($listener, $this->withdrawal(), ['workflowDefinition' => 'not-written-yet'])->isPropagationStopped());
	}//end testADanglingFlowNameFallsBackToTheActiveFlow()

	/**
	 * Moves stored as a JSON string are still read, and still refuse.
	 *
	 * @return void
	 */
	public function testMovesStoredAsJsonAreRead(): void {
		$listener = $this->listener(
			[['id' => 'pick-up', 'fromStatus' => self::ONTVANGEN, 'toStatus' => self::IN_BEHANDELING]],
			true
		);

		$this->assertTrue($this->save($listener, $this->withdrawal())->isPropagationStopped());
	}//end testMovesStoredAsJsonAreRead()

	/**
	 * A target that is not one of this type's statuses is refused, also without a workflow.
	 *
	 * @return void
	 */
	public function testATargetOfAnotherTypeIsRefused(): void {
		$event = $this->save($this->listener([]), $this->withdrawal('st-of-another-type'));

		$this->assertTrue($event->isPropagationStopped());
		$this->assertStringContainsString('st-of-another-type', $event->getErrors()['message']);
	}//end testATargetOfAnotherTypeIsRefused()

	/**
	 * A type with statuses and no workflow: any of its own statuses is a valid target.
	 *
	 * @return void
	 */
	public function testATypeWithoutAWorkflowAcceptsItsOwnStatus(): void {
		$this->assertFalse($this->save($this->listener([]), $this->withdrawal())->isPropagationStopped());
	}//end testATypeWithoutAWorkflowAcceptsItsOwnStatus()

	/**
	 * A type whose statuses are not stored yet (the same import) is not judged.
	 *
	 * @return void
	 */
	public function testATypeWithoutStoredStatusesIsNotJudged(): void {
		$listener = $this->listener([], false, []);

		$this->assertFalse($this->save($listener, $this->withdrawal('anything'))->isPropagationStopped());
	}//end testATypeWithoutStoredStatusesIsNotJudged()

	/**
	 * Another schema's save, and a create, follow the same rules; the schema gate holds.
	 *
	 * @return void
	 */
	public function testOnlyCaseTypesAreInspected(): void {
		$listener = $this->listener([['id' => 'pick-up', 'fromStatus' => self::ONTVANGEN, 'toStatus' => self::IN_BEHANDELING]]);

		$other = new ObjectCreatingEvent(
			$this->entity(['portalWithdrawal' => $this->withdrawal()], 'some-other-schema')
		);
		$listener->handle($other);
		$this->assertFalse($other->isPropagationStopped());

		$create = new ObjectCreatingEvent($this->entity(['portalWithdrawal' => $this->withdrawal()]));
		$listener->handle($create);
		$this->assertTrue($create->isPropagationStopped());
	}//end testOnlyCaseTypesAreInspected()
}//end class
