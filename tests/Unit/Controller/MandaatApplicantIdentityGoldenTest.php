<?php

/**
 * Golden record of how MandaatMatrixController derives the applicant identity.
 *
 * The belangenconflict check trusts only the applicant identity the controller
 * derives from the case object. This test drives that derivation through every
 * world it distinguishes and records the outcome and the log lines.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/authz-bypass-fixes/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\MandaatMatrixController;
use OCA\Dossiq\Service\MandaatCheckService;
use OCA\Dossiq\Service\MandaatEscalatieService;
use OCA\Dossiq\Service\MandaatGebruikService;
use OCA\Dossiq\Service\MandaatImportService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\MatchesGoldenFiles;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Typed stub for the OpenRegister ObjectService (named-argument safe).
 */
interface MandaatIdentityObjectServiceStub {
	/**
	 * Find a single object by ID.
	 *
	 * @param int|string $id       Object UUID.
	 * @param mixed      $register Register slug.
	 * @param mixed      $schema   Schema slug.
	 *
	 * @return mixed
	 */
	public function find(int|string $id, mixed $register = null, mixed $schema = null): mixed;
}//end interface

/**
 * Golden test for MandaatMatrixController::resolveApplicantIdentity.
 *
 * @covers \OCA\Dossiq\Controller\MandaatMatrixController
 */
class MandaatApplicantIdentityGoldenTest extends TestCase {
	use MatchesGoldenFiles;

	/**
	 * Log lines captured for the current world.
	 *
	 * @var list<string>
	 */
	private array $logLines = [];

	/**
	 * Every world the derivation distinguishes, recorded in one golden file.
	 *
	 * @return void
	 */
	public function testApplicantIdentityAcrossWorlds(): void {
		$person = ['id' => 'case-1', 'initiatorType' => 'person', 'initiatorSourceId' => '123456789'];
		$worlds = [
			'no object service' => ['service' => false, 'config' => ['register' => 'r', 'case_schema' => 's'], 'case' => $person],
			'no register' => ['service' => true, 'config' => ['case_schema' => 's'], 'case' => $person],
			'no case schema' => ['service' => true, 'config' => ['register' => 'r'], 'case' => $person],
			'lookup fails' => ['service' => true, 'config' => ['register' => 'r', 'case_schema' => 's'], 'case' => 'throw'],
			'case not found' => ['service' => true, 'config' => ['register' => 'r', 'case_schema' => 's'], 'case' => null],
			'company initiator' => ['service' => true, 'config' => ['register' => 'r', 'case_schema' => 's'], 'case' => ['initiatorType' => 'company', 'initiatorSourceId' => '12345678']],
			'contact initiator' => ['service' => true, 'config' => ['register' => 'r', 'case_schema' => 's'], 'case' => ['initiatorType' => 'contact', 'initiatorSourceId' => 'urn:x']],
			'no initiator type' => ['service' => true, 'config' => ['register' => 'r', 'case_schema' => 's'], 'case' => ['initiatorSourceId' => '123456789']],
			'person without number' => ['service' => true, 'config' => ['register' => 'r', 'case_schema' => 's'], 'case' => ['initiatorType' => 'person']],
			'person with empty number' => ['service' => true, 'config' => ['register' => 'r', 'case_schema' => 's'], 'case' => ['initiatorType' => 'person', 'initiatorSourceId' => '']],
			'person' => ['service' => true, 'config' => ['register' => 'r', 'case_schema' => 's'], 'case' => $person],
			'person with numeric number' => ['service' => true, 'config' => ['register' => 'r', 'case_schema' => 's'], 'case' => ['initiatorType' => 'person', 'initiatorSourceId' => 123456789]],
		];

		$record = [];
		foreach ($worlds as $name => $world) {
			$this->logLines = [];
			$controller = $this->controllerFor(world: $world);
			$ref = new ReflectionMethod(MandaatMatrixController::class, 'resolveApplicantIdentity');
			$ref->setAccessible(true);
			$record[$name] = [
				'identity' => $ref->invoke($controller, 'case-1'),
				'log' => $this->logLines,
			];
		}

		$this->assertMatchesGolden(name: 'mandaat-applicant-identity', value: $record);
	}//end testApplicantIdentityAcrossWorlds()

	/**
	 * Build a controller for one world.
	 *
	 * @param array<string, mixed> $world The world.
	 *
	 * @return MandaatMatrixController
	 */
	private function controllerFor(array $world): MandaatMatrixController {
		$objectService = $this->createMock(MandaatIdentityObjectServiceStub::class);
		if ($world['case'] === 'throw') {
			$objectService->method('find')->willThrowException(new RuntimeException('register offline'));
		} else {
			$objectService->method('find')->willReturn($world['case']);
		}

		$config = $world['config'];
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($world['service'] === true ? $objectService : null);
		$settings->method('getConfigValue')->willReturnCallback(
			static function (string $key) use ($config): string {
				return $config[$key] ?? '';
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'error', 'info', 'debug', 'notice'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message) use ($level): void {
					$this->logLines[] = $level . ': ' . $message;
				}
			);
		}

		return new MandaatMatrixController(
			appName: 'dossiq',
			request: $this->createMock(IRequest::class),
			userSession: $this->createMock(IUserSession::class),
			check: $this->createMock(MandaatCheckService::class),
			escalation: $this->createMock(MandaatEscalatieService::class),
			gebruik: $this->createMock(MandaatGebruikService::class),
			import: $this->createMock(MandaatImportService::class),
			settings: $settings,
			logger: $logger,
		);
	}//end controllerFor()
}//end class
