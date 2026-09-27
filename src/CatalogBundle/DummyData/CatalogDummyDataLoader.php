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

namespace Augias\CatalogBundle\DummyData;

use Augias\CatalogBundle\Entity\Product;
use Augias\CatalogBundle\Enum\ProductType;
use Augias\CoreBundle\DummyData\DummyDataLoaderInterface;
use Augias\CoreBundle\Entity\Category;
use Augias\CoreBundle\Entity\Company;
use Augias\CoreBundle\Enum\QuantityUnit;
use Augias\TaxBundle\Entity\Tax;
use Brick\Math\BigInteger;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use function assert;

/**
 * A starter catalogue for an IT services company: the day rates, recurring
 * managed-services lines and resold hardware such a business bills most often.
 *
 * Runs after the tax loader (lower priority) so the VAT rate it attaches as a
 * default already exists; when no rate is found the entries are still created,
 * just without one.
 */
#[AsTaggedItem(priority: 90)]
final readonly class CatalogDummyDataLoader implements DummyDataLoaderInterface
{
    public function __construct(
        private ManagerRegistry $registry
    ) {
    }

    public static function getPriority(): int
    {
        return 90;
    }

    public function load(Company $company): void
    {
        $entityManager = $this->registry->getManager();
        assert($entityManager instanceof EntityManagerInterface);

        $categories = [];

        foreach (['Prestations', 'Infogérance', 'Licences', 'Matériel'] as $name) {
            $category = new Category();
            $category->setCompany($company)->setName($name)->setUsedForCatalog(true);
            $entityManager->persist($category);
            $categories[$name] = $category;
        }

        $tax = $this->defaultTax($company);

        // [reference, name, description, category, type, unit, sale, purchase]
        $entries = [
            ['DEV-JOUR', 'Journée de développement', 'Développement applicatif sur mesure, régie ou forfait.', 'Prestations', ProductType::Service, QuantityUnit::Day, 70000, null],
            ['CONSEIL-JOUR', 'Journée de conseil', 'Cadrage, architecture technique, accompagnement à la décision.', 'Prestations', ProductType::Service, QuantityUnit::Day, 90000, null],
            ['AUDIT-FORFAIT', 'Audit technique', 'Audit de sécurité, de performance ou de dette technique, restitution écrite incluse.', 'Prestations', ProductType::Service, QuantityUnit::FlatRate, 150000, null],
            ['FORMATION-H', 'Formation utilisateur', 'Formation en présentiel ou à distance, support fourni.', 'Prestations', ProductType::Service, QuantityUnit::Hour, 9000, null],
            ['INTERV-H', 'Intervention sur site', 'Déplacement et intervention technique sur site client.', 'Prestations', ProductType::Service, QuantityUnit::Hour, 8500, null],
            ['SUPPORT-H', 'Support à distance', 'Assistance téléphonique et prise en main à distance.', 'Prestations', ProductType::Service, QuantityUnit::Hour, 7500, null],
            ['INFO-POSTE', 'Infogérance poste de travail', 'Supervision, mises à jour et support par poste.', 'Infogérance', ProductType::Service, QuantityUnit::Month, 2500, null],
            ['INFO-SRV', 'Infogérance serveur', 'Supervision 24/7, sauvegardes et maintenance par serveur.', 'Infogérance', ProductType::Service, QuantityUnit::Month, 12000, null],
            ['BACKUP-100', 'Sauvegarde externalisée 100 Go', 'Sauvegarde chiffrée hors site, restauration incluse.', 'Infogérance', ProductType::Service, QuantityUnit::Month, 3000, 900],
            ['HEB-WEB', 'Hébergement web', 'Hébergement mutualisé, certificat TLS et nom de domaine inclus.', 'Infogérance', ProductType::Service, QuantityUnit::Month, 1500, 400],
            ['M365-BS', 'Licence Microsoft 365 Business Standard', 'Abonnement par utilisateur, facturé mensuellement.', 'Licences', ProductType::Service, QuantityUnit::Month, 1290, 1050],
            ['AV-POSTE', 'Antivirus poste de travail', 'Licence par poste, console d\'administration incluse.', 'Licences', ProductType::Service, QuantityUnit::Month, 450, 280],
            ['PC-PRO14', 'Ordinateur portable professionnel 14"', 'Configuration bureautique, garantie constructeur 3 ans.', 'Matériel', ProductType::Product, QuantityUnit::Unit, 110000, 89000],
            ['ECRAN-27', 'Écran 27 pouces', 'Dalle IPS, réglable en hauteur.', 'Matériel', ProductType::Product, QuantityUnit::Unit, 25000, 18500],
            ['DOCK-USBC', 'Station d\'accueil USB-C', 'Double affichage, alimentation par le port USB-C.', 'Matériel', ProductType::Product, QuantityUnit::Unit, 18000, 12500],
        ];

        foreach ($entries as [$reference, $name, $description, $category, $type, $unit, $sale, $purchase]) {
            $product = new Product();
            $product->setCompany($company)
                ->setReference($reference)
                ->setName($name)
                ->setDescription($description)
                ->setCategory($categories[$category])
                ->setType($type)
                ->setUnit($unit)
                ->setSalePrice(BigInteger::of($sale))
                ->setPurchasePrice($purchase === null ? null : BigInteger::of($purchase));

            if ($tax instanceof Tax) {
                $product->setTax($tax);
            }

            $entityManager->persist($product);
        }

        $entityManager->flush();
    }

    /**
     * The highest-rate tax already configured, which on a French install is the
     * standard 20% VAT rather than a reduced rate.
     */
    private function defaultTax(Company $company): ?Tax
    {
        return $this->registry->getRepository(Tax::class)
            ->createQueryBuilder('t')
            ->where('t.company = :company')
            ->setParameter('company', $company->getId(), 'ulid')
            ->orderBy('t.rate', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
