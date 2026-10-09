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

namespace Augias\ElectronicInvoicingBundle\Tests\Manager;

use Augias\ClientBundle\Entity\Client;
use Augias\ClientBundle\Test\Factory\ClientFactory;
use Augias\CoreBundle\Entity\Discount;
use Augias\ElectronicInvoicingBundle\Entity\ElectronicInvoiceProviderSetting;
use Augias\ElectronicInvoicingBundle\Entity\ElectronicInvoiceSubmission;
use Augias\ElectronicInvoicingBundle\Manager\ElectronicInvoiceManager;
use Augias\InstallBundle\Test\EnsureApplicationInstalled;
use Augias\InvoiceBundle\Entity\CreditNote;
use Augias\InvoiceBundle\Entity\CreditNoteLine;
use Augias\InvoiceBundle\Enum\CreditNoteStatus;
use Augias\InvoiceBundle\Listener\Workflow\SendIssuedCreditNoteElectronicallyListener;
use Augias\InvoiceBundle\Model\CreditNoteGraph;
use Augias\InvoiceBundle\Test\Factory\CreditNoteFactory;
use Augias\SettingsBundle\SystemConfig;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Workflow\Registry;
use function assert;

/**
 * A credit note to a business client goes through the electronic invoicing
 * platform as soon as it is issued, like an invoice; to a private individual
 * it does not (it is reported instead).
 */
#[CoversClass(SendIssuedCreditNoteElectronicallyListener::class)]
#[CoversClass(ElectronicInvoiceManager::class)]
final class CreditNoteElectronicInvoicingTest extends KernelTestCase
{
    use EnsureApplicationInstalled;

    protected function setUp(): void
    {
        parent::setUp();

        self::getContainer()->get(SystemConfig::class)->set(SystemConfig::ELECTRONIC_INVOICING_CONFIG_PATH, '1');

        $setting = new ElectronicInvoiceProviderSetting();
        $setting->setCompany($this->company)->setName('Test')->setProvider('test_provider')->setSettings([])->setActive(true);

        $this->entityManager()->persist($setting);
        $this->entityManager()->flush();
    }

    public function testIssuingACreditNoteToABusinessSendsIt(): void
    {
        $client = ClientFactory::createOne(['company' => $this->company, 'currencyCode' => 'EUR']);
        $client->setSiret('12345678900012');
        $this->entityManager()->flush();

        $creditNote = $this->issue($client);

        $submissions = $creditNote->getElectronicInvoiceSubmissions();
        self::assertCount(1, $submissions);

        $submission = $submissions->first();
        assert($submission instanceof ElectronicInvoiceSubmission);
        self::assertTrue($submission->isSuccess());
        self::assertSame($creditNote, $submission->getCreditNote());
        self::assertNull($submission->getInvoice());
        self::assertSame($creditNote->getCreditNoteId(), $submission->getDocumentNumber());
    }

    public function testACreditNoteToAPrivateIndividualIsNotSent(): void
    {
        $client = ClientFactory::createOne(['company' => $this->company, 'currencyCode' => 'EUR']);

        $creditNote = $this->issue($client);

        self::assertCount(0, $creditNote->getElectronicInvoiceSubmissions());
    }

    private function issue(Client $client): CreditNote
    {
        $creditNote = CreditNoteFactory::createOne([
            'company' => $this->company,
            'client' => $client,
            'status' => CreditNoteStatus::Draft,
            'discount' => new Discount(),
            'lines' => [new CreditNoteLine()->setDescription('Audit')->setPrice(10_000)->setQty(1)->updateTotal()],
        ]);

        $registry = self::getContainer()->get(Registry::class);
        assert($registry instanceof Registry);
        $registry->get($creditNote, 'credit_note')->apply($creditNote, CreditNoteGraph::TRANSITION_ISSUE);
        $this->entityManager()->flush();
        $this->entityManager()->refresh($creditNote);

        return $creditNote;
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = self::getContainer()->get('doctrine')->getManager();
        assert($entityManager instanceof EntityManagerInterface);

        return $entityManager;
    }
}
