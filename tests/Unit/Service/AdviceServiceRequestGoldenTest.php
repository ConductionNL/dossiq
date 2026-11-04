<?php

/**
 * Golden test for AdviceService::requestAdvice().
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
use OCA\Dossiq\Tests\Support\MatchesGoldenFiles;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The save half of the ObjectService, as requestAdvice() calls it.
 */
interface AdviceGoldenSaveStub {
	/**
	 * Save an object.
	 *
	 * @param mixed       $object   The object.
	 * @param mixed       $register The register.
	 * @param mixed       $schema   The schema.
	 * @param string|null $uuid     The id to save under.
	 *
	 * @return mixed
	 */
	public function saveObject(mixed $object = null, mixed $register = null, mixed $schema = null, ?string $uuid = null): mixed;
}//end interface

/**
 * Every write, delegation and notification requestAdvice() makes, per case.
 *
 * @covers \OCA\Dossiq\Service\AdviceService
 * @uses \OCA\Dossiq\Service\CaseDateNormaliser
 *
 * @spec openspec/specs/remaining-decision-delegation/spec.md
 */
class AdviceServiceRequestGoldenTest extends TestCase {
	use MakesCaseDateNormaliser;
	use MatchesGoldenFiles;

	/**
	 * The calls, in order.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $log = [];

	/**
	 * The worlds: request data, what the first save answers, and whether decidesk refuses.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: mixed, 2: bool}>
	 */
	public static function worlds(): array {
		$data = ['advisor' => 'alice', 'question' => 'Is de kap nodig?', 'deadline' => '2028-01-31'];

		return [
			'saved with id' => [$data, ['id' => 'advice-1', 'status' => 'open'], false],
			'saved with uuid only' => [$data, ['uuid' => 'advice-2'], false],
			'saved without id' => [$data, ['status' => 'open'], false],
			'save answers an object' => [$data, new \stdClass(), false],
			'no question' => [['advisor' => 'alice'], ['id' => 'advice-3'], false],
			'empty question' => [['advisor' => 'alice', 'question' => ''], ['id' => 'advice-4'], false],
			'no advisor' => [['question' => 'Vraag'], ['id' => 'advice-5'], false],
			'empty deadline' => [['advisor' => 'alice', 'deadline' => ''], ['id' => 'advice-6'], false],
			'decidesk refuses' => [$data, ['id' => 'advice-7'], true],
			'decidesk refuses without id' => [$data, [], true],
		];
	}//end worlds()

	/**
	 * The calls and the answer match the recording.
	 *
	 * @param array<string, mixed> $data    The advice request data.
	 * @param mixed                $saved   What the first save answers.
	 * @param bool                 $refuses Whether decidesk refuses.
	 *
	 * @dataProvider worlds
	 *
	 * @return void
	 */
	public function testRequestAdviceMatchesTheRecording(array $data, mixed $saved, bool $refuses): void {
		try {
			$answer = $this->service(saved: $saved, refuses: $refuses)->requestAdvice(caseId: 'case-1', data: $data, requestedBy: 'bob');
		} catch (RuntimeException $e) {
			$answer = ['thrown' => $e->getMessage(), 'previous' => $e->getPrevious()?->getMessage()];
		}

		$name = trim(strtolower((string)preg_replace('/[^a-z]+/i', '-', $this->dataName())), '-');
		$this->assertMatchesGolden(name: 'advice-service-request/' . $name, value: ['calls' => $this->log, 'answer' => $answer]);
	}//end testRequestAdviceMatchesTheRecording()

	/**
	 * The service over recording collaborators.
	 *
	 * @param mixed $saved   What the first save answers.
	 * @param bool  $refuses Whether decidesk refuses.
	 *
	 * @return AdviceService
	 */
	private function service(mixed $saved, bool $refuses): AdviceService {
		$objects = $this->createMock(originalClassName: AdviceGoldenSaveStub::class);
		$objects->method('saveObject')->willReturnCallback(
			function (mixed $object = null, mixed $register = null, mixed $schema = null, ?string $uuid = null) use ($saved): mixed {
				$this->log[] = ['saveObject' => ['object' => $object, 'register' => $register, 'schema' => $schema, 'uuid' => $uuid]];
				return $saved;
			}
		);

		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($objects);
		$settings->method('getConfigValue')->willReturnCallback(
			function (string $key): string {
				$this->log[] = ['getConfigValue' => $key];
				return ['register' => 'dossiq'][$key] ?? '';
			}
		);

		$delegation = $this->createMock(originalClassName: AdviceDelegationService::class);
		$delegation->method('raiseAdviceDecision')->willReturnCallback(
			function (string $subjectSchema, string $subjectId, array $payload = []) use ($refuses): string {
				$this->log[] = ['raiseAdviceDecision' => ['subjectSchema' => $subjectSchema, 'subjectId' => $subjectId, 'payload' => $payload]];
				if ($refuses === true) {
					throw new RuntimeException('decidesk down');
				}

				return 'decision-9';
			}
		);

		$notifier = $this->createMock(originalClassName: AdviceNotifier::class);
		$notifier->method('notifyAdviseur')->willReturnCallback(
			function (string $caseId, array $payload, array $saved): void {
				$this->log[] = ['notifyAdviseur' => ['caseId' => $caseId, 'payload' => $payload, 'saved' => $saved]];
			}
		);

		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		foreach (['info', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->log[] = [$level => $message];
				}
			);
		}

		return new AdviceService(
			settingsService: $settings,
			userSession: $this->createMock(originalClassName: IUserSession::class),
			logger: $logger,
			adviceDelegation: $delegation,
			repository: $this->createMock(originalClassName: AdviceRepository::class),
			guard: $this->createMock(originalClassName: AdviceAuthorizationGuard::class),
			notifier: $notifier,
			dates: $this->caseDates(),
		);
	}//end service()
}//end class
