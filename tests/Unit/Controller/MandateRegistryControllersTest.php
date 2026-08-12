<?php

/**
 * Mandate registry controller Unit Tests
 *
 * Covers MandaatRegistryController, OrganisatieRolController and
 * TermijnDefinitieController — the three surfaces that close procest#794.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/mandaat-matrix/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Controller;

use OCA\Procest\Controller\MandaatRegistryController;
use OCA\Procest\Controller\OrganisatieRolController;
use OCA\Procest\Controller\TermijnDefinitieController;
use OCA\Procest\Service\Mandaat\MandaatRegistryService;
use OCA\Procest\Service\Support\ConfiguredRegistryService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Unit tests for the three procest#794 admin controllers.
 *
 * @covers \OCA\Procest\Controller\MandaatRegistryController
 * @covers \OCA\Procest\Controller\OrganisatieRolController
 * @covers \OCA\Procest\Controller\TermijnDefinitieController
 */
class MandateRegistryControllersTest extends TestCase
{

    /**
     * Request, mocked.
     *
     * @var IRequest|\PHPUnit\Framework\MockObject\MockObject
     */
    private IRequest $request;

    /**
     * Logger, mocked.
     *
     * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private LoggerInterface $logger;

    /**
     * Mandate registry service, mocked.
     *
     * @var MandaatRegistryService|\PHPUnit\Framework\MockObject\MockObject
     */
    private MandaatRegistryService $registry;

    /**
     * Generic registry service, mocked.
     *
     * @var ConfiguredRegistryService|\PHPUnit\Framework\MockObject\MockObject
     */
    private ConfiguredRegistryService $generic;

    /**
     * Set up mocks.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->request  = $this->createMock(IRequest::class);
        $this->logger   = $this->createMock(LoggerInterface::class);
        $this->registry = $this->createMock(MandaatRegistryService::class);
        $this->generic  = $this->createMock(ConfiguredRegistryService::class);
    }//end setUp()

    /**
     * Build the decision-registry controller.
     *
     * @return MandaatRegistryController The controller.
     */
    private function mandaatController(): MandaatRegistryController
    {
        return new MandaatRegistryController('procest', $this->request, $this->registry, $this->logger);
    }//end mandaatController()

    /**
     * Build the role/assignment controller.
     *
     * @return OrganisatieRolController The controller.
     */
    private function rolController(): OrganisatieRolController
    {
        return new OrganisatieRolController('procest', $this->request, $this->registry, $this->logger);
    }//end rolController()

    /**
     * Build the term-definition controller.
     *
     * @return TermijnDefinitieController The controller.
     */
    private function termijnController(): TermijnDefinitieController
    {
        return new TermijnDefinitieController('procest', $this->request, $this->generic, $this->logger);
    }//end termijnController()

    /**
     * Besluiten lists the mandateringsbesluit registry as a JSON array.
     *
     * @return void
     */
    public function testBesluitenListsTheDecisionRegistry(): void
    {
        $this->registry->expects($this->once())
            ->method('list')
            ->with(MandaatRegistryService::SCHEMA_BESLUIT)
            ->willReturn([['id' => 'b1']]);

        $response = $this->mandaatController()->besluiten();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame([['id' => 'b1']], $response->getData());
    }//end testBesluitenListsTheDecisionRegistry()

    /**
     * Rollen and toewijzingen each read their own schema, not each other's.
     *
     * @return void
     */
    public function testEachListingReadsItsOwnSchema(): void
    {
        $seen = [];
        $this->registry->method('list')->willReturnCallback(
            static function (string $key) use (&$seen): array {
                $seen[] = $key;
                return [];
            }
        );

        $controller = $this->rolController();
        $controller->rollenIndex();
        $controller->toewijzingenIndex();

        $this->assertSame(
            [MandaatRegistryService::SCHEMA_ROL, MandaatRegistryService::SCHEMA_TOEWIJZING],
            $seen
        );
    }//end testEachListingReadsItsOwnSchema()

    /**
     * Create answers 201 and strips the routing noise from the payload.
     *
     * @return void
     */
    public function testCreateAnswers201AndStripsRoutingParams(): void
    {
        $this->request->method('getParams')->willReturn(
            ['rolNaam' => 'Vergunningverlener', '_route' => 'procest.organisatieRol.rollenCreate', 'id' => 'spoofed']
        );

        $this->registry->expects($this->once())
            ->method('save')
            ->with(
                MandaatRegistryService::SCHEMA_ROL,
                ['rolNaam' => 'Vergunningverlener'],
                null
            )
            ->willReturn(['id' => 'new']);

        $response = $this->rolController()->rollenCreate();

        $this->assertSame(Http::STATUS_CREATED, $response->getStatus());
    }//end testCreateAnswers201AndStripsRoutingParams()

