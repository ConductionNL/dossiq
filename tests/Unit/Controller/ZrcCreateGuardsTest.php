<?php

/**
 * What ZrcController refuses before a create and what its body pre-validation reaches.
 *
 * Characterisation written against the unsplit methods and run green on them
 * before they were split. Allowed and refused outcomes are pinned side by side.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\ZrcController;
use OCA\Dossiq\Service\CaseRelationService;
use OCA\Dossiq\Service\Zaakdossier\DocumentJoinHoming;
use OCA\Dossiq\Service\Zgw\ZrcStatusEffects;
use OCA\Dossiq\Service\ZgwService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Controller\ZrcController
 */
class ZrcCreateGuardsTest extends TestCase {

	/**
	 * Scopes the consumer holds.
	 *
	 * @var array<string, bool>
	 */
	private array $scopes = ['zaken.aanmaken' => true, 'zaken.bijwerken' => true, 'zaken.heropenen' => true];

	/**
	 * What JWT validation answers.
	 *
	 * @var JSONResponse|null
	 */
	private ?JSONResponse $jwtError = null;

	/**
	 * What getObjectService answers.
	 *
	 * @var object|null
	 */
	private ?object $store = null;

	/**
	 * The request body, or a Throwable to throw.
	 *
	 * @var mixed
	 */
	private mixed $body = [];

	/**
	 * Debug lines logged.
	 *
	 * @var array<int, string>
	 */
	private array $debug = [];

	/**
	 * Whether the status is a reopen attempt, and the usage-rights refusal.
	 *
	 * @var array{reopen: bool, usage: array<string, mixed>|null}
	 */
	private array $status = ['reopen' => false, 'usage' => null];

	/**
	 * The join homing refusal.
	 *
	 * @var string|null
	 */
	private ?string $homing = null;

	/**
	 * The controller over the stubs above.
	 *
	 * @return ZrcController
	 */
	private function controller(): ZrcController {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('debug')->willReturnCallback(
			function (string $message): void {
				$this->debug[] = $message;
			}
		);

		$zgw = $this->createMock(ZgwService::class);
		$zgw->method('validateJwtAuth')->willReturnCallback(fn (): ?JSONResponse => $this->jwtError);
		$zgw->method('consumerHasScope')->willReturnCallback(fn ($r, $c, string $scope): bool => ($this->scopes[$scope] ?? false));
		$zgw->method('getObjectService')->willReturnCallback(fn (): ?object => $this->store);
		$zgw->method('unavailableResponse')->willReturn(new JSONResponse(['detail' => 'unavailable'], 503));
		$zgw->method('getLogger')->willReturn($logger);
		$zgw->method('getZgwMappingService')->willThrowException(new \RuntimeException('products check reached'));
		$zgw->method('getRequestBody')->willReturnCallback(
			function (): array {
				if ($this->body instanceof \Throwable) {
					throw $this->body;
				}

				return $this->body;
			}
		);

		$effects = $this->createMock(ZrcStatusEffects::class);
		$effects->method('isReopenAttempt')->willReturnCallback(fn (): bool => $this->status['reopen']);
		$effects->method('unsetUsageRightsRefusal')->willReturnCallback(fn (): ?array => $this->status['usage']);

		$homing = $this->createMock(DocumentJoinHoming::class);
		$homing->method('refusal')->willReturnCallback(fn (): ?string => $this->homing);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new ZrcController(
			request: $this->createMock(IRequest::class),
			zgwService: $zgw,
			l10n: $l10n,
			caseRelationService: $this->createMock(CaseRelationService::class),
			statusEffects: $effects,
			joinHoming: $homing,
		);
	}//end controller()

	/**
	 * Call a private method of a fresh controller.
	 *
	 * @param string               $method The method.
	 * @param array<string, mixed> $args   Named arguments.
	 *
	 * @return mixed
	 */
	private function call(string $method, array $args): mixed {
		$controller = $this->controller();
		$reflection = new \ReflectionMethod($controller, $method);
		return $reflection->invokeArgs($controller, $args);
	}//end call()

