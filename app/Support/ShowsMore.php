<?php

namespace App\Support;

/** "Show more" paging for long lists: each list loads a page at a time instead of every row. */
trait ShowsMore
{
    public int $limit = 40;

    public function showMore(): void
    {
        $this->limit = min($this->limit + 40, 2000);
    }

    /** Any filter or search change starts the list from the top again. */
    public function updated($name = null): void
    {
        if ($name !== 'limit') {
            $this->limit = 40;
        }
    }
}