    /**
     * 🔴 A client-supplied `id` must not override the routed one.
     *
     * The path id is authoritative; accepting the body's would let a caller
     * update a different record than the URL names.
     *
     * @return void
     */
    public function testTheRoutedIdWinsOverAClientSuppliedOne(): void
    {
        $this->request->method('getParams')->willReturn(['rolNaam' => 'X', 'id' => 'attacker-choice']);

        $this->registry->expects($this->once())
            ->method('save')
            ->with(MandaatRegistryService::SCHEMA_ROL, ['rolNaam' => 'X'], 'routed-id')
            ->willReturn([]);

        $this->rolController()->rollenUpdate('routed-id');
    }//end testTheRoutedIdWinsOverAClientSuppliedOne()

    /**
     * An unconfigured registry surfaces as 422, not a 500.
     *
     * @return void
     */
    public function testAnUnconfiguredRegistryAnswers422(): void
    {
        $this->request->method('getParams')->willReturn(['rolNaam' => 'X']);
        $this->registry->method('save')->willThrowException(new RuntimeException('Not configured: no register'));

        $response = $this->rolController()->rollenCreate();

        $this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
    }//end testAnUnconfiguredRegistryAnswers422()

    /**
     * An unexpected failure surfaces as 500 and is logged.
     *
     * @return void
     */
    public function testAnUnexpectedFailureAnswers500(): void
    {
        $this->request->method('getParams')->willReturn(['rolNaam' => 'X']);
        $this->registry->method('save')->willThrowException(new \LogicException('boom'));
        $this->logger->expects($this->once())->method('error');

        $response = $this->rolController()->rollenCreate();

        $this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
    }//end testAnUnexpectedFailureAnswers500()

    /**
     * Deleting an unreferenced role answers 200.
     *
     * Negative control for the 409 test below.
     *
     * @return void
     */
    public function testDeletingAnUnreferencedRoleAnswers200(): void
    {
        $this->registry->expects($this->once())->method('deleteRole')->with('role-1');

        $response = $this->rolController()->rollenDestroy('role-1');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
    }//end testDeletingAnUnreferencedRoleAnswers200()

    /**
     * 🔴 A referenced role answers 409 and the refusal names what blocks it.
     *
     * @return void
     */
    public function testDeletingAReferencedRoleAnswers409WithAReason(): void
    {
        $this->registry->method('deleteRole')->willThrowException(
            new RuntimeException('This role cannot be deleted while it is still referenced by 2 mandaat(en)')
        );

        $response = $this->rolController()->rollenDestroy('role-1');

        $this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
        $this->assertStringContainsString('2 mandaat(en)', $response->getData()['message']);
    }//end testDeletingAReferencedRoleAnswers409WithAReason()

    /**
     * Term definitions list every version, unfiltered.
     *
     * @return void
     */
    public function testTermijnDefinitiesListsEveryVersion(): void
    {
        $this->generic->expects($this->once())
            ->method('list')
            ->with('termijn_definitie_schema')
            ->willReturn([['version' => 1], ['version' => 2]]);

        $response = $this->termijnController()->index();

        $this->assertCount(2, $response->getData());
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
    }//end testTermijnDefinitiesListsEveryVersion()

    /**
     * A term-definition update targets the routed id.
     *
     * @return void
     */
    public function testTermijnDefinitieUpdateTargetsTheRoutedId(): void
    {
        $this->request->method('getParams')->willReturn(['validUntil' => '2026-08-11', '_route' => 'x']);

        $this->generic->expects($this->once())
            ->method('save')
            ->with('termijn_definitie_schema', ['validUntil' => '2026-08-11'], 'def-3')
            ->willReturn([]);

        $response = $this->termijnController()->update('def-3');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
    }//end testTermijnDefinitieUpdateTargetsTheRoutedId()

    /**
     * Mandaat create and update both route to the mandaat schema.
     *
     * @return void
     */
    public function testMandaatWritesUseTheMandaatSchema(): void
    {
        $this->request->method('getParams')->willReturn(['mandaatNummer' => 'M-1']);

        $seen = [];
        $this->registry->method('save')->willReturnCallback(
            static function (string $key, array $data, ?string $id) use (&$seen): array {
                $seen[] = [$key, $id];
                return [];
            }
        );

        $controller = $this->mandaatController();
        $controller->mandatenCreate();
        $controller->mandatenUpdate('m-9');

        $this->assertSame(
            [
                [MandaatRegistryService::SCHEMA_MANDAAT, null],
                [MandaatRegistryService::SCHEMA_MANDAAT, 'm-9'],
            ],
            $seen
        );
    }//end testMandaatWritesUseTheMandaatSchema()
}//end class
