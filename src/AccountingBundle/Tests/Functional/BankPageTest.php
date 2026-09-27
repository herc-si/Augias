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

namespace Augias\AccountingBundle\Tests\Functional;

use Augias\ClientBundle\Entity\Client;
use Augias\ClientBundle\Test\Factory\ClientFactory;
use Augias\CoreBundle\Entity\Company;
use Augias\CoreBundle\Test\Traits\DoctrineTestTrait;
use Augias\InvoiceBundle\Entity\Invoice;
use Augias\InvoiceBundle\Entity\Line;
use Augias\InvoiceBundle\Enum\InvoiceStatus;
use Augias\PaymentBundle\Entity\PaymentMethod;
use Augias\UserBundle\Entity\User;
use Augias\UserBundle\Enum\CompanyRole;
use Augias\UserBundle\Test\Factory\UserFactory;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Browser\Test\HasBrowser;
use function file_put_contents;
use function sys_get_temp_dir;
use function tempnam;

/**
 * The bank page as a user goes through it: an account, a statement file, the
 * invoice it pays offered on its line, one click.
 */
#[Group('functional')]
final class BankPageTest extends WebTestCase
{
    use HasBrowser;
    use DoctrineTestTrait;

    public function testFromAStatementFileToAPaidInvoice(): void
    {
        $owner = $this->member(CompanyRole::Owner);
        $invoice = $this->owedInvoice();

        $browser = $this->browser()->actingAs($owner)->visit('/accounting/bank')->assertSuccessful();
        $token = $browser->crawler()->filter('form[action="/accounting/bank-accounts"] input[name=_token]')->attr('value');

        $browser->post('/accounting/bank-accounts', ['body' => ['_token' => $token, 'name' => 'Compte pro', 'iban' => '', 'currency' => 'EUR']])
            ->assertSuccessful()
            ->assertSee('Compte pro');

        $file = (string) tempnam(sys_get_temp_dir(), 'stmt');
        file_put_contents($file, "Date;Libellé;Montant\n24/09/2026;VIR ACME FACT-BANK-1;120,00\n");

        $browser->attachFile('statement', $file)
            ->click('Importer')
            ->assertSuccessful()
            ->assertSee('1 opération(s) ajoutée(s)')
            ->assertSee('VIR ACME FACT-BANK-1')
            ->assertSee('FACT-BANK-1');

        $match = $browser->crawler()->filter('form[action$="/match"]');
        $browser->post((string) $match->attr('action'), ['body' => [
            '_token' => $token,
            'kind' => $match->filter('input[name=kind]')->attr('value'),
            'target' => $match->filter('input[name=target]')->attr('value'),
        ]])
            ->assertSuccessful()
            ->assertSee('Opération rapprochée.');

        $this->em->clear();
        $invoice = $this->em->find(Invoice::class, $invoice->getId());
        self::assertInstanceOf(Invoice::class, $invoice);
        self::assertSame(InvoiceStatus::Paid, $invoice->getStatus());
    }

    public function testAnAccountantSeesTheBankButRecordsNothing(): void
    {
        $accountant = $this->member(CompanyRole::Accountant);

        $this->browser()
            ->actingAs($accountant)
            ->visit('/accounting/bank')
            ->assertSuccessful()
            ->assertElementCount('form[action="/accounting/bank-accounts"]', 0)
            ->post('/accounting/bank-accounts', ['body' => ['name' => 'X']])
            ->assertStatus(403);
    }

    private function member(CompanyRole $role): User
    {
        $_SERVER['AUGIAS_LOCALE'] = $_ENV['AUGIAS_LOCALE'] = 'fr_FR';
        $user = UserFactory::createOne(['companies' => []]);
        $user = $this->em->find(User::class, $user->getId());
        $company = $this->em->find(Company::class, $this->company->getId());
        self::assertInstanceOf(User::class, $user);
        self::assertInstanceOf(Company::class, $company);
        $user->addCompany($company, $role);
        $this->em->flush();

        return $user;
    }

    private function owedInvoice(): Invoice
    {
        $company = $this->em->find(Company::class, $this->company->getId());
        self::assertInstanceOf(Company::class, $company);
        $client = ClientFactory::createOne(['company' => $this->company, 'currencyCode' => 'EUR', 'isCompany' => true, 'name' => 'Acme bank']);

        $method = new PaymentMethod();
        $method->setName('Virement');
        $method->setGatewayName('bank_transfer');
        $method->setFactoryName(PaymentMethod::FACTORY_OFFLINE);
        $method->setInternal(true);
        $method->setEnabled(true);
        $method->setCompany($company);
        $this->em->persist($method);

        $invoice = new Invoice();
        $invoice->setCompany($company);
        $invoice->setClient($this->em->find(Client::class, $client->getId()));
        $invoice->setStatus(InvoiceStatus::Pending);
        $invoice->setInvoiceId('FACT-BANK-1');
        $invoice->setInvoiceDate(new DateTimeImmutable('2026-09-01'));
        $invoice->addLine(new Line()->setDescription('Consulting')->setPrice(12000)->setQty(1));
        $this->em->persist($invoice);
        $this->em->flush();

        return $invoice;
    }
}
