<?php

namespace App\Models\Concerns;

/**
 * Eloquent has no native composite primary key support. Models on tables
 * whose PK spans two columns use this trait so save()/delete() build correct
 * WHERE clauses instead of `WHERE id IS NULL`.
 */
trait HasCompositeKey
{
    protected function setKeysForSaveQuery($query)
    {
        foreach ($this->compositeKeyColumns() as $column) {
            $query->where($column, $this->getAttribute($column));
        }

        return $query;
    }

    protected function setKeysForSelectQuery($query)
    {
        return $this->setKeysForSaveQuery($query);
    }

    /** @return list<string> */
    abstract protected function compositeKeyColumns(): array;
}
