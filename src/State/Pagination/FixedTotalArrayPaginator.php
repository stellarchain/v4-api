<?php

declare(strict_types=1);

namespace App\State\Pagination;

use ApiPlatform\State\Pagination\HasNextPagePaginatorInterface;
use ApiPlatform\State\Pagination\PaginatorInterface;

/**
 * @template T of object
 * @implements \IteratorAggregate<int,T>
 */
final class FixedTotalArrayPaginator implements \IteratorAggregate, PaginatorInterface, HasNextPagePaginatorInterface
{
    /**
     * @param list<T> $results
     */
    public function __construct(
        private readonly array $results,
        private readonly int $currentPage,
        private readonly int $itemsPerPage,
        private readonly int $totalItems,
    ) {
    }

    public function getCurrentPage(): float
    {
        return (float) max(1, $this->currentPage);
    }

    public function getLastPage(): float
    {
        if ($this->itemsPerPage <= 0) {
            return 1.0;
        }

        return (float) (max(1, (int) ceil($this->totalItems / $this->itemsPerPage)));
    }

    public function getItemsPerPage(): float
    {
        return (float) $this->itemsPerPage;
    }

    public function getTotalItems(): float
    {
        return (float) $this->totalItems;
    }

    public function count(): int
    {
        return count($this->results);
    }

    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->results);
    }

    public function hasNextPage(): bool
    {
        return $this->getCurrentPage() < $this->getLastPage();
    }
}
