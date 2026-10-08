<?php

declare(strict_types=1);

/*
 * This file is part of Augias project.
 *
 * (c) Pierre du Plessis <open-source@solidworx.co>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Augias\SaasBundle\Tests\Functional;

use Augias\ClientBundle\Test\Factory\ClientFactory;
use Augias\ClientBundle\Test\Factory\ContactFactory;
use Augias\CoreBundle\Action\ViewBilling;
use Augias\CoreBundle\Activity\DocumentActivityRecorder;
use Augias\CoreBundle\Company\CompanySelector;
use Augias\CoreBundle\Contracts\EmailVerificationGateInterface;
use Augias\CoreBundle\Pdf\Generator;
use Augias\CoreBundle\Templates\BillingTemplateResolver;
use Augias\InstallBundle\Test\EnsureApplicationInstalled;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\InvoiceBundle\Enum\InvoiceStatus;
use Augias\InvoiceBundle\Test\Factory\InvoiceFactory;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Twig\Environment;

/**
 * Verifies the public invoice/quote view link returns 404 when the owning
 * company is gated by the SaaS email-verification gate, and serves the page
 * when the gate is open.
 */
#[Group('functional')]
final class PublicViewLinkGateTest extends KernelTestCase
{
    use EnsureApplicationInstalled;

    public function testReturnsNotFoundWhenCompanyGated(): void
    {
        $action = $this->buildAction(gated: true);

        $invoice = $this->createInvoice();

        $this->expectException(NotFoundHttpException::class);

        $action->invoiceAction(Request::create('/view/invoice/' . $invoice->getUuid()->toString()), $invoice->getUuid()->toString());
    }

    public function testReturnsArrayWhenCompanyNotGated(): void
    {
        $action = $this->buildAction(gated: false);

        $invoice = $this->createInvoice();

        $response = $action->invoiceAction(Request::create('/view/invoice/' . $invoice->getUuid()->toString()), $invoice->getUuid()->toString());

        self::assertIsArray($response);
        self::assertArrayHasKey('invoice', $response);
        self::assertSame($invoice->getId()->toString(), $response['invoice']->getId()->toString());
    }

    private function buildAction(bool $gated): ViewBilling
    {
        $container = self::getContainer();

        $gate = $this->createStub(EmailVerificationGateInterface::class);
        $gate->method('isCompanyGated')
            ->willReturn($gated);

        $authChecker = $this->createStub(AuthorizationCheckerInterface::class);
        $authChecker->method('isGranted')
            ->willReturn(false);

        return new ViewBilling(
            $container->get('doctrine'),
            $authChecker,
            $this->createStub(RouterInterface::class),
            $container->get(CompanySelector::class),
            $container->get(Generator::class),
            $container->get(Environment::class),
            $gate,
            $container->get(BillingTemplateResolver::class),
            $container->get(DocumentActivityRecorder::class),
        );
    }

    private function createInvoice(): Invoice
    {
        $client = ClientFactory::createOne(['company' => $this->company, 'currencyCode' => 'USD']);
        ContactFactory::createOne(['client' => $client, 'company' => $this->company]);

        $invoice = InvoiceFactory::createOne([
            'company' => $this->company,
            'client' => $client,
            'status' => InvoiceStatus::Pending,
        ]);

        return $invoice;
    }
}
