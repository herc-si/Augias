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

namespace Augias\DataGridBundle\Twig\Components;

use Augias\DataGridBundle\Attributes\AsDataGrid;
use Augias\DataGridBundle\Exception\InvalidGridException;
use Augias\DataGridBundle\Export\GridQueryService;
use Augias\DataGridBundle\GridBuilder\Column\Column;
use Augias\DataGridBundle\GridBuilder\Query;
use Augias\DataGridBundle\GridInterface;
use Augias\DataGridBundle\Paginator\Adapter\QueryAdapter;
use Augias\DataGridBundle\Render\GridFieldRenderer;
use Augias\DataGridBundle\Source\SourceInterface;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Exception;
use Pagerfanta\Exception\OutOfRangeCurrentPageException;
use Pagerfanta\Pagerfanta;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use ReflectionObject;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Uid\Ulid;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\ComponentWithFormTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Symfony\UX\TwigComponent\Attribute\ExposeInTemplate;
use Symfony\UX\TwigComponent\Attribute\PostMount;
use Twig\Error\LoaderError;
use Twig\Error\SyntaxError;
use function explode;

/**
 * @template T of object
 * @see \Augias\DataGridBundle\Tests\Twig\Components\DataGridTest
 */
#[AsLiveComponent]
class DataGrid extends AbstractController
{
    use DefaultActionTrait;
    use ComponentToolsTrait;
    use ComponentWithFormTrait;

    /**
     * @var class-string<T>
     */
    #[LiveProp(writable: true, url: false)]
    public string $name;

    /**
     * @var array<string, mixed>
     */
    #[LiveProp(writable: true, hydrateWith: 'hydrateContext', dehydrateWith: 'dehydrateContext', url: false)]
    public array $context = [];

    #[LiveProp(writable: true, url: true)]
    public int $page = 1;

    #[LiveProp(writable: true, url: true)]
    public string $sort = '';

    #[LiveProp(writable: true, url: true)]
    public int $perPage = 10;

    #[LiveProp(writable: true, url: true)]
    public string $search = '';

    /**
     * Hidden column field names.
     *
     * @var list<string>
     */
    #[LiveProp(writable: true, url: true)]
    public array $hiddenColumns = [];

    /**
     * @var list<string>
     */
    #[LiveProp(writable: true, onUpdated: 'onSelectedItem', url: false)]
    public array $selectedItems = [];

    #[LiveProp(writable: true, onUpdated: 'selectAll', url: false)]
    public bool $selectedAll = false;

    /**
     * @var array<string, mixed>
     */
    #[LiveProp(writable: true, url: true)]
    public array $gridFilters = [];

    /**
     * Initialize formValues from filters after mount.
     * This ensures URL-persisted filters are properly applied to the form.
     */
    #[PostMount(priority: 10)]
    public function initializeFormFromFilters(): void
    {
        if ($this->gridFilters !== []) {
            $this->formValues = $this->gridFilters;
        }
    }

    /**
     * Seeds $hiddenColumns from the grid's own hiddenByDefault() columns on a
     * fresh visit — $hiddenColumns is still at its `[]` PHP default here only
     * when the request/URL carried no explicit value (hydration from the URL,
     * which runs before PostMount, would already have overwritten it
     * otherwise), so this never fights a user's own column choices, including
     * a deliberate "show everything" that happens to also be `[]`.
     */
    #[PostMount(priority: 10)]
    public function initializeHiddenColumnsFromGridDefaults(): void
    {
        if ($this->hiddenColumns !== []) {
            return;
        }

        $this->hiddenColumns = array_values(array_map(
            static fn (Column $column) => $column->getField(),
            array_filter(
                $this->getGrid()->columns(),
                static fn (Column $column) => $column->isHiddenByDefault(),
            ),
        ));
    }

    public function selectAll(): void
    {
        if ($this->selectedAll) {
            $this->selectedItems = [];
            foreach ($this->getPaginator() as $item) {
                $this->selectedItems[] = (string) $this->entityId($item);
            }
        } else {
            $this->selectedItems = [];
        }
    }

    public function onSelectedItem(): void
    {
        $totalItems = count($this->selectedItems);
        if (0 === $totalItems) {
            $this->selectedAll = false;
        } elseif ($totalItems === count($this->getPaginator())) {
            $this->selectedAll = true;
        }
    }

