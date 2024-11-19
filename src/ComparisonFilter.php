<?php
namespace WizwebBe;

use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\Filters\Filter;
use Illuminate\Database\Eloquent\Builder;

class ComparisonFilter implements Filter
{
    protected static $types = ['gt' => '>', 'ge' => '>=', 'lt' => '<', 'le' => '<=', 'eq' => '=', 'ne' => '<>'];

    public static function setFilters(string $field, array $givenTypes) {

        $result = [];

        foreach ($givenTypes as $type) {
            $result[] = AllowedFilter::custom("$field-$type", new ComparisonFilter);
        }

        return $result;
    }

    public function __invoke(Builder $query, $value, string $property)
    {
        list($field, $type) = explode('-', $property);

        if(!array_key_exists($type, self::$types)) return;

        $operator = self::$types[$type];

        $query->where($field, $operator, $value);
    }
}