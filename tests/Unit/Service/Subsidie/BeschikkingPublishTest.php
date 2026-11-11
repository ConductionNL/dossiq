<?php

/**
 * BeschikkingService::publish() Unit Tests.
 *
 * Pins who may be published: only a signed beschikking, which is granted
 * with its publication day and bezwaartermijn; an unknown one, one whose
 * lookup fails, an unsigned one and a failed save are each refused.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Subsidie
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Subsidie;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Subsidie\BeschikkingService;
use OCA\Dossiq\Service\Subsidie\SubsidieService;
use OCA\Dossiq\Tests\Unit\Service\FakeStoredObject;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for BeschikkingService::publish().
 */
class BeschikkingPublishTest extends TestCase {
	/**
	 * Stored beschikkingen by id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public array $store = [];

	/**
	 * Saved changes, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $saved = [];

	/**
	 * The service over an in-memory store.
	 *
	 * @param string $fail '' for none, 'find' or 'save' to make that call throw.
	 *
	 * @return BeschikkingService
	 */
	private function service(string $fail = ''): BeschikkingService {
		$test = $this;
		$objects = new class ($test, $fail) {
			/**
			 * Constructor.
			 *
			 * @param BeschikkingPublishTest $test Where rows live.
			 * @param string                 $fail Which call throws.
			 */
			public function __construct(private BeschikkingPublishTest $test, private string $fail) {
			}//end __construct()

			/**
			 * @param int|string      $id       The id.
			 * @param array|null      $_extend  Unused.
			 * @param bool            $files    Unused.
			 * @param string|int|null $register Unused.
			 * @param string|int|null $schema   Unused.
			 *
			 * @return FakeStoredObject The row.
			 */
			public function find(int|string $id, ?array $_extend = [], bool $files = false, string|int|null $register = null, string|int|null $schema = null): FakeStoredObject {
				if ($this->fail === 'find') {
					throw new RuntimeException('store down');
				}

				if (isset($this->test->store[(string)$id]) === false) {
					throw new DoesNotExistException('missing');
				}

				return new FakeStoredObject($this->test->store[(string)$id]);
			}//end find()

			/**
			 * @param array<string, mixed> $object   The changes.
			 * @param array|null           $extend   Unused.
			 * @param string|int|null      $register Unused.
			 * @param string|int|null      $schema   Unused.
			 * @param string|null          $uuid     The id.
			 *
			 * @return FakeStoredObject The saved row.
			 */
			public function saveObject(array $object, ?array $extend = [], string|int|null $register = null, string|int|null $schema = null, ?string $uuid = null): FakeStoredObject {
				if ($this->fail === 'save') {
					throw new RuntimeException('disk full');
				}

				$this->test->saved[] = $object;
				$merged = array_merge(($this->test->store[(string)$uuid] ?? []), $object, ['id' => $uuid]);
				$this->test->store[(string)$uuid] = $merged;
				return new FakeStoredObject($merged);
			}//end saveObject()
		};
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objects);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => match ($key) {
				'register' => 'dossiq',
				'subsidie_beschikking_schema' => 'beschikking',
				default => $default,
			}
		);
		$logger = $this->createMock(LoggerInterface::class);

		return new BeschikkingService($settings, new SubsidieService($settings, $logger), $this->createMock(IUserSession::class), $logger);
	}//end service()

	/**
	 * The refusal a publish answers, or 'published'.
	 *
	 * @param BeschikkingService $service The service.
	 * @param string             $id      The beschikking.
	 *
	 * @return string The refusal.
	 */
	private static function outcome(BeschikkingService $service, string $id): string {
		try {
			$service->publish($id);
			return 'published';
		} catch (OCSBadRequestException $e) {
			return $e->getMessage();
		}
	}//end outcome()

	/**
	 * A signed beschikking is granted with its publication day and bezwaartermijn.
	 *
	 * @return void
	 */
	public function testASignedBeschikkingIsPublished(): void {
		$this->store['b-1'] = ['id' => 'b-1', 'status' => 'draft', 'signedBy' => 'wethouder'];

		$result = $this->service()->publish('b-1');

		self::assertSame('granted', $result['status']);
		self::assertSame('granted', $this->saved[0]['status']);
		self::assertSame('wethouder', $this->saved[0]['signedBy']);
		self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $this->saved[0]['publicationDate']);
		self::assertGreaterThan($this->saved[0]['publicationDate'], $this->saved[0]['objectionPeriodEnd']);
	}//end testASignedBeschikkingIsPublished()

	/**
	 * Unknown, unreadable, unsigned and unsaved publishes are each refused.
	 *
	 * @return void
	 */
	public function testEveryOtherPublishIsRefused(): void {
		$this->store['b-2'] = ['id' => 'b-2', 'status' => 'draft', 'signedBy' => ''];
		$this->store['b-3'] = ['id' => 'b-3', 'status' => 'draft', 'signedBy' => 'wethouder'];

		self::assertSame(
			['Beschikking niet gevonden', 'Beschikking niet gevonden', 'Beschikking moet eerst worden ondertekend', 'Kon beschikking niet publiceren'],
			[
				self::outcome($this->service(), 'missing'),
				self::outcome($this->service('find'), 'b-3'),
				self::outcome($this->service(), 'b-2'),
				self::outcome($this->service('save'), 'b-3'),
			]
		);
		self::assertSame([], $this->saved);
	}//end testEveryOtherPublishIsRefused()
}//end class
