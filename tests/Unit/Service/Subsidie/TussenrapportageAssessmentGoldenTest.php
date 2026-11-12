<?php

/**
 * Every answer an interim report assessment gives, approved and refused.
 *
 * `approveReport()` and `partialApprove()` refuse an assessment without a
 * signed-in assessor, without OpenRegister or without the configured schema,
 * and turn a failed write into their own sentence. A partial approval without
 * a correction request is refused before anything else. These pin every one
 * of those answers, in order, and what is written when the assessment goes
 * through.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/subsidieverlening-keten/spec.md#requirement-req-sub-004-tussenrapportage-as-typed-sub-zaak
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Subsidie;

use DateTimeImmutable;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Subsidie\TussenrapportageService;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;

class TussenrapportageAssessmentGoldenTest extends TestCase {

	/**
	 * Every write the doubled object service received, as [id, data, register, schema].
	 *
	 * @var array<int, array<int, mixed>>
	 */
	public array $patches = [];

	/**
	 * Every error logged.
	 *
	 * @var array<int, string>
	 */
	public array $errors = [];

	/**
	 * Whether OpenRegister was asked for at all.
	 *
	 * @var bool
	 */
	private bool $storeAsked = false;

	/**
	 * The service over a doubled store and session.
	 *
	 * @param string $store    `row`, `null`, `boom`, `absent` or `unconfigured`.
	 * @param bool   $signedIn Whether an assessor is signed in.
	 *
	 * @return TussenrapportageService The service.
	 */
	private function service(string $store, bool $signedIn = true): TussenrapportageService {
		$test = $this;
		$objects = new class ($store, $test) {
			/**
			 * @param string                               $store How to answer.
			 * @param TussenrapportageAssessmentGoldenTest $test  Where writes are recorded.
			 */
			public function __construct(private string $store, private TussenrapportageAssessmentGoldenTest $test) {
			}

			/**
			 * A partial write, or a failure.
			 *
			 * @param string               $objectId The uuid.
			 * @param array<string, mixed> $data     The changes.
			 * @param string               $register The register.
			 * @param string               $schema   The schema.
			 *
			 * @return array<string, mixed>|null The stored row.
			 */
			public function patchObject(string $objectId, array $data, string $register, string $schema): ?array {
				if ($this->store === 'boom') {
					throw new RuntimeException('store down');
				}

				$this->test->patches[] = [$objectId, $data, $register, $schema];
				return ($this->store === 'null' ? null : ['id' => $objectId, 'stored' => true]);
			}
		};

		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturnCallback(
			function () use ($store, $objects): ?object {
				$this->storeAsked = true;
				return ($store === 'absent' ? null : $objects);
			}
		);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => ($store === 'unconfigured' ? '' : ($key === 'register' ? 'dossiq' : 'tussenrapportage'))
		);

		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('beoordelaar');
		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn($signedIn === true ? $user : null);

		$logger = new class ($test) extends AbstractLogger {
			/**
			 * @param TussenrapportageAssessmentGoldenTest $test Where errors go.
			 */
			public function __construct(private TussenrapportageAssessmentGoldenTest $test) {
			}

			/**
			 * Keep a log line.
			 *
			 * @param mixed             $level   The level.
			 * @param string|Stringable $message The message.
			 * @param array<mixed>      $context The context.
			 *
			 * @return void
			 */
			public function log($level, string|Stringable $message, array $context = []): void {
				$this->test->errors[] = (string)$level . ' ' . (string)$message;
			}
		};

		return new TussenrapportageService(settingsService: $settings, userSession: $session, logger: $logger);
	}//end service()

	/**
	 * Run an assessment, answering what came back or the refusal's sentence.
	 *
	 * @param callable $assessment The assessment.
	 *
	 * @return mixed The answer, or `refused:<sentence>`.
	 */
	private function answer(callable $assessment): mixed {
		try {
			return $assessment();
		} catch (OCSBadRequestException $e) {
			return 'refused:' . $e->getMessage();
		}
	}//end answer()

	/**
	 * Both assessments and how each fails a write.
	 *
	 * @return array<string, array{0: string, 1: string}> Per assessment: its name and its failure sentence.
	 */
	public static function assessments(): array {
		return [
			'approve'         => ['approveReport', 'Kon tussenrapportage niet goedkeuren'],
			'partial approve' => ['partialApprove', 'Kon tussenrapportage niet gedeeltelijk goedkeuren'],
		];
	}//end assessments()

	/**
	 * One assessment of report r-1 on the given service.
	 *
	 * @param TussenrapportageService $service The service.
	 * @param string                  $name    `approveReport` or `partialApprove`.
	 *
	 * @return mixed The answer, or `refused:<sentence>`.
	 */
	private function assess(TussenrapportageService $service, string $name): mixed {
		if ($name === 'approveReport') {
			return $this->answer(assessment: fn () => $service->approveReport(reportId: 'r-1'));
		}

		return $this->answer(
			assessment: fn () => $service->partialApprove(reportId: 'r-1', correctionRequest: 'Bijlage B ontbreekt', currentTeller: 2)
		);
	}//end assess()

	/**
	 * Without a signed-in assessor nothing is assessed and OpenRegister is not asked.
	 *
	 * @param string $name The assessment.
	 *
	 * @return void
	 *
	 * @dataProvider assessments
	 */
	public function testWithoutAnAssessorNothingIsAssessed(string $name): void {
		self::assertSame(
			'refused:Authenticatie vereist om te beoordelen',
			$this->assess(service: $this->service(store: 'row', signedIn: false), name: $name)
		);
		self::assertFalse($this->storeAsked);
		self::assertSame([], $this->patches);
	}//end testWithoutAnAssessorNothingIsAssessed()

	/**
	 * Without OpenRegister or its configuration the assessment is refused with the lookup's sentence.
	 *
	 * @param string $name The assessment.
	 *
	 * @return void
	 *
	 * @dataProvider assessments
	 */
	public function testAnUnreadyStoreRefuses(string $name): void {
		self::assertSame('refused:OpenRegister is niet beschikbaar', $this->assess(service: $this->service(store: 'absent'), name: $name));
		self::assertSame(
			'refused:Tussenrapportage-schema is niet geconfigureerd',
			$this->assess(service: $this->service(store: 'unconfigured'), name: $name)
		);
		self::assertSame([], $this->patches);
		self::assertSame([], $this->errors);
	}//end testAnUnreadyStoreRefuses()

	/**
	 * A write that fails is refused with the assessment's own sentence, and logged under its name.
	 *
	 * @param string $name     The assessment.
	 * @param string $sentence The failure sentence.
	 *
	 * @return void
	 *
	 * @dataProvider assessments
	 */
	public function testAFailedWriteRefusesInItsOwnWords(string $name, string $sentence): void {
		self::assertSame('refused:' . $sentence, $this->assess(service: $this->service(store: 'boom'), name: $name));
		self::assertSame(['error Dossiq subsidie: ' . $name . ' failed: store down'], $this->errors);
	}//end testAFailedWriteRefusesInItsOwnWords()

	/**
	 * A partial approval without a correction request is refused before anything else is asked.
	 *
	 * @return void
	 */
	public function testAPartialApprovalNeedsACorrectionRequest(): void {
		$answer = $this->answer(
			assessment: fn () => $this->service(store: 'row', signedIn: false)->partialApprove(reportId: 'r-1', correctionRequest: '  ', currentTeller: 0)
		);

		self::assertSame('refused:Een correctieverzoek is verplicht bij gedeeltelijke goedkeuring', $answer);
		self::assertFalse($this->storeAsked);
	}//end testAPartialApprovalNeedsACorrectionRequest()

	/**
	 * An approval writes the assessor, the moment and only the optional fields given.
	 *
	 * @return void
	 */
	public function testAnApprovalIsWritten(): void {
		$service = $this->service(store: 'row');

		self::assertSame(['id' => 'r-1', 'stored' => true], $service->approveReport(reportId: 'r-1'));
		self::assertSame(['id' => 'r-1', 'stored' => true], $service->approveReport(reportId: 'r-1', beoordelingsoordeel: 'voldoende', approvedAmount: 1200.5));

		[$plain, $full] = [$this->patches[0][1], $this->patches[1][1]];
		self::assertSame(['status', 'assessor', 'beoordelingsdatum'], array_keys($plain));
		self::assertSame(['approved', 'beoordelaar'], [$plain['status'], $plain['assessor']]);
		self::assertInstanceOf(DateTimeImmutable::class, DateTimeImmutable::createFromFormat(DATE_ATOM, $plain['beoordelingsdatum']));
		self::assertSame(['voldoende', 1200.5], [$full['beoordelingsoordeel'], $full['approvedAmount']]);
		self::assertSame(['r-1', 'dossiq', 'tussenrapportage'], [$this->patches[0][0], $this->patches[0][2], $this->patches[0][3]]);
	}//end testAnApprovalIsWritten()

	/**
	 * A partial approval writes the correction and the next numerator; a write that stores nothing answers the patch.
	 *
	 * @return void
	 */
	public function testAPartialApprovalIsWritten(): void {
		$answer = $this->service(store: 'null')->partialApprove(reportId: 'r-1', correctionRequest: 'Bijlage B ontbreekt', currentTeller: 2);

		self::assertSame($this->patches[0][1], $answer);
		self::assertSame(['status', 'correctionRequest', 'amendmentNumerator', 'assessor', 'beoordelingsdatum'], array_keys($answer));
		self::assertSame(
			['gedeeltelijk_approved', 'Bijlage B ontbreekt', 3, 'beoordelaar'],
			[$answer['status'], $answer['correctionRequest'], $answer['amendmentNumerator'], $answer['assessor']]
		);
	}//end testAPartialApprovalIsWritten()
}//end class
