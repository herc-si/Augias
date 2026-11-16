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

namespace Augias\ClientBundle\Import;

use Augias\ClientBundle\Entity\Address;
use Augias\ClientBundle\Entity\Client;
use Augias\ClientBundle\Entity\Contact;
use Augias\ClientBundle\Repository\ClientRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Intl\Countries;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use function array_unique;
use function array_values;
use function in_array;
use function mb_strtolower;
use function preg_replace;
use function str_starts_with;
use function strlen;
use function strtoupper;
use function trim;

/**
 * Creates clients from a spreadsheet export.
 *
 * It only ever adds: a line naming a client that already exists is left out,
 * never merged into it, so importing the same file twice changes nothing. A
 * line that breaks a rule of the client form (a business without a SIRET, a
 * malformed e-mail address) is reported with the rule it broke and the rest of
 * the file still goes in.
 *
 * @see \Augias\ClientBundle\Tests\Import\ClientImporterTest
 */
final readonly class ClientImporter
{
    /** How the "type" column says "a private individual", once lower-cased and unaccented. */
    private const array INDIVIDUAL = ['particulier', 'individu', 'personne physique', 'individual', 'private', 'person', 'b2c'];

    public function __construct(
        private ClientCsvReader $reader,
        private ClientRepository $clients,
        private EntityManagerInterface $entityManager,
        private ValidatorInterface $validator,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @param bool $dryRun true to report what the import would do and write nothing
     *
     * @throws UnreadableClientFile
     */
    public function import(string $content, bool $dryRun = false): ClientImportReport
    {
        $file = $this->reader->read($content);
        $taken = $this->existingNames();

        $created = [];
        $duplicates = [];
        $rejected = [];

        foreach ($file['rows'] as ['line' => $line, 'data' => $data]) {
            $name = $data[ClientCsvReader::NAME] ?? '';

            if ('' === $name) {
                $rejected[] = ['line' => $line, 'name' => '', 'errors' => [$this->translator->trans('client.import.row.no_name')]];

                continue;
            }

            $key = $this->nameKey($name);

            if (isset($taken[$key])) {
                $duplicates[] = ['line' => $line, 'name' => $name];

                continue;
            }

            $client = $this->client($data);
            $errors = $this->errors($client);

            if ([] !== $errors) {
                $rejected[] = ['line' => $line, 'name' => $name, 'errors' => $errors];

                continue;
            }

            $taken[$key] = true;
            $created[] = $name;

            if (! $dryRun) {
                // One flush per client: the plan's client limit and the unique name are
                // checked against what is in the database, so each line has to be there
                // before the next one is validated.
                $this->entityManager->persist($client);
                $this->entityManager->flush();
            }
        }

        return new ClientImportReport($created, $duplicates, $rejected, $file['unknownColumns']);
    }

    /**
     * @param array<string, string> $data
     */
    private function client(array $data): Client
    {
        $client = new Client();
        $client->setName($data[ClientCsvReader::NAME]);
        $client->setIsCompany(! $this->isIndividual($data[ClientCsvReader::TYPE] ?? ''));

        if (isset($data[ClientCsvReader::WEBSITE])) {
            $website = $data[ClientCsvReader::WEBSITE];
            $client->setWebsite(str_starts_with($website, 'http://') || str_starts_with($website, 'https://') ? $website : 'https://' . $website);
        }

        if (isset($data[ClientCsvReader::CURRENCY])) {
            $client->setCurrencyCode(strtoupper($data[ClientCsvReader::CURRENCY]));
        }

        if (isset($data[ClientCsvReader::SIRET])) {
            // Spreadsheets show a SIRET as "732 829 320 00074".
            $client->setSiret((string) preg_replace('/\s+/', '', $data[ClientCsvReader::SIRET]));
        }

        if (isset($data[ClientCsvReader::VAT_NUMBER])) {
            $client->setVatNumber(strtoupper((string) preg_replace('/\s+/', '', $data[ClientCsvReader::VAT_NUMBER])));
        }

        // A contact is somebody to write to: without an address there is none to create.
        if (isset($data[ClientCsvReader::EMAIL])) {
            $contact = new Contact();
            $contact->setEmail($data[ClientCsvReader::EMAIL]);
            $contact->setFirstName($data[ClientCsvReader::FIRST_NAME] ?? $data[ClientCsvReader::LAST_NAME] ?? $data[ClientCsvReader::NAME]);

            if (isset($data[ClientCsvReader::FIRST_NAME], $data[ClientCsvReader::LAST_NAME])) {
                $contact->setLastName($data[ClientCsvReader::LAST_NAME]);
            }

            $client->addContact($contact);
        }

        if (isset($data[ClientCsvReader::STREET]) || isset($data[ClientCsvReader::CITY]) || isset($data[ClientCsvReader::ZIP])) {
            $address = new Address();
            $address->setStreet1($data[ClientCsvReader::STREET] ?? null);
            $address->setStreet2($data[ClientCsvReader::STREET2] ?? null);
            $address->setZip($data[ClientCsvReader::ZIP] ?? null);
            $address->setCity($data[ClientCsvReader::CITY] ?? null);
            $address->setCountry($this->country($data[ClientCsvReader::COUNTRY] ?? ''));
            $client->addAddress($address);
        }

        return $client;
    }

    /**
     * The messages of the rules the client breaks, as the client form would word them.
     *
     * @return list<string>
     */
    private function errors(Client $client): array
    {
        $messages = $this->messages($this->validator->validate($client));

        foreach ($client->getContacts() as $contact) {
            $messages = [...$messages, ...$this->messages($this->validator->validate($contact))];
        }

        foreach ($client->getAddresses() as $address) {
            $messages = [...$messages, ...$this->messages($this->validator->validate($address))];
        }

        return array_values(array_unique($messages));
    }

    /**
     * @return list<string>
     */
    private function messages(ConstraintViolationListInterface $violations): array
    {
        $messages = [];

        foreach ($violations as $violation) {
            $messages[] = (string) $violation->getMessage();
        }

        return $messages;
    }

    /**
     * Every client name of the company, archived ones included: the name is
     * unique in the database whether the client is archived or not.
     *
     * @return array<string, true>
     */
    private function existingNames(): array
    {
        $filters = $this->entityManager->getFilters();
        $archivable = $filters->isEnabled('archivable');

        if ($archivable) {
            $filters->disable('archivable');
        }

        try {
            /** @var list<array{name: string|null}> $rows */
            $rows = $this->clients->createQueryBuilder('c')->select('c.name')->getQuery()->getArrayResult();
        } finally {
            if ($archivable) {
                $filters->enable('archivable');
            }
        }

        $names = [];

        foreach ($rows as $row) {
            $names[$this->nameKey((string) $row['name'])] = true;
        }

        return $names;
    }

    /**
     * "ACME  sarl" and "Acme SARL" are the same client.
     */
    private function nameKey(string $name): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/', ' ', $name)));
    }

    private function isIndividual(string $type): bool
    {
        return in_array(new AsciiSlugger()->slug($type, ' ')->lower()->toString(), self::INDIVIDUAL, true);
    }

    /**
     * A two-letter code as is, a country name in French or English looked up;
     * anything else is left out rather than stored wrong.
     */
    private function country(string $country): ?string
    {
        $country = trim($country);

        if ('' === $country) {
            return null;
        }

        if (2 === strlen($country) && Countries::exists(strtoupper($country))) {
            return strtoupper($country);
        }

        $wanted = $this->nameKey($country);

        foreach (['fr', 'en'] as $locale) {
            foreach (Countries::getNames($locale) as $code => $name) {
                if ($this->nameKey($name) === $wanted) {
                    return $code;
                }
            }
        }

        return null;
    }
}
