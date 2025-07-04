<?php

namespace Galahad\Prismoquent;

use BadMethodCallException;
use DateTime;
use Galahad\Prismoquent\Exceptions\DocumentNotFoundException;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Debug\Dumper;
use Illuminate\Support\HigherOrderTapProxy;
use Illuminate\Support\Traits\Macroable;
use InvalidArgumentException;
use Prismic\Api;
use Prismic\Predicate;
use Prismic\Predicates;

class Builder
{
	use Macroable {
		__call as macroCall;
	}
	
	// @formatter:off
	/**
	 * All of the available predicates
	 *
	 * @var array
	 */
	public $predicates = [
		'at', 'not', 'any', 'in', 'fulltext', 'has',
		'missing', 'similar', 'near',
		'lt', 'gt', 'inRange',
		'dateAfter', 'dateBefore', 'dateBetween', 'dayOfMonth',
		'dayOfMonthAfter', 'dayOfMonthBefore', 'dayOfWeek',
		'dayOfWeekAfter', 'dayOfWeekBefore', 'month', 'monthAfter',
		'monthBefore', 'year', 'hour', 'hourAfter', 'hourBefore',
	];
	// @formatter:on
	
	/**
	 * Map of common query operators to predicates
	 *
	 * @var array
	 */
	public $predicate_aliases = [
		'=' => 'at',
		'<>' => 'not',
		'!=' => 'not',
		'<' => 'lt',
		'>' => 'gt',
	];
	
	/**
	 * @var Predicate[]
	 */
	public $query = [];
	
	/**
	 * @var string[]
	 */
	public $orderings = [];
	
	/**
	 * Links to eager load
	 *
	 * @var string[]
	 */
	public $fetchLinks = [];
	
	/**
	 * Page to retrieve
	 *
	 * @var int
	 */
	public $page = 1;
	
	/**
	 * Results per page
	 *
	 * @var int
	 */
	public $page_size = 20;
	
	/**
	 * @var \Galahad\Prismoquent\Prismoquent
	 */
	protected $api;
	
	/**
	 * @var \Galahad\Prismoquent\Model
	 */
	protected $model;
	
	/**
	 * Create a new query builder instance.
	 *
	 * @param Api $api
	 * @param \Galahad\Prismoquent\Model $model
	 */
	public function __construct(Prismoquent $api, Model $model)
	{
		$this->api = $api;
		$this->model = $model;
	}
	
	/**
	 * Add a predicate
	 *
	 * @param  string|array|\Closure $path
	 * @param  mixed $predicate
	 * @param  mixed $value
	 * @return \Galahad\Prismoquent\Builder
	 */
	public function where($path, $predicate = null, $value = null) : self
	{
		if (is_array($path)) {
			return $this->addArrayOfPredicates($path);
		}
		
		[$value, $predicate] = $this->prepareValueAndPredicate(
			$value, $predicate, 2 === func_num_args()
		);
		
		if ($this->invalidPredicate($predicate)) {
			[$value, $predicate] = [$predicate, 'at'];
		}
		
		if (null === $value) {
			return in_array($predicate, ['<>', '!=', 'not'])
				? $this->whereHas($path)
				: $this->whereMissing($path);
		}
		
		$this->query[] = Predicates::$predicate($path, $value);
		
		return $this;
	}
	
	/**
	 * Query on content type
	 *
	 * @param string $type
	 * @param string $predicate
	 * @return \Galahad\Prismoquent\Builder
	 */
	public function whereType($type, $predicate = 'at') : self
	{
		return $this->where('document.type', $predicate, $type);
	}
	
	/**
	 * Query on tag(s)
	 *
	 * @param string|array $tags
	 * @param string $predicate
	 * @return \Galahad\Prismoquent\Builder
	 */
	public function whereTags($tags, $predicate = 'at') : self
	{
		return $this->where('document.tags', $predicate, (array) $tags);
	}
	
	/**
	 * Query on first publication date
	 *
	 * @param $date
	 * @param string $predicate
	 * @return \Galahad\Prismoquent\Builder
	 */
	public function whereFirstPublicationDate($date, $predicate = 'at') : self
	{
		return $this->where('document.first_publication_date', $predicate, $date);
	}
	
