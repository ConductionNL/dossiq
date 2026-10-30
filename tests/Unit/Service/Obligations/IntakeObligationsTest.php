<?php

/**
 * Tests for the obligations a form asked for when it opened the case.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/obligations-placed-at-intake/specs/obligations-placed-at-intake/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Obligations;

use DateTimeImmutable;
use OCA\Dossiq\Service\CaseDateNormaliser;
use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\Obligations\IntakeObligationCase;
use OCA\Dossiq\Service\Obligations\IntakeObligations;
use OCA\Dossiq\Service\Obligations\ObligationDeclaration;
use OCA\Dossiq\Service\Obligations\ObligationService;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Service\Obligations\IntakeObligations
 * @covers \OCA\Dossiq\Service\Obligations\IntakeObligationCase
 *
 * @spec openspec/changes/obligations-placed-at-intake/specs/obligations-placed-at-intake/spec.md
 */
class IntakeObligationsTest extends TestCase {

	/**
	 * The obligation rows.
	 *
	 * @var ObligationService&MockObject
	 */
	private ObligationService&MockObject $obligations;

	/**
	 * The case the object service answers.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $stored = null;

	/**
	 * What the object service was asked to patch.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $patches = [];

	/**
	 * The case type's whenUnmet for kind `cosign`.
	 *
	 * @var string
	 */
	private string $whenUnmet = 'stay';

