<?php

/**
 * The purpose a reader states before a sensitive case opens is written to the
 * case's audit trail; an unreadable case, a bad purpose or a failed write is
 * refused, so the case stays closed (case-access-purpose).
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service
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
 * @spec openspec/specs/case-access-purpose/spec.md#requirement-opening-a-sensitive-case-records-its-purpose
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\CaseAccessPurposeService;
use OCA\Dossiq\Service\People\CaseRoleHolder;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Tests for {@see CaseAccessPurposeService}.
 */
class CaseAccessPurposeServiceTest extends TestCase {

	/**
	 * The audit entries written, as [object, action, context, actorId].
	 *
	 * @var list<array{0: object, 1: string, 2: array<string, mixed>, 3: string|null}>
	 */
	private array $written = [];

	/**
	 * Whether the audit write fails.
	 *
	 * @var boolean
	 */
	private bool $writeFails = false;

	/**
	 * The readers who hold a skip role on every case, for the role holder double.
	 *
	 * @var list<string>
	 */
	private array $holders = [];

	/**
	 * The bindings the role holder double was asked to add, as [uid, genericRole].
	 *
	 * @var list<array{0: string, 1: string}>
	 */
	private array $bound = [];

	/**
	 * The service over a register holding the given objects, by uuid.
	 *
	 * @param array<string, array<string, mixed>> $objects The objects the reader may read.
	 * @param string                              $rules   The app configuration of the purpose and role rules.
	 *
	 * @return CaseAccessPurposeService The service.
	 */
	private function service(array $objects, string $rules=''): CaseAccessPurposeService {
		$objectService = new class($objects) {
			/**
			 * @param array<string, array<string, mixed>> $objects Readable objects.
			 */
			public function __construct(private array $objects) {
			}

			/**
			 * @param string $id       The uuid.
			 * @param string $register The register.
			 * @param string $schema   The schema.
			 *
			 * @return object The entity.
			 */
			public function find(string $id, string $register, string $schema): object {
				if (isset($this->objects[$id]) === false) {
					throw new RuntimeException('Object not found');
				}

				$data = $this->objects[$id];
				return new class($id, $data) {
					/**
					 * @param string               $uuid The uuid.
					 * @param array<string, mixed> $data The fields.
					 */
					public function __construct(public string $uuid, private array $data) {
					}

					/**
					 * @return array<string, mixed>
					 */
					public function getObject(): array {
						return $this->data;
					}
				};
			}
		};

		$test = $this;
		$mapper = new class($test) {
			/**
			 * @param CaseAccessPurposeServiceTest $test The test collecting the writes.
			 */
			public function __construct(private CaseAccessPurposeServiceTest $test) {
			}

			/**
			 * @param object               $object    The case.
			 * @param string               $action    The action.
			 * @param array<string, mixed> $context   The context.
			 * @param string|null          $actorId   The actor.
			 * @param string|null          $actorName The actor's name.
			 *
			 * @return void
			 */
			public function createAuditTrailEntry(object $object, string $action, array $context, ?string $actorId=null, ?string $actorName=null): void {
				$this->test->collect(object: $object, action: $action, context: $context, actorId: $actorId);
			}
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getOpenRegisterClass')->willReturn($mapper);
		$settings->method('getConfigValue')->willReturnMap([
			['register', '', 'dossiq'],
			['case_schema', '', 'case'],
			['case_type_schema', '', 'caseType'],
			[CaseAccessPurposeService::ROLES_KEY, '', $rules],
		]);

		$roles = $this->createMock(CaseRoleHolder::class);
		$roles->method('holdsAny')->willReturnCallback(fn (array $case, string $uid, array $genericRoles): bool => in_array($uid, $this->holders, true) && $genericRoles !== []);
		$roles->method('bind')->willReturnCallback(function (array $case, string $uid, string $genericRole, string $displayName): bool {
			$this->bound[] = [$uid, $genericRole];
			return true;
		});

		return new CaseAccessPurposeService(settingsService: $settings, logger: new NullLogger(), roles: $roles);
	}//end service()

	/**
	 * Collect an audit write, or fail it when the test says so.
	 *
	 * @param object               $object  The case.
	 * @param string               $action  The action.
	 * @param array<string, mixed> $context The context.
	 * @param string|null          $actorId The actor.
	 *
	 * @return void
	 */
	public function collect(object $object, string $action, array $context, ?string $actorId): void {
		if ($this->writeFails === true) {
			throw new RuntimeException('database is gone');
		}

		$this->written[] = [$object, $action, $context, $actorId];
	}//end collect()

	/**
	 * A Jeugdwet case and its case type, which names health.
	 *
	 * @return array<string, array<string, mixed>> The objects.
	 */
	private function jeugdwet(): array {
		return [
			'case-1' => ['title' => 'Melding Jeugdwet gezin Oosthaven', 'caseType' => 'ct-jw'],
			'ct-jw' => ['title' => 'Melding Jeugdwet', 'specialDataCategories' => ['health']],
			'case-2' => ['title' => 'Dakkapel', 'caseType' => 'ct-ov'],
			'ct-ov' => ['title' => 'Omgevingsvergunning'],
		];
	}//end jeugdwet()

	/**
	 * The purpose goes to the case's audit trail with the reader, the time and the categories.
	 *
	 * @return void
	 */
	public function testRecordsThePurposeInTheCasesAuditTrail(): void {
		$result = $this->service(objects: $this->jeugdwet())->record(caseId: 'case-1', readerId: 'pieter', purpose: 'handling', note: 'ignored');

		$this->assertSame(['recorded' => true, 'required' => true, 'roleAdded' => true], $result);
		$this->assertCount(2, $this->written);
		[$object, $action, $context, $actorId] = $this->written[0];
		$this->assertSame('case-1', $object->uuid);
		$this->assertSame(CaseAccessPurposeService::ACTION, $action);
		$this->assertSame('pieter', $actorId);
		$this->assertSame('pieter', $context['reader']);
		$this->assertSame('handling', $context['purpose']);
		$this->assertSame(['health'], $context['categories']);
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $context['at']);
	}//end testRecordsThePurposeInTheCasesAuditTrail()