	/**
	 * Query on last publication date
	 *
	 * @param $date
	 * @param string $predicate
	 * @return \Galahad\Prismoquent\Builder
	 */
	public function whereLastPublicationDate($date, $predicate = 'at') : self
	{
		return $this->where('document.last_publication_date', $predicate, $date);
	}
	
	/**
	 * Query on custom data
	 *
	 * @param $path
	 * @param string $predicate
	 * @param null $value
	 * @return \Galahad\Prismoquent\Builder
	 */
	public function whereMy($path, $predicate = 'at', $value = null) : self
	{
		[$value, $predicate] = $this->prepareValueAndPredicate(
			$value, $predicate, 2 === func_num_args()
		);
		
		return $this->where("my.{$path}", $predicate, $value);
	}
	
	/**
	 * Prepare the value and predicate.
	 *
	 * @param  string $value
	 * @param  string $predicate
	 * @param  bool $use_default
	 * @return array
	 *
	 * @throws \InvalidArgumentException
	 */
	public function prepareValueAndPredicate($value, $predicate, $use_default = false) : array
	{
		if ($use_default) {
			return [$predicate, 'at'];
		}
		
		$predicate = $this->applyPredicateAlias($predicate);
		
		if ($this->invalidPredicateAndValue($predicate, $value)) {
			throw new InvalidArgumentException('Illegal operator and value combination.');
		}
		
		return [$value, $predicate];
	}
	
	/**
	 * Add a raw predicate to query
	 *
	 * @param Predicate $predicate
	 * @return \Galahad\Prismoquent\Builder
	 */
	public function addPredicate(Predicate $predicate) : self
	{
		$this->query[] = $predicate;
		
		return $this;
	}
	
	/**
	 * Add an "in" predicate to the query
	 *
	 * @param  string $path
	 * @param  mixed $values
	 * @return \Galahad\Prismoquent\Builder
	 */
	public function whereIn($path, $values) : self
	{
		if ($values instanceof Arrayable) {
			$values = $values->toArray();
		}
		
		$this->query[] = Predicates::in($path, $values);
		
		return $this;
	}
	
	/**
	 * Alias for whereMissing()
	 *
	 * @param $column
	 * @return Builder
	 */
	public function whereNull($column) : self
	{
		return $this->whereMissing($column);
	}
	
	/**
	 * Add a missing predicate
	 *
	 * @param  string $path
	 * @return \Galahad\Prismoquent\Builder
	 */
	public function whereMissing($path) : self
	{
		$this->query[] = Predicates::missing($path);
		
		return $this;
	}
	
	/**
	 * Add a has predicate
	 *
	 * @param $path
	 * @return Builder
	 */
	public function whereHas($path) : self
	{
		$this->query[] = Predicates::has($path);
		
		return $this;
	}
	
	/**
	 * Alias for whereHas
	 *
	 * @param  string $column
	 * @return \Galahad\Prismoquent\Builder
	 */
	public function whereNotNull($column) : self
	{
		return $this->whereHas($column);
	}
	
	/**
	 * Alias for whereInRange
	 *
	 * @param $column
	 * @param int[]|float[] $values
	 * @return \Galahad\Prismoquent\Builder
	 */
	public function whereBetween($column, array $values) : self
	{
		return $this->whereInRange($column, $values[0], $values[1]);
	}
	
	/**
	 * Add a where between statement to the query.
	 *
	 * @param string $path
	 * @param int|float $lower_limit
	 * @param int|float $upper_limit
	 * @return \Galahad\Prismoquent\Builder
	 */
	public function whereInRange($path, $lower_limit, $upper_limit) : self
	{
		$this->query[] = Predicates::inRange($path, $lower_limit, $upper_limit);
		
		return $this;
	}
	
	/**
	 * Add dateAfter predicate
	 *
	 * @param string $path
	 * @param int|DateTime $value
	 * @return \Galahad\Prismoquent\Builder
	 */
	public function whereDateAfter($path, $value) : self
	{
		$this->query[] = Predicates::dateAfter($path, $value);
		
		return $this;
	}
	
	/**
	 * Add dateBefore predicate
	 *
	 * @param string $path
	 * @param int|DateTime $value
	 * @return \Galahad\Prismoquent\Builder
	 */
	public function whereDateBefore($path, $value) : self
	{
		$this->query[] = Predicates::dateBefore($path, $value);
		
		return $this;
	}
	