	/**
	 * Build the service with a fake object service.
	 *
	 * @return IntakeObligations
	 */
	private function service(): IntakeObligations {
		$this->obligations = $this->createMock(ObligationService::class);

		$test          = $this;
		$objectService = new class($test) {
			/**
			 * @param IntakeObligationsTest $test The test holding the state.
			 */
			public function __construct(private IntakeObligationsTest $test) {
			}

			/**
			 * @param callable $operation The operation.
			 *
			 * @return mixed
			 */
			public function runAsSystem(callable $operation): mixed {
				return $operation();
			}

			/**
			 * @return array<string, mixed>|null
			 */
			public function find(string $id, mixed $register = null, mixed $schema = null): ?array {
				return $this->test->storedCase();
			}

			/**
			 * @param array<string, mixed> $data The changes.
			 *
			 * @return array<string, mixed>
			 */
			public function patchObject(string $objectId, array $data, mixed $register = null, mixed $schema = null): array {
				$this->test->recordPatch(data: $data);
				return $data;
			}
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnMap([
			['register', '', 'dossiq'],
			['case_schema', '', 'case'],
		]);

		$caseTypes = $this->createMock(CaseTypeResolver::class);
		$caseTypes->method('effectiveCaseType')->willReturnCallback(
			fn (): array => ['obligationKinds' => [
				['kind' => 'cosign', 'title' => 'the second signature', 'whenUnmet' => $this->whenUnmet],
			],
			]
		);

		$dates = $this->createMock(CaseDateNormaliser::class);
		$dates->method('nowAsMoment')->willReturn('2026-10-30T12:00:00+01:00');
		$dates->method('today')->willReturn(new DateTimeImmutable('2026-10-30'));

		$logger = $this->createMock(LoggerInterface::class);

		return new IntakeObligations(
			obligations: $this->obligations,
			declaration: new ObligationDeclaration(),
			caseTypes: $caseTypes,
			cases: new IntakeObligationCase(settingsService: $settings, dates: $dates, logger: $logger),
			dates: $dates,
			logger: $logger,
		);
	}//end service()

	/**
	 * The case the fake answers.
	 *
	 * @return array<string, mixed>|null
	 */
	public function storedCase(): ?array {
		return $this->stored;
	}//end storedCase()

	/**
	 * Record a patch.
	 *
	 * @param array<string, mixed> $data The changes.
	 *
	 * @return void
	 */
	public function recordPatch(array $data): void {
		$this->patches[] = $data;
	}//end recordPatch()

	/**
	 * A case waiting on a second signer.
	 *
	 * @param string $state The entry's state.
	 *
	 * @return array<string, mixed>
	 */
	private function waitingCase(string $state = 'awaiting'): array {
		return [
			'id' => 'case-1',
			'caseType' => 'type-1',
			'portalSubject' => 'subject-applicant',
			'intakeObligations' => [
				['kind' => 'cosign', 'holderName' => 'Henk de Vries', 'holderEmail' => 'henk@example.nl', 'dueAt' => '2026-10-22', 'state' => $state],
			],
		];
	}//end waitingCase()

	/**
	 * A received case with an awaiting entry gets an obligation that blocks closing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/obligations-placed-at-intake/specs/obligations-placed-at-intake/spec.md#requirement-a-case-opened-with-intake-obligations-waits-on-them
	 */
	public function testAReceivedCaseWaitsOnWhatTheFormAskedFor(): void {
		$service = $this->service();
		$this->obligations->method('idForSource')->willReturn('');
		$this->obligations->expects(self::once())->method('place')->with(
			self::callback(
				static fn (array $row): bool => $row['case'] === 'case-1'
					&& $row['kind'] === 'cosign'
					&& $row['title'] === 'the second signature'
					&& $row['holderName'] === 'Henk de Vries'
					&& $row['dueAt'] === '2026-10-22'
					&& $row['blocks'] === ['closing']
					&& $row['source'] === 'intake:case-1:cosign'
			)
		)->willReturn('obligation-1');

		self::assertSame(1, $service->placeFor(case: $this->waitingCase()));
	}//end testAReceivedCaseWaitsOnWhatTheFormAskedFor()

	/**
	 * A second receipt of the same case places nothing again.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/obligations-placed-at-intake/specs/obligations-placed-at-intake/spec.md#requirement-a-case-opened-with-intake-obligations-waits-on-them
	 */
	public function testAnObligationIsPlacedOnce(): void {
		$service = $this->service();
		$this->obligations->method('idForSource')->willReturn('obligation-1');
		$this->obligations->expects(self::never())->method('place');

		self::assertSame(0, $service->placeFor(case: $this->waitingCase()));
	}//end testAnObligationIsPlacedOnce()

	/**
	 * Signing meets the obligation, records who signed, and the case says signed.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/obligations-placed-at-intake/specs/obligations-placed-at-intake/spec.md#requirement-the-holder-settles-an-intake-obligation-from-the-portal
	 */
	public function testTheHolderSigns(): void {
		$service      = $this->service();
		$this->stored = $this->waitingCase();
		$this->obligations->method('idForSource')->willReturn('obligation-1');
		$this->obligations->expects(self::once())->method('meet')->with(
			'obligation-1',
			self::anything(),
			['settledBySubject' => 'subject-holder', 'settledTrust' => 'substantial']
		)->willReturn(true);

		$result = $service->settle(caseId: 'case-1', kind: 'cosign', answer: 'sign', subjectRef: 'subject-holder', trust: 'substantial');

		self::assertSame(200, $result['status']);
		self::assertSame('signed', $result['body']['state']);
		self::assertSame('signed', $this->patches[0]['intakeObligations'][0]['state']);
		self::assertSame('Henk de Vries', $this->patches[0]['intakeObligations'][0]['holderName']);
	}//end testTheHolderSigns()

	/**
	 * The applicant cannot settle their own case's obligation.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/obligations-placed-at-intake/specs/obligations-placed-at-intake/spec.md#requirement-the-holder-settles-an-intake-obligation-from-the-portal
	 */
	public function testTheApplicantCannotSignForThemselves(): void {
		$service      = $this->service();
		$this->stored = $this->waitingCase();
		$this->obligations->expects(self::never())->method('meet');

		$result = $service->settle(caseId: 'case-1', kind: 'cosign', answer: 'sign', subjectRef: 'subject-applicant', trust: 'substantial');

		self::assertSame(403, $result['status']);
		self::assertSame([], $this->patches);
	}//end testTheApplicantCannotSignForThemselves()

	/**
	 * An entry that is no longer awaiting cannot be answered again.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/obligations-placed-at-intake/specs/obligations-placed-at-intake/spec.md#requirement-the-holder-settles-an-intake-obligation-from-the-portal
	 */
	public function testAnAnsweredEntryIsNotAnsweredTwice(): void {
		$service      = $this->service();
		$this->stored = $this->waitingCase(state: 'expired');

		$result = $service->settle(caseId: 'case-1', kind: 'cosign', answer: 'sign', subjectRef: 'subject-holder', trust: 'substantial');

		self::assertSame(409, $result['status']);
	}//end testAnAnsweredEntryIsNotAnsweredTwice()

	/**
	 * An unknown answer or kind is refused before anything is read.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/obligations-placed-at-intake/specs/obligations-placed-at-intake/spec.md#requirement-the-holder-settles-an-intake-obligation-from-the-portal
	 */
	public function testBadInputIsRefused(): void {
		$service      = $this->service();
		$this->stored = $this->waitingCase();

		self::assertSame(400, $service->settle(caseId: 'case-1', kind: 'cosign', answer: 'maybe', subjectRef: 'subject-holder', trust: 'low')['status']);
		self::assertSame(404, $service->settle(caseId: 'case-1', kind: 'other', answer: 'sign', subjectRef: 'subject-holder', trust: 'low')['status']);
	}//end testBadInputIsRefused()

	/**
	 * Refusing under `stay` keeps the obligation open; under `withdraw` it is withdrawn.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/obligations-placed-at-intake/specs/obligations-placed-at-intake/spec.md#requirement-a-refused-or-lapsed-intake-obligation-follows-its-kinds-rule
	 */
	public function testARefusalFollowsTheKindsRule(): void {
		$service      = $this->service();
		$this->stored = $this->waitingCase();
		$this->obligations->method('idForSource')->willReturn('obligation-1');
		$this->obligations->expects(self::never())->method('withdraw');

		$result = $service->settle(caseId: 'case-1', kind: 'cosign', answer: 'refuse', subjectRef: 'subject-holder', trust: 'substantial');
		self::assertSame('refused', $result['body']['state']);
		self::assertSame('refused', $this->patches[0]['intakeObligations'][0]['state']);

		$this->whenUnmet = 'withdraw';
		$this->patches   = [];
		$service         = $this->service();
		$this->obligations->method('idForSource')->willReturn('obligation-1');
		$this->obligations->expects(self::once())->method('withdraw')->with('obligation-1', self::anything())->willReturn(true);

		$result = $service->settle(caseId: 'case-1', kind: 'cosign', answer: 'refuse', subjectRef: 'subject-holder', trust: 'substantial');
		self::assertSame(200, $result['status']);
	}//end testARefusalFollowsTheKindsRule()

	/**
	 * A passed due date marks the entry expired; a future one is left alone.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/obligations-placed-at-intake/specs/obligations-placed-at-intake/spec.md#requirement-a-refused-or-lapsed-intake-obligation-follows-its-kinds-rule
	 */
	public function testAPassedDueDateLapses(): void {
		$this->whenUnmet = 'withdraw';
		$service         = $this->service();
		$this->stored    = $this->waitingCase();
		$this->obligations->method('openRows')->willReturn([
			['id' => 'obligation-1', 'case' => 'case-1', 'kind' => 'cosign', 'dueAt' => '2026-10-22', 'source' => 'intake:case-1:cosign'],
			['id' => 'obligation-2', 'case' => 'case-1', 'kind' => 'cosign', 'dueAt' => '2026-11-22', 'source' => 'intake:case-1:cosign'],
			['id' => 'obligation-3', 'case' => 'case-2', 'kind' => 'advice', 'dueAt' => '2026-10-01', 'source' => 'consultation-9'],
		]);
		$this->obligations->expects(self::once())->method('withdraw')->with('obligation-1', self::anything())->willReturn(true);

		self::assertSame(1, $service->lapse());
		self::assertSame('expired', $this->patches[0]['intakeObligations'][0]['state']);
	}//end testAPassedDueDateLapses()
}//end class
