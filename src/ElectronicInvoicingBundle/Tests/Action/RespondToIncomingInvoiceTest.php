<?php

declare(strict_types=1);

/*
 * This file is part of Augias project.
 *
 * (c) HERC SI <opensource@herc-si.fr>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Augias\ElectronicInvoicingBundle\Tests\Action;

use Augias\CoreBundle\Entity\Company;
use Augias\ElectronicInvoicingBundle\Action\RespondToIncomingInvoice;
use Augias\ElectronicInvoicingBundle\Entity\ElectronicInvoiceProviderSetting;
use Augias\ElectronicInvoicingBundle\Entity\ElectronicInvoiceReceipt;
use Augias\ElectronicInvoicingBundle\Enum\ReceiptResponse;
use Augias\ElectronicInvoicingBundle\Enum\ResponseReason;
use Augias\ElectronicInvoicingBundle\Provider\SuperPdp\SuperPdpClient;
use Augias\InstallBundle\Test\EnsureApplicationInstalled;
use Augias\UserBundle\Entity\User;
use Augias\UserBundle\Test\Factory\UserFactory;
use Brick\Math\BigInteger;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use function json_encode;

#[CoversClass(RespondToIncomingInvoice::class)]
final class RespondToIncomingInvoiceTest extends WebTestCase
{
    use EnsureApplicationInstalled;

    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();

        self::ensureKernelShutdown();

        $this->client = self::createClient();
        $this->client->disableReboot();

        $user = UserFactory::createOne(['companies' => [$this->company]]);
        self::assertInstanceOf(User::class, $user);
        $this->client->loginUser($user);

        $entityManager = self::getContainer()->get('doctrine')->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;

        // The platform takes whatever is sent; the answer is not over the network.
        self::getContainer()->set(SuperPdpClient::class, new SuperPdpClient(new MockHttpClient(
            static fn (string $method, string $url): MockResponse => new MockResponse((string) json_encode(
                str_ends_with($url, '/oauth2/token') ? ['access_token' => 'a-token'] : ['id' => 1],
            )),
        )));
    }

    public function testAnInvoiceCanBeAcceptedFromItsPage(): void
    {
        $receipt = $this->receipt();

        $crawler = $this->client->request('GET', '/electronic-invoicing/incoming/respond/' . $receipt->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Tricatel');

        $form = $crawler->filter('form[name="einvoicing_receipt_response"]')->form([
            'einvoicing_receipt_response[response]' => ReceiptResponse::Accepted->value,
        ]);
        $this->client->submit($form);

        self::assertResponseRedirects('/electronic-invoicing/incoming');
        self::assertSame(ReceiptResponse::Accepted, $this->reload($receipt)->getResponse());
    }

    public function testARefusalWithoutAReasonIsNotSent(): void
    {
        $receipt = $this->receipt();

        $crawler = $this->client->request('GET', '/electronic-invoicing/incoming/respond/' . $receipt->getId());
        $form = $crawler->filter('form[name="einvoicing_receipt_response"]')->form([
            'einvoicing_receipt_response[response]' => ReceiptResponse::Refused->value,
        ]);
        $this->client->submit($form);

        self::assertResponseIsSuccessful();
        self::assertNull($this->reload($receipt)->getResponse());
    }

    public function testAnAnsweredInvoiceOffersNoForm(): void
    {
        $receipt = $this->receipt()->setStatusCode(ReceiptResponse::Refused->value);
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/electronic-invoicing/incoming/respond/' . $receipt->getId());

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('form[name="einvoicing_receipt_response"]'));
    }

    /**
     * Once disputed, the page offers what ends the dispute: acceptance or
     * refusal, not a second dispute.
     */
    public function testADisputedInvoiceCanStillBeAnswered(): void
    {
        $receipt = $this->receipt();

        $crawler = $this->client->request('GET', '/electronic-invoicing/incoming/respond/' . $receipt->getId());
        $form = $crawler->filter('form[name="einvoicing_receipt_response"]')->form([
            'einvoicing_receipt_response[response]' => ReceiptResponse::Disputed->value,
            'einvoicing_receipt_response[reason]' => ResponseReason::Quantity->value,
        ]);
        $this->client->submit($form);

        self::assertResponseRedirects('/electronic-invoicing/incoming');
        self::assertSame(ReceiptResponse::Disputed, $this->reload($receipt)->getResponse());

        $crawler = $this->client->request('GET', '/electronic-invoicing/incoming/respond/' . $receipt->getId());

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('input[name="einvoicing_receipt_response[response]"][value="fr:205"]'));
        self::assertCount(1, $crawler->filter('input[name="einvoicing_receipt_response[response]"][value="fr:210"]'));
        self::assertCount(0, $crawler->filter('input[name="einvoicing_receipt_response[response]"][value="fr:207"]'));
    }

    private function receipt(): ElectronicInvoiceReceipt
    {
        // The company as this kernel's entity manager knows it.
        $company = $this->entityManager->find(Company::class, $this->company->getId());
        self::assertInstanceOf(Company::class, $company);

        $setting = new ElectronicInvoiceProviderSetting();
        $setting->setCompany($company)
            ->setName('SUPER PDP')
            ->setProvider('super_pdp')
            ->setSettings(['client_id' => 'id', 'client_secret' => 'secret'])
            ->setActive(true);
        $this->entityManager->persist($setting);

        $receipt = new ElectronicInvoiceReceipt();
        $receipt->setCompany($company)
            ->setProvider('super_pdp')
            ->setExternalReference('747283')
            ->setInvoiceNumber('TRI-SVC')
            ->setSellerName('Tricatel')
            ->setTotalAmount(BigInteger::of(60000))
            ->setCurrencyCode('EUR');
        $this->entityManager->persist($receipt);
        $this->entityManager->flush();

        return $receipt;
    }

    private function reload(ElectronicInvoiceReceipt $receipt): ElectronicInvoiceReceipt
    {
        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(ElectronicInvoiceReceipt::class, $receipt->getId());
        self::assertInstanceOf(ElectronicInvoiceReceipt::class, $reloaded);

        return $reloaded;
    }
}