	/**
	 * Add a dateBetween predicate
	 *
	 * @param string $path
	 * @param string|DateTime $before
	 * @param string|DateTime $after
	 * @return \Galahad\Prismoquent\Builder
	 */
	public function whereDateBetween($path, $before, $after) : self
	{
		$this->query[] = Predicates::dateBetween($path, $before, $after);
		
		return $this;
	}
	
	/**
	 * Add a dayOfMonth predicate
	 *
	 * @param string $path
	 * @param string|int $value
	 * @return \Galahad\Prismoquent\Builder
	 */
	public function whereDayOfMonth($path, $value = null) : self
	{
		$this->query[] = Predicates::dayOfMonth($path, $value);
		
		return $this;
	}
	
	/**
	 * Add a dayOfMonthAfter predicate
	 *
	 * @param string $path
	 * @param string|int $value
	 * @return \Galahad\Prismoquent\Builder
	 */
	public function whereDayOfMonthAfter($path, $value) : self
	{
		$this->query[] = Predicates::dayOfMonthAfter($path, $value);
		
		return $this;
	}
	
	/**
	 * Add a dayOfMonthBefore predicate
	 *
	 * @param string $path
	 * @param string|int $value
	 * @return \Galahad\Prismoquent\Builder
	 */
	public function whereDayOfMonthBefore($path, $value) : self
	{
		$this->query[] = Predicates::dayOfMonthBefore($path, $value);
		
		return $this;
	}
	
	/**
	 * Add a dayOfWeek predicate
	 *
	 * @param string $path
	 * @param string|int $value
	 * @return \Galahad\Prismoquent\Builder
	 */
	public function whereDayOfWeek($path, $value) : self
	{
		$this->query[] = Predicates::dayOfWeek($path, $value);
		
		return $this;
	}
	
	/**
	 * Add a dayOfWeekAfter predicate
	 *
	 * @param string $path
	 * @param string|int $value
	 * @return \Galahad\Prismoquent\Builder
	 */
	public function whereDayOfWeekAfter($path, $value) : self
	{
		$this->query[] = Predicates::dayOfWeekAfter($path, $value);
		
		return $this;
	}
	
	/**
	 * Add a dayOfWeekBefore predicate
	 *
	 * @param string $path
	 * @param string|int $value
	 * @return \Galahad\Prismoquent\Builder
	 */
	public function whereDayOfWeekBefore($path, $value) : self
	{
		$this->query[] = Predicates::dayOfWeekBefore($path, $value);
		
		return $this;
	}
	
	/**
	 * Add a month predicate
	 *
	 * @param string $path
	 * @param string|int $value
	 * @return \Galahad\Prismoquent\Builder
	 */
	public function whereMonth($path, $value) : self
	{
		$this->query[] = Predicates::month($path, $value);
		
		return $this;
	}
	
	/**
	 * Add a monthAfter predicate
	 *
	 * @param string $path
	 * @param string|int $value
	 * @return \Galahad\Prismoquent\Builder
	 */
	public function whereMonthAfter($path, $value) : self
	{
		$this->query[] = Predicates::monthAfter($path, $value);
		
		return $this;
	}
	
	/**
	 * Add a monthBefore predicate
	 *
	 * @param string $path
	 * @param string|int $value
	 * @return \Galahad\Prismoquent\Builder
	 */
	public function whereMonthBefore($path, $value) : self
	{
		$this->query[] = Predicates::monthBefore($path, $value);
		
		return $this;
	}
	
	/**
	 * Add a year predicate
	 *
	 * @param string $path
	 * @param int $value
	 * @return \Galahad\Prismoquent\Builder
	 */
	public function whereYear($path, $value) : self
	{
		$this->query[] = Predicates::year($path, $value);
		
		return $this;
	}
	
	/**
	 * Add a hour predicate
	 *
	 * @param string $path
	 * @param int $value
	 * @return \Galahad\Prismoquent\Builder
	 */
	public function whereHour($path, $value) : self
	{
		$this->query[] = Predicates::hour($path, $value);
		
		return $this;
	}
	
