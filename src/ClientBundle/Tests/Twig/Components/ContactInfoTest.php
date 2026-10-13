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

namespace Augias\ClientBundle\Tests\Twig\Components;

use Augias\ClientBundle\Test\Factory\ClientFactory;
use Augias\ClientBundle\Test\Factory\ContactFactory;
use Augias\ClientBundle\Twig\Components\ContactInfo;
use Augias\CoreBundle\Enum\CustomFieldTarget;
use Augias\CoreBundle\Repository\CustomFieldValueRepository;
use Augias\CoreBundle\Test\LiveComponentTest;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(ContactInfo::class)]
final class ContactInfoTest extends LiveComponentTest
{
    public function testSaveExistingContactPersistsCustomFieldValue(): void
    {
        $client = ClientFactory::createOne(['company' => $this->company, 'siret' => '12345678900012']);
        $contact = ContactFactory::createOne([
            'firstName' => 'Jane',
            'lastName' => 'Smith',
            'email' => 'jane@example.com',
            'client' => $client,
            'company' => $this->company,
        ]);

        $component = $this->createLiveComponent(
            name: ContactInfo::class,
            data: ['contact' => $contact],
            client: $this->client,
        )->actingAs($this->getUser());

        $component->set('contact', [
            'firstName' => 'Jane',
            'lastName' => 'Smith',
            'email' => 'jane@example.com',
            'customFields' => ['phone' => '9876543210'],
        ])->call('save');

        /** @var CustomFieldValueRepository $repo */
        $repo = self::getContainer()->get(CustomFieldValueRepository::class);
        $values = $repo->findForRecord(CustomFieldTarget::CONTACT, $contact->getId());
        self::assertCount(1, $values);
        self::assertSame('9876543210', $values[0]->getValue());
    }
}
