<?php

/**
 * Record Template Reader Test
 *
 * dossiq reads a named OpenRegister record template as the signed-in caller.
 * The doubles carry the signatures of OpenRegister's `RecordTemplateService`,
 * `ViewerReachResolver` and `RecordTemplate` on development (12 Oct 2026) and
 * are called with named arguments, so a renamed parameter fails here.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\RecordTemplate
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/woo-request-corpus-collection/specs/record-template-start/spec.md#requirement-a-named-record-template-is-read-as-the-caller-req-rts-001
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\RecordTemplate;

use Exception;
use OCA\Dossiq\Service\RecordTemplate\RecordTemplateReader;
use OCP\App\IAppManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\RecordTemplate\RecordTemplateReader
 */
class RecordTemplateReaderTest extends TestCase {

	private const TEMPLATE = '77777777-7777-4777-8777-777777777777';

	/**
	 * The signed-in user, '' for nobody.
	 *
	 * @var string
	 */
	private string $uid = 'pjansen';

	/**
	 * Whether OpenRegister is installed.
	 *
	 * @var boolean
	 */
	private bool $installed = true;

	/**
	 * The templates OpenRegister holds, by uuid.
	 *
	 * @var array<string, array{schema: string, values: array<string, mixed>, name: string}>
	 */
	private array $templates = [
		self::TEMPLATE => ['schema' => '42', 'name' => 'Ruimtelijke verzoeken', 'values' => ['terms' => 'Stationsweg']],
	];

	/**
	 * The reach the service was last asked for.
	 *
	 * @var object|null
	 */
	private ?object $askedAs = null;

	/**
	 * The reader over OpenRegister doubles.
	 *
	 * @return RecordTemplateReader The reader.
	 */
	private function reader(): RecordTemplateReader {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturnCallback(fn (): string => $this->uid);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturnCallback(fn (): ?IUser => $this->uid === '' ? null : $user);
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->willReturnCallback(fn (): bool => $this->installed);

		$templates = $this->templates;
		$service = new class ($templates, $this) {

			/**
			 * @param array<string, array<string, mixed>> $templates The store.
			 * @param RecordTemplateReaderTest            $test      Records the reach.
			 */
			public function __construct(private array $templates, private RecordTemplateReaderTest $test) {
			}//end __construct()

			/**
			 * OpenRegister's `RecordTemplateService::get(string $uuid, ViewerReach $reach)`.
			 */
			public function get(string $uuid, object $reach): object {
				$this->test->asked($reach);
				if (isset($this->templates[$uuid]) === false) {
					throw new Exception('No such record template.', 404);
				}

				if ($this->templates[$uuid]['schema'] === 'broken') {
					throw new Exception('The store is down.', 500);
				}

				$row = $this->templates[$uuid];
				return new class ($uuid, $row) {

					/**
					 * @param array<string, mixed> $row The row.
					 */
					public function __construct(private string $uuid, private array $row) {
					}//end __construct()

					public function getUuid(): ?string {
						return $this->uuid;
					}//end getUuid()

					public function getName(): ?string {
						return $this->row['name'];
					}//end getName()

					public function getSchema(): ?string {
						return $this->row['schema'];
					}//end getSchema()

					/**
					 * @return array<string, mixed>
					 */
					public function getValues(): array {
						return $this->row['values'];
					}//end getValues()
				};
			}//end get()
		};
		$resolver = new class () {

			/**
			 * OpenRegister's `ViewerReachResolver::reachOf(string $userId)`.
			 */
			public function reachOf(string $userId): object {
				return (object)['userId' => $userId, 'groups' => [], 'isAdmin' => false];
			}//end reachOf()
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id): object => match ($id) {
				'OCA\OpenRegister\Service\RecordTemplate\RecordTemplateService' => $service,
				'OCA\OpenRegister\Service\Rbac\ViewerReachResolver' => $resolver,
				default => throw new RuntimeException('unexpected ' . $id),
			}
		);

		return new RecordTemplateReader(appManager: $apps, container: $container, userSession: $session);
	}//end reader()

	/**
	 * Keep the reach the service was asked for.
	 *
	 * @param object $reach The reach.
	 *
	 * @return void
	 */
	public function asked(object $reach): void {
		$this->askedAs = $reach;
	}//end asked()

	/**
	 * REQ-RTS-001 "A handler reads a template they may use".
	 *
	 * @return void
	 */
	public function testATemplateIsReadAsTheSignedInCaller(): void {
		$template = $this->reader()->find(uuid: self::TEMPLATE, schema: '42');

		self::assertSame(['uuid' => self::TEMPLATE, 'name' => 'Ruimtelijke verzoeken', 'values' => ['terms' => 'Stationsweg']], $template);
		self::assertSame('pjansen', $this->askedAs->userId, 'OpenRegister decides for the caller, not for the app');
	}//end testATemplateIsReadAsTheSignedInCaller()

	/**
	 * REQ-RTS-001 "A template the caller may not use reads as absent": OpenRegister
	 * answers 404 for both, and so does dossiq.
	 *
	 * @return void
	 */
	public function testAnAbsentOrUnusableTemplateIsNull(): void {
		self::assertNull($this->reader()->find(uuid: '88888888-8888-4888-8888-888888888888', schema: '42'));
	}//end testAnAbsentOrUnusableTemplateIsNull()

	/**
	 * REQ-RTS-001 "A template of another schema is not used".
	 *
	 * @return void
	 */
	public function testATemplateOfAnotherSchemaIsNull(): void {
		self::assertNull($this->reader()->find(uuid: self::TEMPLATE, schema: '43'));
		self::assertNull($this->reader()->find(uuid: self::TEMPLATE, schema: ''), 'no schema to compare with is no match');
	}//end testATemplateOfAnotherSchemaIsNull()

	public function testWithoutASessionNothingIsRead(): void {
		$this->uid = '';

		self::assertNull($this->reader()->find(uuid: self::TEMPLATE, schema: '42'));
		self::assertNull($this->askedAs, 'OpenRegister is not asked on behalf of nobody');
	}//end testWithoutASessionNothingIsRead()

	/**
	 * A failure is not an absence: the caller hears that the store failed.
	 *
	 * @return void
	 */
	public function testAFailingStoreIsNotReadAsAbsent(): void {
		$this->templates[self::TEMPLATE]['schema'] = 'broken';

		$this->expectException(Exception::class);
		$this->expectExceptionCode(500);
		$this->reader()->find(uuid: self::TEMPLATE, schema: '42');
	}//end testAFailingStoreIsNotReadAsAbsent()

	public function testWithoutOpenRegisterTheReaderSaysSo(): void {
		$this->installed = false;

		$this->expectException(RuntimeException::class);
		$this->reader()->find(uuid: self::TEMPLATE, schema: '42');
	}//end testWithoutOpenRegisterTheReaderSaysSo()
}//end class