	/**
	 * Add a hourAfter predicate
	 *
	 * @param string $path
	 * @param int $value
	 * @return \Galahad\Prismoquent\Builder
	 */
	public function whereHourAfter($path, $value) : self
	{
		$this->query[] = Predicates::hourAfter($path, $value);
		
		return $this;
	}
	
	/**
	 * Add a hourBefore predicate
	 *
	 * @param string $path
	 * @param int $value
	 * @return \Galahad\Prismoquent\Builder
	 */
	public function whereHourBefore($path, $value) : self
	{
		$this->query[] = Predicates::hourBefore($path, $value);
		
		return $this;
	}
	
	/**
	 * Add a "where day" statement to the query.
	 *
	 * @param  string $path
	 * @param  mixed $value
	 * @return \Galahad\Prismoquent\Builder
	 */
	public function whereDay($path, $value) : self
	{
		return $this->whereDayOfMonth($path, $value);
	}
	
	/**
	 * Add an ordering
	 *
	 * @param  string $ordering
	 * @param  string $direction
	 * @return \Galahad\Prismoquent\Builder
	 */
	public function orderBy($ordering, $direction = 'asc') : self
	{
		if ('desc' === $direction) {
			$ordering .= ' desc';
		}
		
		$this->orderings[] = $ordering;
		
		return $this;
	}
	
	/**
	 * Add a ascending ordering
	 *
	 * @param  string $path
	 * @return \Galahad\Prismoquent\Builder
	 */
	public function orderByAsc($path) : self
	{
		return $this->orderBy($path, 'asc');
	}
	
	/**
	 * Add a descending ordering
	 *
	 * @param  string $path
	 * @return \Galahad\Prismoquent\Builder
	 */
	public function orderByDesc($path) : self
	{
		return $this->orderBy($path, 'desc');
	}
	
	/**
	 * Order by most recent publication date
	 *
	 * @param  string $path
	 * @return \Galahad\Prismoquent\Builder
	 */
	public function latest($path = 'document.last_publication_date') : self
	{
		return $this->orderBy($path, 'desc');
	}
	
	/**
	 * Order by least recent publication date
	 *
	 * @param  string $column
	 * @return \Galahad\Prismoquent\Builder
	 */
	public function oldest($column = 'document.last_publication_date') : self
	{
		return $this->orderBy($column, 'asc');
	}
	
	/**
	 * Alias to set the "limit" value of the query.
	 *
	 * @param  int $value
	 * @return \Galahad\Prismoquent\Builder
	 */
	public function take($value) : self
	{
		return $this->limit($value);
	}
	
	/**
	 * Set the "limit" value of the query.
	 *
	 * @param  int $value
	 * @return \Galahad\Prismoquent\Builder
	 */
	public function limit(int $value) : self
	{
		if ($value > 100) {
			throw new InvalidArgumentException('Page size cannot be greater than 100');
		}
		
		$this->page_size = $value;
		
		return $this;
	}
	
	/**
	 * Set the limit and offset for a given page.
	 *
	 * @param  int $page
	 * @param  int $per_page
	 * @return \Galahad\Prismoquent\Builder
	 */
	public function forPage(int $page, int $per_page = 20) : self
	{
		$this->page = $page;
		$this->limit($per_page);
		
		return $this;
	}
	
	public function paginate($per_page = null, $page_name = 'page', $page = null) : Results
	{
		$page = $page ?: Paginator::resolveCurrentPage($page_name);
		
		$per_page = $per_page ?: $this->model->getPerPage();
		
		$this->forPage($page, $per_page);
		
		return $this->get();
	}
	
	/**
	 * Find by ID
	 *
	 * @param string|array|Arrayable $id
	 * @return null|\Galahad\Prismoquent\Model|\Galahad\Prismoquent\Results
	 */
	public function find($id)
	{
		if (is_array($id) || $id instanceof Arrayable) {
			return $this->findMany($id);
		}
		
		return $this->where('document.id', 'at', $id)->first();
	}
	
	/**
	 * Find many documents at once
	 *
	 * @param $ids
	 * @return \Galahad\Prismoquent\Results
	 */
	public function findMany($ids)
	{
		return $this->whereIn('document.id', $ids)->get();
	}
	