    /**
     * @param ServiceLocator<GridInterface> $serviceLocator
     */
    public function __construct(
        private readonly ManagerRegistry $registry,
        private readonly GridFieldRenderer $fieldRenderer,
        private readonly SourceInterface $source,
        #[AutowireLocator(AsDataGrid::DI_TAG, 'name')]
        private readonly ServiceLocator $serviceLocator,
        private readonly GridQueryService $gridQueryService,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * @return Pagerfanta<T>
     * @throws Exception
     * @throws ContainerExceptionInterface
     */
    #[ExposeInTemplate]
    public function getPaginator(): Pagerfanta
    {
        $grid = $this->getGrid();

        $query = $this->source->fetch($grid);
        $builder = $query->getQueryBuilder();

        $this->filterQuery($grid, $builder);

        try {
            return Pagerfanta::createForCurrentPageWithMaxPerPage(
                new QueryAdapter(
                    $builder,
                    beforeQuery: $query->getCallback(Query::BEFORE_QUERY),
                    afterQuery: $query->getCallback(Query::AFTER_QUERY),
                ),
                $this->page,
                $this->perPage,
            );
        } catch (OutOfRangeCurrentPageException) {
            $this->page = 1;

            return Pagerfanta::createForCurrentPageWithMaxPerPage(
                new QueryAdapter(
                    $builder,
                    beforeQuery: $query->getCallback(Query::BEFORE_QUERY),
                    afterQuery: $query->getCallback(Query::AFTER_QUERY),
                ),
                $this->page,
                $this->perPage,
            );
        }
    }

    #[ExposeInTemplate]
    public function sortDir(): string
    {
        return explode(',', $this->sort)[1] ?? 'ASC';
    }

    #[ExposeInTemplate]
    public function sortField(): string
    {
        return explode(',', $this->sort)[0] ?? '';
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws InvalidGridException
     */
    #[ExposeInTemplate]
    public function getGrid(): GridInterface
    {
        try {
            $grid = $this->serviceLocator->get($this->name);
            $grid->initialize($this->context);

            return $grid;
        } catch (NotFoundExceptionInterface $e) {
            throw new InvalidGridException($this->name, $e);
        }
    }

    /**
     * @throws SyntaxError
     * @throws NotFoundExceptionInterface
     * @throws ContainerExceptionInterface
     * @throws LoaderError
     */
    public function renderField(Column $column, object $entity): string
    {
        return $this->fieldRenderer->render($column, $entity);
    }

    public function entityId(object $entity): mixed
    {
        $manager = $this->registry->getManagerForClass($entity::class);

        if (! $manager instanceof ObjectManager) {
            throw new RuntimeException(sprintf('No entity manager found for class "%s"', $entity::class));
        }

        $metaData = $manager->getClassMetadata($entity::class);

        return $metaData->getIdentifierValues($entity)[$metaData->getIdentifier()[0]];
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws InvalidGridException
     */
    #[LiveAction]
    public function executeBatch(#[LiveArg('actionName')] string $actionName): void
    {
        try {
            if ($this->selectedItems === []) {
                $this->addFlash('warning', 'datagrid.flash.select_one');
                return;
            }

            $grid = $this->getGrid();

            foreach ($grid->batchActions() as $action) {
                if ($action->getLabel() !== $actionName) {
                    continue;
                }

                $actionFn = $action->getAction();

                if (null === $actionFn) {
                    $this->addFlash('warning', 'datagrid.flash.not_implemented');
                    return;
                }

                if ($this->run($actionFn, $this->selectedItems, $grid)) {
                    $this->addFlash('success', 'datagrid.flash.success');
                }

                return;
            }
        } finally {
            $this->selectedItems = [];
            $this->selectedAll = false;
            $this->dispatchBrowserEvent('modal:close');
        }
    }

    /**
     * Execute a batch action on a single entity (from the row dropdown menu).
     *
     * @throws ContainerExceptionInterface
     * @throws InvalidGridException
     */
    #[LiveAction]
    public function executeSingle(
        #[LiveArg('actionName')]
        string $actionName,
        #[LiveArg('entityId')]
        string $entityId
    ): void {
        $grid = $this->getGrid();

        foreach ($grid->batchActions() as $action) {
            if ($action->getLabel() !== $actionName) {
                continue;
            }

            $actionFn = $action->getAction();

            if (null === $actionFn) {
                $this->addFlash('warning', 'datagrid.flash.not_implemented');
                return;
            }

            if ($this->run($actionFn, [$entityId], $grid)) {
                $this->addFlash('success', 'datagrid.flash.success');
            }

            $this->dispatchBrowserEvent('modal:close');

            return;
        }

        $this->addFlash('warning', 'datagrid.flash.not_found');
    }

    /**
     * Runs a batch action, and shows the reason when it refuses.
     *
     * An action that declines — deleting an invoice that has been issued, say —
     * throws with a message meant for the person who asked. Without this the
     * grid answered with an error page, or with "done" for something that was
     * not.
     *
     * @param list<string> $ids
     */
    private function run(callable $action, array $ids, GridInterface $grid): bool
    {
        // Seeing a list is not changing what is in it: whoever may run its
        // batch actions is decided by what the list holds.
        if (! $this->isGranted('datagrid.batch', $grid->entityFQCN())) {
            $this->addFlash('danger', $this->translator->trans('datagrid.flash.not_allowed'));

            return false;
        }

        try {
            $action($this->registry->getRepository($grid->entityFQCN()), $ids);
        } catch (RuntimeException $exception) {
            if (! $exception instanceof TranslatableInterface) {
                throw $exception;
            }

            $this->addFlash('danger', $exception->trans($this->translator));

            return false;
        }

        return true;
    }

    /**
     * Apply filters from the submitted form.
     * This is called when the user clicks "Apply Filters".
     */
    #[LiveAction]
    public function applyFilters(): void
    {
        // Get the submitted form values
        $this->submitForm();

        if ($this->formValues !== []) {
            $values = $this->formValues;
            unset($values['_token']);
            $this->gridFilters = $this->clearNestedValues($values);
        }

        // Reset page to 1 when filters change
        $this->page = 1;
    }

    #[LiveAction]
    public function clearFilters(): void
    {
        $this->gridFilters = [];
        $this->resetForm();
    }

    #[LiveAction]
    public function clearSelection(): void
    {
        $this->selectedItems = [];
        $this->selectedAll = false;
    }

    #[LiveAction]
    public function removeFilter(#[LiveArg('filterKey')] string $filterKey): void
    {
        unset($this->gridFilters[$filterKey]);
        $this->resetForm();
    }

    /**
     * Check if a column is visible.
     */
    public function isColumnVisible(string $field): bool
    {
        return ! in_array($field, $this->hiddenColumns, true);
    }

    /**
     * Get visible columns for the grid.
     *
     * @return list<Column>
     */
    #[ExposeInTemplate]
    public function getVisibleColumns(): array
    {
        return array_values(array_filter(
            $this->getGrid()->columns(),
            fn (Column $column) => $this->isColumnVisible($column->getField())
        ));
    }

    #[LiveAction]
    public function toggleColumn(#[LiveArg('field')] string $field): void
    {
        if (in_array($field, $this->hiddenColumns, true)) {
            $this->hiddenColumns = array_values(array_filter(
                $this->hiddenColumns,
                static fn (string $col) => $col !== $field
            ));
        } else {
            $this->hiddenColumns[] = $field;
        }
    }

    #[LiveAction]
    public function resetColumns(): void
    {
        $this->hiddenColumns = [];
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws InvalidGridException
     * @return FormInterface<mixed>
     */
    protected function instantiateForm(): FormInterface
    {
        $form = $this->createFormBuilder($this->gridFilters);

        foreach ($this->getGrid()->filters() as $name => $filter) {
            $form->add($name, $filter->form(), array_merge(['label' => false], $filter->formOptions()));
        }

        return $form->getForm();
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function clearNestedValues(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $value = $this->clearNestedValues($value);

                if ($value === []) {
                    unset($values[$key]);
                }
            } elseif ($value === '') {
                unset($values[$key]);
            }
        }

        return $values;
    }

    private function filterQuery(GridInterface $grid, QueryBuilder $builder): void
    {
        $this->gridQueryService->applyFilters($grid, $builder, $this->sort, $this->search, $this->gridFilters);
    }

    public function title(): ?string
    {
        $grid = $this->getGrid();

        $gridDefinition = new ReflectionObject($grid)->getAttributes(AsDataGrid::class)[0] ?? null;

        return $gridDefinition?->getArguments()['title'] ?? null;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function dehydrateContext(array $context): string
    {
        array_walk_recursive($context, static function (mixed &$value): void {
            if ($value instanceof Ulid) {
                $value = (string) $value;
            }
        });

        return json_encode($context, JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    public function hydrateContext(string $context): array
    {
        return json_decode($context, true, 512, JSON_THROW_ON_ERROR) ?? [];
    }
}