	/**
	 * "Other" keeps the reader's own words, trimmed.
	 *
	 * @return void
	 */
	public function testRecordsTheReadersOwnWordsForOther(): void {
		$this->service(objects: $this->jeugdwet())->record(caseId: 'case-1', readerId: 'pieter', purpose: 'other', note: '  Steekproef FG  ');

		$this->assertSame('Steekproef FG', $this->written[0][2]['note']);
	}//end testRecordsTheReadersOwnWordsForOther()

	/**
	 * A case the reader may not read is refused, and nothing is written.
	 *
	 * @return void
	 */
	public function testRefusesACaseTheReaderCannotOpen(): void {
		try {
			$this->service(objects: $this->jeugdwet())->record(caseId: 'case-hidden', readerId: 'pieter', purpose: 'handling', note: '');
			$this->fail('An unreadable case must be refused.');
		} catch (RefusedException $e) {
			$this->assertSame('case-not-readable', $e->getRule());
			$this->assertSame(404, $e->getStatus());
		}

		$this->assertSame([], $this->written);
	}//end testRefusesACaseTheReaderCannotOpen()

	/**
	 * A case type that cannot be read is not taken to ask nothing: the open is refused.
	 *
	 * @return void
	 */
	public function testRefusesWhenTheCaseTypeCannotBeRead(): void {
		$objects = $this->jeugdwet();
		unset($objects['ct-jw']);
		try {
			$this->service(objects: $objects)->record(caseId: 'case-1', readerId: 'pieter', purpose: 'handling', note: '');
			$this->fail('An unreadable case type must refuse the open.');
		} catch (RefusedException $e) {
			$this->assertSame(RefusedException::STATUS_INDETERMINATE, $e->getStatus());
		}

		$this->assertSame([], $this->written);
	}//end testRefusesWhenTheCaseTypeCannotBeRead()

	/**
	 * A failed write refuses the open, so the case does not open unrecorded.
	 *
	 * @return void
	 */
	public function testRefusesTheOpenWhenThePurposeCannotBeWritten(): void {
		$this->writeFails = true;
		try {
			$this->service(objects: $this->jeugdwet())->record(caseId: 'case-1', readerId: 'pieter', purpose: 'handling', note: '');
			$this->fail('A purpose that could not be written must refuse the open.');
		} catch (RefusedException $e) {
			$this->assertSame('access-purpose-not-recorded', $e->getRule());
			$this->assertSame(RefusedException::STATUS_INDETERMINATE, $e->getStatus());
		}
	}//end testRefusesTheOpenWhenThePurposeCannotBeWritten()

	/**
	 * A purpose not on the list, or "other" without words, is refused before anything is read.
	 *
	 * @return void
	 */
	public function testRefusesAnUnknownPurposeAndAnEmptyOther(): void {
		foreach ([['curiosity', ''], ['other', '   ']] as [$purpose, $note]) {
			try {
				$this->service(objects: $this->jeugdwet())->record(caseId: 'case-1', readerId: 'pieter', purpose: $purpose, note: $note);
				$this->fail('Refused: ' . $purpose);
			} catch (RefusedException $e) {
				$this->assertSame(RefusedException::STATUS_UNPROCESSABLE, $e->getStatus());
			}
		}

		$this->assertSame([], $this->written);
	}//end testRefusesAnUnknownPurposeAndAnEmptyOther()