	/**
	 * Find a document by its ID or throw an exception
	 *
	 * @param  mixed  $id
	 * @return \Galahad\Prismoquent\Model|\Galahad\Prismoquent\Results
	 *
	 * @throws \Galahad\Prismoquent\Exceptions\DocumentNotFoundException
	 */
	public function findOrFail($id)
	{
		$result = $this->find($id);
		
		if (is_array($id)) {
			if (count($result) === count(array_unique($id))) {
				return $result;
			}
		} elseif (null !== $result) {
			return $result;
		}
		
		throw (new DocumentNotFoundException())->setDocument(
			get_class($this->model), $id
		);
	}
	
	/**
	 * Find a content type by UID/slug
	 *
	 * @param $type
	 * @param $uid
	 * @return \Galahad\Prismoquent\Model|null
	 */
	public function findByUID($type, $uid) : ?Model
	{
		return $this->where("my.{$type}.uid", 'at', $uid)->first();
	}
	
	/**
	 * Get a single column's value from the first result of a query.
	 *
	 * @param  string $path
	 * @return mixed
	 */
	public function value($path)
	{
		$results = $this->get();
		
		if ($results->isEmpty()) {
			return null;
		}
		
		return data_get($results->first(), $path);
	}
	
	/**
	 * Set the links that should be eager loaded
	 *
	 * @param  mixed  $links
	 * @return \Galahad\Prismoquent\Builder
	 */
	public function with($links) : self
	{
		$this->fetchLinks = array_merge($this->fetchLinks, $links);
		
		return $this;
	}
	
	/**
	 * Execute the query
	 *
	 * @return \Galahad\Prismoquent\Results
	 */
	public function get() : Results
	{
		/** @noinspection PhpParamsInspection */
		return new Results($this->model, $this->api->query($this->query, $this->opts()));
	}
	
	public function dump() : self
	{
		$dumper = new Dumper();
		
		/** @noinspection ForgottenDebugOutputInspection */
		(new HigherOrderTapProxy($dumper))
			->dump($this->query)
			->dump($this->opts());
		
		return $this;
	}
	
	protected function opts() : array
	{
		$opts = [
			'pageSize' => $this->page_size,
			'page' => $this->page,
		];
		
		if (!empty($this->orderings)) {
			$opts['orderings'] = '['.implode(',', $this->orderings).']';
		}
		
		if (!empty($this->fetchLinks)) {
			$opts['fetchLinks'] = implode(',', $this->fetchLinks);
		}
		
		return $opts;
	}
	
	/**
	 * Determine if any rows exist for the current query.
	 *
	 * @return bool
	 */
	public function exists() : bool
	{
		return $this->get()->isNotEmpty();
	}
	
	/**
	 * Determine if no rows exist for the current query.
	 *
	 * @return bool
	 */
	public function doesntExist() : bool
	{
		return !$this->exists();
	}
	
	/**
	 * Retrieve the "count" result of the query.
	 *
	 * @return int
	 */
	public function count() : int
	{
		return $this->get()->count();
	}
	
	/**
	 * Get a new instance of the query builder.
	 *
	 * @return \Galahad\Prismoquent\Builder
	 */
	public function newQuery() : self
	{
		return new static($this->api, $this->model);
	}
	
	/**
	 * Clone the query without the given properties.
	 *
	 * @param  array $properties
	 * @return \Galahad\Prismoquent\Builder
	 */
	public function cloneWithout(array $properties) : self
	{
		return tap(clone $this, function($clone) use ($properties) {
			foreach ($properties as $property) {
				$clone->{$property} = null;
			}
		});
	}
	
	/**
	 * Determine if the builder prevents accessing missing attributes.
	 *
	 * @return bool
	 */
	public function preventsAccessingMissingAttributes()
	{
		return false;
	}
	
	/**
	 * Handle dynamic method calls into the method.
	 *
	 * @param  string $method
	 * @param  array $parameters
	 * @return mixed
	 *
	 * @throws \BadMethodCallException
	 */
	public function __call($method, $parameters)
	{
		if (static::hasMacro($method)) {
			return $this->macroCall($method, $parameters);
		}
		
		throw new BadMethodCallException(sprintf(
			'Method %s::%s does not exist.', static::class, $method
		));
	}
	