	/**
	 * A create is refused for a bad token, a missing scope or an unreachable store, in that order; otherwise allowed.
	 *
	 * @return void
	 */
	public function testCreateRefusalsAndTheAllowedCreate(): void {
		$this->store = new \stdClass();
		$this->assertNull($this->call('createRefusal', ['resource' => 'zaken']));
		$this->assertNull($this->call('createRefusal', ['resource' => 'rollen']));

		$this->scopes['zaken.aanmaken'] = false;
		$this->assertSame(403, $this->call('createRefusal', ['resource' => 'zaken'])->getStatus());
		$this->assertSame('permission_denied', $this->call('createRefusal', ['resource' => 'zaken'])->getData()['code']);
		$this->assertNull($this->call('createRefusal', ['resource' => 'rollen']));

		$this->scopes = ['zaken.aanmaken' => true];
		$this->assertSame(403, $this->call('createRefusal', ['resource' => 'rollen'])->getStatus());
		$this->assertNull($this->call('createRefusal', ['resource' => 'zaken']));

		$this->store = null;
		$this->assertSame(503, $this->call('createRefusal', ['resource' => 'zaken'])->getStatus());
		$this->scopes = [];
		$this->assertSame(403, $this->call('createRefusal', ['resource' => 'zaken'])->getStatus());

		$this->jwtError = new JSONResponse(['detail' => 'bad token'], 401);
		$this->assertSame($this->jwtError, $this->call('createRefusal', ['resource' => 'zaken']));
	}//end testCreateRefusalsAndTheAllowedCreate()

	/**
	 * Before the save: a reopen needs heropenen, an eindstatus needs usage rights, a join needs a folder.
	 *
	 * @return void
	 */
	public function testTheChecksBeforeTheSave(): void {
		$args = ['resource' => 'statussen', 'originalBody' => ['a' => 1], 'body' => []];
		$this->assertNull($this->call('beforeCreateSave', $args));

		$this->status['reopen'] = true;
		$this->assertNull($this->call('beforeCreateSave', $args));
		$this->scopes['zaken.heropenen'] = false;
		$this->assertSame(403, $this->call('beforeCreateSave', $args)->getStatus());

		$this->status['reopen'] = false;
		$this->status['usage'] = ['detail' => 'usage rights'];
		$refusal = $this->call('beforeCreateSave', $args);
		$this->assertSame(400, $refusal->getStatus());
		$this->assertSame(['detail' => 'usage rights'], $refusal->getData());

		$this->homing = 'no folder';
		$this->assertNull($this->call('beforeCreateSave', ['resource' => 'rollen', 'originalBody' => [], 'body' => []]));
		$join = $this->call('beforeCreateSave', ['resource' => 'zaakinformatieobjecten', 'originalBody' => ['zaak' => 'z'], 'body' => []]);
		$this->assertSame(422, $join->getStatus());
		$this->assertSame(['detail' => 'no folder'], $join->getData());
		$this->homing = null;
		$this->assertNull($this->call('beforeCreateSave', ['resource' => 'zaakinformatieobjecten', 'originalBody' => ['zaak' => 'z'], 'body' => []]));
	}//end testTheChecksBeforeTheSave()

	/**
	 * Pre-validation reaches the products check only with products, a store and a case type; failures are logged at debug.
	 *
	 * @return void
	 */
	public function testThePreValidationReachesTheProductsCheckOnlyWhenItCan(): void {
		$this->store = new \stdClass();
		foreach ([
			['productenOfDiensten' => [], 'caseType' => 'ct'],
			['productenOfDiensten' => 'x', 'caseType' => 'ct'],
			['productenOfDiensten' => ['p']],
			['productenOfDiensten' => [], 'caseType' => 'https://ztc/zaaktypen/aaaaaaaa-1111-2222-3333-444444444444'],
			['communicatiekanaal' => ''],
			[],
		] as $body) {
			$this->body = $body;
			$this->assertNull($this->call('preValidateCaseBody', ['isPatch' => false]));
		}

		$this->assertSame([], $this->debug);

		$this->store = null;
		$this->body = ['productenOfDiensten' => ['p'], 'caseType' => 'https://ztc/zaaktypen/aaaaaaaa-1111-2222-3333-444444444444'];
		$this->assertNull($this->call('preValidateCaseBody', ['isPatch' => true]));
		$this->assertSame([], $this->debug);

		$this->body = new \RuntimeException('no body');
		$this->assertNull($this->call('preValidateCaseBody', ['isPatch' => false]));
		$this->assertSame(['preValidateZaakBody: no body'], $this->debug);

		$this->debug = [];
		$this->store = new \stdClass();
		$this->body = ['productenOfDiensten' => ['p'], 'caseType' => 'https://ztc/zaaktypen/aaaaaaaa-1111-2222-3333-444444444444'];
		$this->assertNull($this->call('preValidateCaseBody', ['isPatch' => false]));
		$this->assertSame(['preValidateZaakBody: products check reached'], $this->debug);

		$this->body = ['communicatiekanaal' => 'not a url'];
		$this->assertSame('bad-url', $this->call('preValidateCaseBody', ['isPatch' => false])->getData()['invalidParams'][0]['code']);
	}//end testThePreValidationReachesTheProductsCheckOnlyWhenItCan()
}//end class
