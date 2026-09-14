<?php

/**
 * The gateway that reads OpenRegister's grants, and answers null rather than guessing.
 *
 * 🔴 THE THREE ABSENCES THAT MUST NOT LOOK LIKE A REFUSAL.
 *
 * OpenRegister can fail to answer in three ways, and dossiq runs against
 * builds where each of them is normal: the app is not installed, the app is
 * installed but predates openregister#3726 so `provenanceFor()` does not
 * exist, and the call throws. All three must come back as null, because the
 * caller reads null as "carry on" and a false would empty every case's action
 * list on every instance that has not upgraded yet.
 *
 * The mirror assertion matters as much: `refuses()` must answer null for a
 * record with no `granted` key. A missing key read as `false` would silently
 * grant, and read as `true` would silently refuse, and neither of those is
 * visible in a log.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Access
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
 *
 * @spec openspec/changes/case-grants-name-their-source/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Access;

use OCA\Dossiq\Service\Access\OpenRegisterGrantsGateway;
use OCA\Dossiq\Service\SettingsService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Drives the gateway over a container we dictate.
 *
 * @spec openspec/changes/case-grants-name-their-source/specs/case-management/spec.md
 */
class OpenRegisterGrantsGatewayTest extends TestCase {

	/**
	 * A case uuid.
	 *
	 * @var string
	 */
	private const CASE_ID = 'c7b1f0de-0a6c-4a1e-9a0e-3b1f0de0a6c4';

	/**
	 * Build the gateway over an app manager and a container we dictate.
	 *
	 * @param bool                 $installed Whether OpenRegister is installed.
	 * @param array<string, mixed> $services  Class name to the service to answer with.
	 *
	 * @return OpenRegisterGrantsGateway The gateway.
	 */
	private function gateway(bool $installed, array $services = []): OpenRegisterGrantsGateway {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn($installed);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($services): object {
				if (isset($services[$id]) === false) {
					throw new RuntimeException(sprintf('nothing answers to %s', $id));
				}

				return $services[$id];
			}
		);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getConfigValue')->willReturn('case');
		$settings->method('getObjectService')->willReturn(null);

		return new OpenRegisterGrantsGateway(
			appManager: $appManager,
			container: $container,
			settingsService: $settings,
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end gateway()

	/**
	 * An OpenRegister that is not installed answers null, not a refusal.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-grants-name-their-source/specs/case-management/spec.md
	 */
	public function testAnAbsentOpenRegisterAnswersNull(): void {
		self::assertNull(
			$this->gateway(installed: false)->provenanceForCase(caseId: self::CASE_ID, actions: ['update'])
		);
	}//end testAnAbsentOpenRegisterAnswersNull()

	/**
	 * An OpenRegister without the provenance reader answers null, not a refusal.
	 *
	 * This is the build that predates openregister#3726. The service resolves,
	 * and calling the method would be a fatal Error inside a lifecycle
	 * provider, which OpenRegister answers with 502 and a timeline of disabled
	 * stages.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-grants-name-their-source/specs/case-management/spec.md
	 */
	public function testAnOpenRegisterWithoutTheProvenanceReaderAnswersNull(): void {
		$older = new class {
			/**
			 * The only method the older build has.
			 *
			 * @return bool Always true.
			 */
			public function hasPermission(): bool {
				return true;
			}
		};

		$gateway = $this->gateway(
			installed: true,
			services: ['OCA\OpenRegister\Service\Object\PermissionHandler' => $older],
		);

		self::assertNull($gateway->provenanceForCase(caseId: self::CASE_ID, actions: ['update']));
	}//end testAnOpenRegisterWithoutTheProvenanceReaderAnswersNull()

	/**
	 * A throwing evaluator answers null, not a refusal.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-grants-name-their-source/specs/case-management/spec.md
	 */
	public function testAThrowingEvaluatorAnswersNull(): void {
		$throwing = new class {
			/**
			 * Always fails.
			 *
			 * @param mixed $schema  Ignored.
			 * @param mixed $actions Ignored.
			 * @param mixed $userId  Ignored.
			 * @param mixed $object  Ignored.
			 *
			 * @return array<string, mixed> Never returns.
			 */
			public function provenanceFor($schema = null, $actions = [], $userId = null, $object = null): array {
				throw new RuntimeException('the rbac tables are unreachable');
			}
		};

		$schemaMapper = new class {
			/**
			 * Answers a schema.
			 *
			 * @param string $slug The slug.
			 *
			 * @return object The schema stand-in.
			 */
			public function find(string $slug): object {
				return new \stdClass();
			}
		};

		$gateway = $this->gateway(
			installed: true,
			services: [
				'OCA\OpenRegister\Service\Object\PermissionHandler' => $throwing,
				'OCA\OpenRegister\Db\SchemaMapper' => $schemaMapper,
			],
		);

		self::assertNull($gateway->provenanceForCase(caseId: self::CASE_ID, actions: ['update']));
	}//end testAThrowingEvaluatorAnswersNull()

	/**
	 * OpenRegister's answer is handed on key for key, and nothing is added.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-grants-name-their-source/specs/case-management/spec.md
	 */
	public function testTheAnswerIsHandedOnVerbatim(): void {
		$record = [
			'action' => 'update',
			'granted' => false,
			'source' => 'deny',
			'rule' => 'waarnemers',
			'principal' => 'waarnemers',
			'role' => null,
			'deny' => ['principal' => 'waarnemers', 'rule' => 'waarnemers'],
			'stagedDeny' => null,
			'wouldHaveBeenGrantedBy' => 'role',
		];

		$evaluator = new class($record) {
			/**
			 * Constructor.
			 *
			 * @param array<string, mixed> $record What to answer with.
			 */
			public function __construct(private readonly array $record) {
			}

			/**
			 * Answers the dictated record.
			 *
			 * @param mixed $schema  Ignored.
			 * @param mixed $actions Ignored.
			 * @param mixed $userId  Ignored.
			 * @param mixed $object  Ignored.
			 *
			 * @return array<string, mixed> The provenance.
			 */
			public function provenanceFor($schema = null, $actions = [], $userId = null, $object = null): array {
				return ['update' => $this->record];
			}
		};

		$schemaMapper = new class {
			/**
			 * Answers a schema.
			 *
			 * @param string $slug The slug.
			 *
			 * @return object The schema stand-in.
			 */
			public function find(string $slug): object {
				return new \stdClass();
			}
		};

		$gateway = $this->gateway(
			installed: true,
			services: [
				'OCA\OpenRegister\Service\Object\PermissionHandler' => $evaluator,
				'OCA\OpenRegister\Db\SchemaMapper' => $schemaMapper,
			],
		);

		self::assertSame(
			['update' => $record],
			$gateway->provenanceForCase(caseId: self::CASE_ID, actions: ['update']),
			'A summarised provenance is a second access decision that nobody updates.',
		);
	}//end testTheAnswerIsHandedOnVerbatim()

	/**
	 * A record with no verdict answers null, so nobody reads a missing key as a no.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-grants-name-their-source/specs/case-management/spec.md
	 */
	public function testAVerdictThatIsNotThereIsNotGuessed(): void {
		$gateway = $this->gateway(installed: false);

		self::assertNull($gateway->refuses(record: null));
		self::assertNull($gateway->refuses(record: ['action' => 'update', 'source' => 'role']));
		self::assertTrue($gateway->refuses(record: ['granted' => false]));
		self::assertFalse($gateway->refuses(record: ['granted' => true]));
	}//end testAVerdictThatIsNotThereIsNotGuessed()
}//end class