	/**
	 * Chunk the results of the query
	 *
	 * @param  int $count
	 * @param  callable $callback
	 * @return bool
	 */
	public function chunk($count, callable $callback) : bool
	{
		$page = 1;
		
		do {
			$results = $this->forPage($page, $count)->get();
			
			if ($results->isEmpty()) {
				break;
			}
			
			if (false === $callback($results, $page)) {
				return false;
			}
			
			$page++;
		} while ($results->hasMorePages());
		
		return true;
	}
	
	/**
	 * Execute a callback over each item while chunking
	 *
	 * @param  callable $callback
	 * @param  int $count
	 * @return bool
	 */
	public function each(callable $callback, $count = 100) : bool
	{
		return $this->chunk($count, function($results) use ($callback) {
			foreach ($results as $key => $value) {
				if (false === $callback($value, $key)) {
					return false;
				}
			}
		});
	}
	
	/**
	 * Execute the query and get the first result
	 *
	 * @return null|\Galahad\Prismoquent\Model
	 */
	public function first() : ?Model
	{
		return $this->take(1)->get()->first();
	}
	
	/**
	 * Execute the query and get the first result or throw an exception.
	 *
	 * @return \Galahad\Prismoquent\Model
	 *
	 * @throws \Galahad\Prismoquent\Exceptions\DocumentNotFoundException
	 */
	public function firstOrFail()
	{
		if ($model = $this->first()) {
			return $model;
		}
		
		throw (new DocumentNotFoundException())->setDocument(get_class($this->model));
	}
	
	/**
	 * Apply the callback's query changes if the given "value" is true.
	 *
	 * @param  mixed $value
	 * @param  callable $callback
	 * @param  callable $default
	 * @return mixed
	 */
	public function when($value, callable $callback, callable $default = null)
	{
		if ($value) {
			return $callback($this, $value) ?: $this;
		}
		
		if ($default) {
			return $default($this, $value) ?: $this;
		}
		
		return $this;
	}
	
	/**
	 * Pass the query to a given callback.
	 *
	 * @param  \Closure $callback
	 * @return \Galahad\Prismoquent\Builder
	 */
	public function tap($callback) : self
	{
		return $this->when(true, $callback);
	}
	
	/**
	 * Apply the callback's query changes if the given "value" is false.
	 *
	 * @param  mixed $value
	 * @param  callable $callback
	 * @param  callable $default
	 * @return mixed
	 */
	public function unless($value, callable $callback, callable $default = null)
	{
		if (!$value) {
			return $callback($this, $value) ?: $this;
		}
		
		if ($default) {
			return $default($this, $value) ?: $this;
		}
		
		return $this;
	}
	
	/**
	 * Add an array of predicates to the query.
	 *
	 * @param  array $predicates
	 * @param  string $method
	 * @return \Galahad\Prismoquent\Builder
	 */
	protected function addArrayOfPredicates($predicates, $method = 'where') : self
	{
		foreach ($predicates as $path => $value) {
			if (is_numeric($path) && is_array($value)) {
				$this->{$method}(...array_values($value));
			} else {
				$this->$method($path, 'at', $value);
			}
		}
		
		return $this;
	}
	
	/**
	 * Determine if the given predicate and value combination is legal.
	 *
	 * Prevents using null values with invalid predicates.
	 *
	 * @param  string $predicate
	 * @param  mixed $value
	 * @return bool
	 */
	protected function invalidPredicateAndValue($predicate, $value) : bool
	{
		return null === $value
			&& !$this->invalidPredicate($predicate)
			&& !in_array($predicate, ['=', '<>', '!=', 'at', 'not']);
	}
	
	/**
	 * Determine if the given operator is supported.
	 *
	 * @param  string $predicate
	 * @return bool
	 */
	protected function invalidPredicate($predicate) : bool
	{
		return !in_array(strtolower($predicate), $this->predicates, true)
			&& !isset($this->predicate_aliases[$predicate]);
	}
	
	/**
	 * Swap "=" for "at" etc.
	 *
	 * @param $predicate
	 * @return string
	 */
	protected function applyPredicateAlias($predicate) : string
	{
		return $this->predicate_aliases[$predicate] ?? $predicate;
	}
}
