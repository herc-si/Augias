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

namespace Augias\InvoiceBundle\Tests\Functional;

use Augias\ClientBundle\Test\Factory\ClientFactory;
use Augias\ClientBundle\Test\Factory\ContactFactory;
use Augias\InstallBundle\Test\EnsureApplicationInstalled;
use Augias\InvoiceBundle\Entity\Line;
use Augias\InvoiceBundle\Enum\InvoiceStatus;
use Augias\InvoiceBundle\Test\Factory\InvoiceFactory;
use Augias\UserBundle\Test\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ZzLayoutDumpTest extends WebTestCase
{
    use EnsureApplicationInstalled;

    public function testDump(): void
    {
        self::ensureKernelShutdown();
        $browser = self::createClient();
        $browser->disableReboot();
        $user = UserFactory::createOne(['companies' => [$this->company]]);
        $browser->loginUser($user);

        $client = ClientFactory::createOne(['company' => $this->company, 'currencyCode' => 'EUR', 'name' => 'Acme SARL']);
        ContactFactory::createOne(['company' => $this->company, 'client' => $client, 'email' => 'jane@acme.test', 'firstName' => 'Jane', 'lastName' => 'Doe']);
        $invoice = InvoiceFactory::createOne([
            'company' => $this->company, 'client' => $client, 'status' => InvoiceStatus::Pending,
            'lines' => [new Line()->setDescription('Audit annuel')->setPrice(120_000)->setQty(1)->updateTotal()],
        ]);

        foreach (['invoice' => '/invoices/create/' . $client->getId(), 'quote' => '/quotes/create/' . $client->getId(), 'credit' => '/invoices/credit-notes/create/' . $invoice->getId()] as $name => $url) {
            $browser->request('GET', $url);
            $html = (string) $browser->getResponse()->getContent();
            $html = str_replace('<head>', '<head><base href="http://192.168.1.155:8765/">', $html);
            file_put_contents('/app/build/layout/' . $name . '.html', $html);
            self::assertLessThan(400, $browser->getResponse()->getStatusCode(), $name);
        }
    }
}