	/**
	 * A case whose case type names no special categories asks nothing and writes nothing.
	 *
	 * @return void
	 */
	public function testWritesNothingForACaseThatAsksNoPurpose(): void {
		$result = $this->service(objects: $this->jeugdwet())->record(caseId: 'case-2', readerId: 'pieter', purpose: 'handling', note: '');

		$this->assertSame(['recorded' => false, 'required' => false], $result);
		$this->assertSame([], $this->written);
	}//end testWritesNothingForACaseThatAsksNoPurpose()

	/**
	 * The page offers exactly the purposes the service accepts, "other" added by the library.
	 *
	 * @return void
	 */
	public function testThePageOffersTheAcceptedPurposes(): void {
		$manifest = json_decode((string)file_get_contents(__DIR__ . '/../../../src/manifest.json'), true);
		$offered = [];
		foreach ($manifest['pages'] as $page) {
			if ($page['id'] === 'CaseDetail') {
				$offered = array_column($page['config']['accessPurpose']['options'], 'value');
			}
		}

		$this->assertSame(array_values(array_diff(CaseAccessPurposeService::PURPOSES, ['other'])), $offered);
	}//end testThePageOffersTheAcceptedPurposes()

	/**
	 * The case's own handler need not state a purpose: handling is recorded for
	 * them, marked automatic, and the dialog stays shut. Anyone else is asked.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/case-access-purpose/spec.md#requirement-the-cases-own-handler-opens-it-without-the-dialog
	 */
	public function testTheHandlerOpensWithoutAPurposeAndAnotherReaderIsAsked(): void {
		$this->holders = ['pieter'];
		$service = $this->service(objects: $this->jeugdwet());

		$this->assertSame(['required' => false, 'recorded' => true], $service->check(caseId: 'case-1', readerId: 'pieter'));
		$this->assertCount(1, $this->written);
		$this->assertSame(CaseAccessPurposeService::ACTION, $this->written[0][1]);
		$this->assertSame('handling', $this->written[0][2]['purpose']);
		$this->assertTrue($this->written[0][2]['automatic']);

		$this->assertSame(['required' => true, 'recorded' => false], $service->check(caseId: 'case-1', readerId: 'fatma'));
		$this->assertSame(['required' => false, 'recorded' => false], $service->check(caseId: 'case-2', readerId: 'fatma'));
		$this->assertCount(1, $this->written);
	}//end testTheHandlerOpensWithoutAPurposeAndAnotherReaderIsAsked()

	/**
	 * Handling binds the reader as handler and says so in the trail; another
	 * purpose binds nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/case-access-purpose/spec.md#requirement-handling-the-case-makes-you-its-handler
	 */
	public function testHandlingMakesTheReaderTheHandlerAndAdviceDoesNot(): void {
		$service = $this->service(objects: $this->jeugdwet());
		$result = $service->record(caseId: 'case-1', readerId: 'fatma', purpose: 'handling', note: '');

		$this->assertTrue($result['roleAdded']);
		$this->assertSame([['fatma', 'handler']], $this->bound);
		$this->assertSame([CaseAccessPurposeService::ACTION, CaseAccessPurposeService::ACTION_ROLE], array_column($this->written, 1));
		$this->assertSame('handler', $this->written[1][2]['role']);
		$this->assertSame('user:fatma', $this->written[1][2]['participant']);

		$this->written = [];
		$this->bound = [];
		$advice = $service->record(caseId: 'case-1', readerId: 'fatma', purpose: 'advice', note: '');
		$this->assertFalse($advice['roleAdded']);
		$this->assertSame([], $this->bound);
		$this->assertSame([CaseAccessPurposeService::ACTION], array_column($this->written, 1));
	}//end testHandlingMakesTheReaderTheHandlerAndAdviceDoesNot()

	/**
	 * The rules are configuration: an app that maps advice to advisor binds that.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/case-access-purpose/spec.md
	 */
	public function testTheRulesAreConfiguration(): void {
		$service = $this->service(objects: $this->jeugdwet(), rules: '{"assign": {"advice": "advisor"}}');
		$service->record(caseId: 'case-1', readerId: 'fatma', purpose: 'advice', note: '');
		$service->record(caseId: 'case-1', readerId: 'fatma', purpose: 'handling', note: '');

		$this->assertSame([['fatma', 'advisor']], $this->bound);
	}//end testTheRulesAreConfiguration()
}//end class
