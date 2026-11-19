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

namespace Augias\CatalogBundle\Mcp;

use Augias\CatalogBundle\Entity\Product;
use Augias\CatalogBundle\Enum\ProductType;
use Augias\CatalogBundle\Repository\ProductRepository;
use Augias\CoreBundle\Entity\Category;
use Augias\CoreBundle\Enum\CategoryUsage;
use Augias\CoreBundle\Enum\QuantityUnit;
use Augias\CoreBundle\Repository\CategoryRepository;
use Augias\McpBundle\Mcp\Attribute\McpScopeRequired;
use Augias\McpBundle\Mcp\McpScopeGuard;
use Augias\McpBundle\Mcp\Tool\EntityNormalizer;
use Augias\McpBundle\Mcp\Tool\UlidParser;
use Augias\McpBundle\Security\McpScope;
use Augias\TaxBundle\Entity\Tax;
use Brick\Math\BigInteger;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Throwable;

/**
 * The catalogue of products and services: what a line can be filled from, by
 * passing its `product_id` to create_invoice or create_quote.
 */
final readonly class CatalogTools
{
    public function __construct(
        private ProductRepository $productRepository,
        private CategoryRepository $categoryRepository,
        private EntityManagerInterface $entityManager,
        private EntityNormalizer $normalizer,
        private ValidatorInterface $validator,
        private McpScopeGuard $scopeGuard,
    ) {
    }

    /**
     * Search the catalogue by name, reference or description.
     *
     * @param string|null $search          Text to look for (case-insensitive); omit to list everything
     * @param bool        $include_inactive Also return entries no longer offered on new lines
     * @param int         $limit           Max rows (1..100)
     *
     * @return array{results: list<array<string, mixed>>, count: int}
     */
    #[McpTool(name: 'search_products', description: 'Search the catalogue of products and services by name, reference or description. Pass a product id as product_id on a line of create_invoice or create_quote to fill it.')]
    #[McpScopeRequired(McpScope::Read)]
    public function searchProducts(?string $search = null, bool $include_inactive = false, int $limit = 25): array
    {
        $this->scopeGuard->require(McpScope::Read);
        $limit = max(1, min($limit, 100));

        $qb = $this->productRepository->createQueryBuilder('p')
            ->orderBy('p.name', 'ASC')
            ->setMaxResults($limit);

        if (! $include_inactive) {
            $qb->andWhere('p.active = true');
        }

        if ($search !== null && trim($search) !== '') {
            $qb->andWhere('LOWER(p.name) LIKE :search OR LOWER(p.reference) LIKE :search OR LOWER(p.description) LIKE :search')
                ->setParameter('search', '%' . mb_strtolower(addcslashes(trim($search), '%_')) . '%');
        }

        $results = $this->normalizer->normalizeMany($qb->getQuery()->getResult());

        return ['results' => $results, 'count' => \count($results)];
    }

    /**
     * Add a product or a service to the catalogue.
     *
     * @param string      $name           Name shown in the picker and on the first line of a document line
     * @param int|string  $sale_price     Sale price excluding tax, in the minor unit (e.g. cents)
     * @param string      $type           "service" (default) or "product" (goods: VAT falls due on delivery)
     * @param string|null $unit           Unit of the quantity (e.g. "hour", "day", "unit"); defaults to "unit"
     * @param string|null $description    Longer text, added under the name on a document line
     * @param string|null $reference      Internal reference (SKU)
     * @param string|null $tax_id         Tax rate ULID applied by default to lines built from it
     * @param int|string|null $purchase_price Purchase price, in the minor unit, to follow the margin
     * @param string|null $category_id    Catalogue category ULID (from list_categories)
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: 'create_product', description: 'Add a product or a service to the catalogue, with its sale price (minor unit), unit, kind and default tax.')]
    #[McpScopeRequired(McpScope::Write)]
    public function createProduct(
        string $name,
        int | string $sale_price,
        string $type = 'service',
        ?string $unit = null,
        ?string $description = null,
        ?string $reference = null,
        ?string $tax_id = null,
        int | string | null $purchase_price = null,
        ?string $category_id = null,
    ): array {
        $this->scopeGuard->require(McpScope::Write);

        $product = new Product();
        $this->apply($product, [
            'name' => $name,
            'sale_price' => $sale_price,
            'type' => $type,
            'unit' => $unit ?? QuantityUnit::Unit->value,
            'description' => $description,
            'reference' => $reference,
            'tax_id' => $tax_id,
            'purchase_price' => $purchase_price,
            'category_id' => $category_id,
        ]);

        $this->validate($product);
        $this->entityManager->persist($product);
        $this->entityManager->flush();

        return $this->normalizer->normalize($product);
    }

    /**
     * Change a catalogue entry. Only the fields given are changed; documents
     * already written keep the lines they were given.
     *
     * @param string               $product_id Product ULID
     * @param array<string, mixed> $fields     Any of: name, sale_price, type, unit, description, reference, tax_id (null to remove), category_id (null to remove), purchase_price, active
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: 'update_product', description: 'Change a catalogue entry: fields is an object with any of name, sale_price, type, unit, description, reference, tax_id, category_id, purchase_price, or active (false withdraws it from new lines).')]
    #[McpScopeRequired(McpScope::Write)]
    public function updateProduct(string $product_id, #[Schema(type: 'object', additionalProperties: true)] array $fields): array
    {
        $this->scopeGuard->require(McpScope::Write);

        $product = $this->productRepository->find(UlidParser::parse($product_id, 'product_id'));

        if (! $product instanceof Product) {
            throw new ToolCallException(sprintf('Product %s not found.', $product_id));
        }

        $this->apply($product, $fields);
        $this->validate($product);
        $this->entityManager->flush();

        return $this->normalizer->normalize($product);
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function apply(Product $product, array $fields): void
    {
        foreach ($fields as $field => $value) {
            match ($field) {
                'name' => $product->setName($this->string($value, 'name')),
                'description' => $product->setDescription($this->nullableString($value, 'description')),
                'reference' => $product->setReference($this->nullableString($value, 'reference')),
                'sale_price' => $product->setSalePrice($this->amount($value, 'sale_price') ?? throw new ToolCallException('sale_price cannot be empty.')),
                'purchase_price' => $product->setPurchasePrice($this->amount($value, 'purchase_price')),
                'type' => $product->setType(
                    ProductType::tryFrom($this->string($value, 'type'))
                    ?? throw new ToolCallException('type must be "service" or "product".'),
                ),
                'unit' => $product->setUnit(
                    QuantityUnit::tryFrom($this->string($value, 'unit'))
                    ?? throw new ToolCallException(sprintf(
                        'Invalid unit. Expected one of: %s.',
                        implode(', ', array_map(static fn (QuantityUnit $case): string => $case->value, QuantityUnit::cases())),
                    )),
                ),
                'tax_id' => $product->setTax($this->tax($value)),
                'category_id' => $product->setCategory($this->category($value, CategoryUsage::Catalog)),
                'active' => $product->setActive((bool) $value),
                default => throw new ToolCallException(sprintf('Unknown product field "%s".', $field)),
            };
        }
    }

    /**
     * Delete a catalogue entry. Documents already written keep their lines:
     * they hold a copy, not a link. To keep it but stop offering it, set
     * active to false with update_product instead.
     *
     * @param string $product_id Product ULID
     *
     * @return array{deleted: bool, product_id: string}
     */
    #[McpTool(name: 'delete_product', description: 'Delete a catalogue entry (documents already written keep their lines). To only stop offering it, use update_product with active false.')]
    #[McpScopeRequired(McpScope::Write)]
    public function deleteProduct(string $product_id): array
    {
        $this->scopeGuard->require(McpScope::Write);

        $product = $this->productRepository->find(UlidParser::parse($product_id, 'product_id'));

        if (! $product instanceof Product) {
            throw new ToolCallException(sprintf('Product %s not found.', $product_id));
        }

        $this->entityManager->remove($product);
        $this->entityManager->flush();

        return ['deleted' => true, 'product_id' => $product_id];
    }

    /**
     * Categories, shared by the catalogue and the purchases: each says which
     * of the two it is used for.
     *
     * @param string|null $usage "catalog" or "purchase"; omit for all
     *
     * @return array{results: list<array<string, mixed>>, count: int}
     */
    #[McpTool(name: 'list_categories', description: 'List categories with what each is used for (catalog, purchase). Pass a category id as category_id on create_product, update_product or create_bill.')]
    #[McpScopeRequired(McpScope::Read)]
    public function listCategories(?string $usage = null): array
    {
        $this->scopeGuard->require(McpScope::Read);

        if ($usage !== null) {
            $categories = $this->categoryRepository->sortedForUsage($this->usage($usage));
        } else {
            $categories = $this->categoryRepository->findBy([], ['name' => 'ASC']);
        }

        $results = array_map($this->categoryRow(...), $categories);

        return ['results' => $results, 'count' => \count($results)];
    }

    /**
     * @param string       $name   Category name
     * @param list<string> $usages What it is used for: "catalog", "purchase" or both
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: 'create_category', description: 'Create a category for the catalogue and/or the purchases (usages: ["catalog"], ["purchase"] or both).')]
    #[McpScopeRequired(McpScope::Write)]
    public function createCategory(string $name, array $usages = ['catalog']): array
    {
        $this->scopeGuard->require(McpScope::Write);

        $category = new Category();
        $category->setName(trim($name));
        $this->applyUsages($category, $usages);
        $this->validateEntity($category);

        $this->entityManager->persist($category);
        $this->entityManager->flush();

        return $this->categoryRow($category);
    }

    /**
     * Rename a category or change what it is used for.
     *
     * @param string            $category_id Category ULID
     * @param string|null       $name        New name
     * @param list<string>|null $usages      New usages ("catalog", "purchase"), replacing the current ones
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: 'update_category', description: 'Rename a category or replace what it is used for (usages: "catalog", "purchase").')]
    #[McpScopeRequired(McpScope::Write)]
    public function updateCategory(string $category_id, ?string $name = null, ?array $usages = null): array
    {
        $this->scopeGuard->require(McpScope::Write);

        $category = $this->categoryRepository->find(UlidParser::parse($category_id, 'category_id'));

        if (! $category instanceof Category) {
            throw new ToolCallException(sprintf('Category %s not found.', $category_id));
        }

        if ($name !== null) {
            $category->setName(trim($name));
        }

        if ($usages !== null) {
            $this->applyUsages($category, $usages);
        }

        $this->validateEntity($category);
        $this->entityManager->flush();

        return $this->categoryRow($category);
    }

    /**
     * @param list<mixed> $usages
     */
    private function applyUsages(Category $category, array $usages): void
    {
        $wanted = array_map(fn (mixed $value): CategoryUsage => $this->usage(\is_string($value) ? $value : ''), $usages);

        foreach (CategoryUsage::cases() as $case) {
            $category->setUsage($case, \in_array($case, $wanted, true));
        }
    }

    private function usage(string $value): CategoryUsage
    {
        return CategoryUsage::tryFrom($value) ?? throw new ToolCallException(sprintf('Unknown usage "%s": expected "catalog" or "purchase".', $value));
    }

    /**
     * @return array<string, mixed>
     */
    private function categoryRow(Category $category): array
    {
        return [
            'id' => $category->getId()?->toRfc4122(),
            'name' => $category->getName(),
            'usages' => array_map(static fn (CategoryUsage $usage): string => $usage->value, $category->usages()),
        ];
    }

    private function category(mixed $value, CategoryUsage $usage): ?Category
    {
        if ($value === null || $value === '') {
            return null;
        }

        $categoryId = $this->string($value, 'category_id');
        $category = $this->categoryRepository->find(UlidParser::parse($categoryId, 'category_id'));

        if (! $category instanceof Category) {
            throw new ToolCallException(sprintf('Category %s not found.', $categoryId));
        }

        if (! $category->supports($usage)) {
            throw new ToolCallException(sprintf('Category "%s" is not used for the %s. Add the "%s" usage with update_category first.', $category->getName(), $usage === CategoryUsage::Catalog ? 'catalogue' : 'purchases', $usage->value));
        }

        return $category;
    }

    private function tax(mixed $value): ?Tax
    {
        if ($value === null || $value === '') {
            return null;
        }

        $taxId = $this->string($value, 'tax_id');
        $tax = $this->entityManager->getRepository(Tax::class)->find(UlidParser::parse($taxId, 'tax_id'));

        if (! $tax instanceof Tax) {
            throw new ToolCallException(sprintf('Tax %s not found.', $taxId));
        }

        return $tax;
    }

    private function amount(mixed $value, string $field): ?BigInteger
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return BigInteger::of(\is_int($value) || \is_string($value) ? $value : throw new ToolCallException(''));
        } catch (Throwable) {
            throw new ToolCallException(sprintf('%s must be a whole number in the minor unit (e.g. cents).', $field));
        }
    }

    private function string(mixed $value, string $field): string
    {
        if (! \is_string($value) || trim($value) === '') {
            throw new ToolCallException(sprintf('%s must be a non-empty string.', $field));
        }

        return $value;
    }

    private function nullableString(mixed $value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->string($value, $field);
    }

    private function validate(Product $product): void
    {
        $this->validateEntity($product);
    }

    private function validateEntity(object $entity): void
    {
        $errors = $this->validator->validate($entity);

        if (\count($errors) === 0) {
            return;
        }

        $messages = [];

        foreach ($errors as $error) {
            $messages[] = sprintf('%s: %s', $error->getPropertyPath(), $error->getMessage());
        }

        throw new ToolCallException(sprintf('Validation failed: %s', implode('; ', $messages)));
    }
}
