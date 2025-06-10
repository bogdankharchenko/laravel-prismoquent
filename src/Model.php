<?php

namespace Galahad\Prismoquent;

use ArrayAccess;
use DateTime;
use Illuminate\Contracts\Routing\UrlRoutable;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Jsonable;
use Illuminate\Database\Eloquent\Concerns\HasAttributes;
use Illuminate\Database\Eloquent\Concerns\HasEvents;
use Illuminate\Database\Eloquent\Concerns\HasRelationships;
use Illuminate\Database\Eloquent\Concerns\HidesAttributes;
use Illuminate\Database\Eloquent\JsonEncodingException;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use JsonSerializable;
use Prismic\Api;
use Prismic\Document;
use Prismic\Fragment\FragmentInterface;
use Prismic\Fragment\Group;
use Prismic\Fragment\GroupDoc;
use Prismic\Fragment\Link\DocumentLink;
use RuntimeException;

/**
 * @mixin \Galahad\Prismoquent\Builder
 */
abstract class Model implements ArrayAccess, Arrayable, Jsonable, JsonSerializable, UrlRoutable
{
	use HasRelationships, HasEvents, HidesAttributes, HasAttributes {
		castAttribute as eloquentCastAttribute;
	}
	
	protected const DOCUMENT_ATTRIBUTES = [
		'slug' => 'getSlug',
		'id' => 'getId',
		'uid' => 'getUid',
		'type' => 'getType',
		'href' => 'getHref',
		'tags' => 'getTags',
		'slugs' => 'getSlugs',
		'lang' => 'getLang',
		'alternate_languages' => 'getAlternateLanguages',
		'data' => 'getData',
		'first_publication_date' => 'getFirstPublicationDate',
		'last_publication_date' => 'getLastPublicationDate',
	];
	
	/**
	 * The event dispatcher instance.
	 *
	 * @var \Illuminate\Contracts\Events\Dispatcher
	 */
	protected static $dispatcher;
	
	/**
	 * @var \Galahad\Prismoquent\Prismoquent
	 */
	protected static $api;
	
	/**
	 * The original Prismic document
	 *
	 * @var \Prismic\Document|object
	 */
	public $document;
	
	/**
	 * The API ID of the content type this represents
	 *
	 * @var string
	 */
	protected $type;
	
	/**
	 * Links to always eager load
	 *
	 * @var array
	 */
	protected $with = [];
	
	/**
	 * The number of models to return for pagination.
	 *
	 * @var int
	 */
	protected $perPage = 15;
	
	/**
	 * Create a new Prismoquent model instance.
	 *
	 * @param Document|stdClass|null $document
	 */
	public function __construct($document = null)
	{
		if ($document) {
			$this->setDocument($document);
		}
	}
	
	/**
	 * Set the Prismic API instance
	 *
	 * @param Api $api
	 */
	public static function setApi(Prismoquent $api) : void
	{
		static::$api = $api;
	}
	
	/**
	 * Begin querying the model.
	 *
	 * @return \Galahad\Prismoquent\Builder
	 */
	public static function query() : Builder
	{
		return (new static())->newQuery();
	}
	
	/**
	 * Get all of the documents
	 *
	 * @return \Galahad\Prismoquent\Results
	 */
	public static function all() : Results
	{
		return static::query()->get();
	}
	
	/**
	 * Find model by ID
	 *
	 * @param $id
	 * @return \Galahad\Prismoquent\Model|\Galahad\Prismoquent\Results|null
	 */
	public static function find($id)
	{
		return static::query()->find($id);
	}
	
	/**
	 * Find model by UID/slug
	 *
	 * @param $uid
	 * @return \Galahad\Prismoquent\Model|null
	 */
	public static function findByUID($uid) : ?self
	{
		$instance = new static();
		return $instance->newQuery()->findByUID($instance->getType(), $uid);
	}
	
	/**
	 * Handle dynamic static method calls into the method.
	 *
	 * @param  string $method
	 * @param  array $parameters
	 * @return mixed
	 */
	public static function __callStatic($method, $parameters)
	{
		return (new static())->$method(...$parameters);
	}
	
	/**
	 * Begin querying a model with eager loading links
	 *
	 * @param  array|string $relations
	 * @return \Galahad\Prismoquent\Builder
	 */
	public static function with($relations) : Builder
	{
		return static::query()->with(
			is_string($relations) ? func_get_args() : $relations
		);
	}
	
	/**
	 * Get the observable event names.
	 *
	 * @return array
	 */
	public function getObservableEvents()
	{
		return array_merge(['retrieved'], $this->observables);
	}
	
	/**
	 * @param Document|stdClass $document
	 * @return \Galahad\Prismoquent\Model
	 */
	public function setDocument($document) : self
	{
		$this->document = $document;
		
		return $this;
	}
	
	/**
	 * Get a new query builder for the model's table.
	 *
	 * @return \Galahad\Prismoquent\Builder
	 */
	public function newQuery() : Builder
	{
		return (new Builder(static::$api, $this))
			->with($this->with)
			->whereType($this->getType());
	}
	
	/**
	 * Create a new instance of the given model
	 *
	 * @param Document|stdClass $document
	 * @return static
	 */
	public function newInstance($document)
	{
		return new static($document);
	}
	
	/**
	 * Create a new model instance from a document retrieved via a Builder
	 *
	 * @param Document|stdClass $document
	 * @return static
	 */
	public function newFromBuilder($document)
	{
		$model = $this->newInstance($document);
		
		$model->fireModelEvent('retrieved', false);
		
		return $model;
	}
	
	/**
	 * Eager load relations on the model.
	 *
	 * @param  array|string $relations
	 * @return $this
	 */
	public function load($relations)
	{
		$query = $this->newQueryWithoutRelationships()->with(
			is_string($relations) ? func_get_args() : $relations
		);
		
		$query->eagerLoadRelations([$this]);
		
		return $this;
	}
	
	/**
	 * Eager load relations on the model if they are not already eager loaded.
	 *
	 * @param  array|string $relations
	 * @return $this
	 */
	public function loadMissing($relations)
	{
		$relations = is_string($relations) ? func_get_args() : $relations;
		
		$this->newCollection([$this])->loadMissing($relations);
		
		return $this;
	}
	
	/**
	 * Convert the model instance to an array.
	 *
	 * @return array
	 */
	public function toArray() : array
	{
		return $this->attributesToArray();
	}
	
	/**
	 * Convert the model instance to JSON.
	 *
	 * @param  int $options
	 * @return string
	 *
	 * @throws \Illuminate\Database\Eloquent\JsonEncodingException
	 */
	public function toJson($options = 0) : string
	{
		$json = json_encode($this->jsonSerialize(), $options);
		
		if (JSON_ERROR_NONE !== json_last_error()) {
			throw JsonEncodingException::forModel($this, json_last_error_msg());
		}
		
		return $json;
	}
	
	/**
	 * Convert the object into something JSON serializable.
	 *
	 * @return array
	 */
	public function jsonSerialize() : array
	{
		return $this->toArray();
	}
	
	/**
	 * Reload a fresh model instance from the database.
	 *
	 * @param  array|string $with
	 * @return static|null
	 */
	public function fresh() : self
	{
		return $this->newQuery()->find($this->document->getId());
	}
	
	/**
	 * Reload the current model instance with fresh attributes from the database.
	 *
	 * @return \Galahad\Prismoquent\Model
	 */
	public function refresh() : self
	{
		/** @noinspection PhpParamsInspection */
		$this->setDocument($this->fresh()->document);
		
		return $this;
	}
	
	/**
	 * Determine if two models have the same ID and belong to the same table.
	 *
	 * @param  \Illuminate\Database\Eloquent\Model|null $model
	 * @return bool
	 */
	public function is(self $model = null) : bool
	{
		return null !== $model
			&& $this->document->getId() === $model->document->getId();
	}
	
	/**
	 * Determine if two models are not the same.
	 *
	 * @param  \Illuminate\Database\Eloquent\Model|null $model
	 * @return bool
	 */
	public function isNot($model) : bool
	{
		return !$this->is($model);
	}
	
	/**
	 * Get the table associated with the model.
	 *
	 * @return string
	 */
	public function getType() : string
	{
		if (null === $this->type) {
			$this->type = str_replace('\\', '', Str::snake(class_basename($this)));
		}
		
		return $this->type;
	}
	
	/**
	 * Get the document's unique ID
	 *
	 * @return string
	 */
	public function getKey() : ?string
	{
		if ($this->document instanceof Document) {
			return $this->document->getId() ?? null;
		} else {
			return $this->document->id ?? null;
		}
	}
	
	/**
	 * Get the document's slug
	 *
	 * @return string
	 */
	public function getRouteKey() : ?string
	{
		if ($this->document instanceof Document) {
			return $this->document->getUid() ?? null;
		} else {
			return $this->document->uid ?? null;
		}
	}
	
	/**
	 * @return string
	 */
	public function getRouteKeyName() : string
	{
		return 'uid';
	}
	
	/**
	 * Retrieve the model for a bound value.
	 *
	 * @param  mixed $value
	 * @param  string|null $field
	 * @return \Galahad\Prismoquent\Model
	 */
	public function resolveRouteBinding($value, $field = null) : ?self
	{
		return $this->newQuery()->findByUID($this->getType(), $value);
	}

	/**
	 * Retrieve the child model for a bound value.
	 *
	 * @param  string $childType
	 * @param  mixed $value
	 * @param  string|null $field
	 * @return \Galahad\Prismoquent\Model|null
	 */
	public function resolveChildRouteBinding($childType, $value, $field = null) : ?self
	{
		return null;
	}
	
	/**
	 * Get attribute value
	 *
	 * @param $key
	 * @return mixed|null
	 */
	public function getAttribute($key)
	{
		if (!$key) {
			return null;
		}
		
		// Unlike Eloquent, where relationships are loaded via a column with
		// a separate name, Prismic links are likely to share the desired name.
		// We use the 'LinkResolver' suffix, and look for relationships first
		// to address this (otherwise getAttribute would almost always hit first.
		if ($related = $this->getRelationValue(Str::camel($key).'LinkResolver')) {
			return $related;
		}
		
		$value = $this->getAttributeValue($key);
		
		if ($value instanceof DocumentLink) {
			return $this->resolveLink($value);
		}
		
		return $value;
	}
	
	public function getCasts()
	{
		// $type = $this->getType();
		//
		// return collect($this->casts)
		// 	->mapWithKeys(function($value, $key) use ($type) {
		// 		return ["data.{$type}.$key" => $value];
		// 	})
		// 	->toArray();
		
		return $this->casts;
	}
	
	/**
	 * Determine if a get mutator exists for an attribute.
	 *
	 * @param  string $key
	 * @return bool
	 */
	public function hasGetMutator($key)
	{
		$mutator_key = str_replace('.', '_', $key);
		return method_exists($this, 'get'.Str::studly($mutator_key).'Attribute');
	}
	
	/**
	 * Set a given attribute on the model.
	 *
	 * @param  string $key
	 * @param  mixed $value
	 * @return mixed
	 */
	public function setAttribute($key, $value)
	{
		throw new RuntimeException('Prismoquent models are read-only.');
	}
	
	/**
	 * Set a given JSON attribute on the model.
	 *
	 * @param  string $key
	 * @param  mixed $value
	 * @return $this
	 */
	public function fillJsonAttribute($key, $value)
	{
		throw new RuntimeException('Prismoquent models are read-only.');
	}
	
	/**
	 * Get all of the current attributes on the model.
	 *
	 * @return array
	 */
	public function getAttributes()
	{
		if ($this->document instanceof Document) {
			return (array) $this->document->getData();
		} else {
			// stdClass object from Prismic API v5
			return (array) ($this->document->data ?? []);
		}
	}
	
	/**
	 * Set the array of model attributes. No checking is done.
	 *
	 * @param  array $attributes
	 * @param  bool $sync
	 * @return $this
	 */
	public function setRawAttributes(array $attributes, $sync = false)
	{
		throw new RuntimeException('Prismoquent models are read-only.');
	}
	
	/**
	 * Get the attributes that should be converted to dates.
	 *
	 * @return array
	 */
	public function getDates()
	{
		$dates = $this->dates ?: [];
		return array_unique(array_merge($dates, ['first_publication_date', 'last_publication_date']));
	}
	
	/**
	 * Get the format for database stored dates.
	 *
	 * @return string
	 */
	public function getDateFormat()
	{
		return DateTime::ISO8601;
	}
	
	/**
	 * Tell HasAttributes to not try to handle auto-increment on this
	 *
	 * @return bool
	 */
	public function getIncrementing()
	{
		return false;
	}
	
	/**
	 * Get the number of models to return per page.
	 *
	 * @return int
	 */
	public function getPerPage() : int
	{
		return $this->perPage;
	}
	
	/**
	 * Set the number of models to return per page.
	 *
	 * @param  int  $perPage
	 * @return $this
	 */
	public function setPerPage(int $perPage) : self
	{
		$this->perPage = $perPage;
		
		return $this;
	}
	
	/**
	 * Dynamically retrieve attributes on the model.
	 *
	 * @param  string $key
	 * @return mixed
	 */
	public function __get($key)
	{
		return $this->getAttribute($key);
	}
	
	/**
	 * Dynamically set attributes on the model.
	 *
	 * @param  string $key
	 * @param  mixed $value
	 * @return void
	 */
	public function __set($key, $value)
	{
		throw new RuntimeException('Prismoquent models are read-only.');
	}
	
	/**
	 * Determine if the given attribute exists.
	 *
	 * @param  mixed $offset
	 * @return bool
	 */
	public function offsetExists($offset) : bool
	{
		return null !== $this->getAttribute($offset);
	}
	
	/**
	 * Get the value for a given offset.
	 *
	 * @param  mixed $offset
	 * @return mixed
	 */
	public function offsetGet($offset)
	{
		return $this->getAttribute($offset);
	}
	
	/**
	 * Set the value for a given offset.
	 *
	 * @param  mixed $offset
	 * @param  mixed $value
	 * @return void
	 */
	public function offsetSet($offset, $value) : void
	{
		throw new RuntimeException('Prismoquent models are read-only.');
	}
	
	/**
	 * Unset the value for a given offset.
	 *
	 * @param  mixed $offset
	 * @return void
	 */
	public function offsetUnset($offset) : void
	{
		throw new RuntimeException('Prismoquent models are read-only.');
	}
	
	/**
	 * Determine if an attribute or relation exists on the model.
	 *
	 * @param  string $key
	 * @return bool
	 */
	public function __isset($key)
	{
		return $this->offsetExists($key);
	}
	
	/**
	 * Unset an attribute on the model.
	 *
	 * @param  string $key
	 * @return void
	 */
	public function __unset($key) : void
	{
		throw new RuntimeException('Prismoquent models are read-only.');
	}
	
	/**
	 * Handle dynamic method calls into the model.
	 *
	 * @param  string $method
	 * @param  array $parameters
	 * @return mixed
	 */
	public function __call($method, $parameters)
	{
		return $this->newQuery()->$method(...$parameters);
	}
	
	/**
	 * Convert the model to its string representation.
	 *
	 * @return string
	 */
	public function __toString()
	{
		return $this->toJson();
	}
	
	protected function getDocumentAttribute($key)
	{
		if (isset(static::DOCUMENT_ATTRIBUTES[$key])) {
			$method = static::DOCUMENT_ATTRIBUTES[$key];
			
			// Handle both Document objects and stdClass objects from Prismic API v5
			if ($this->document instanceof Document) {
				return $this->document->$method();
			} else {
				// stdClass object - map method calls to properties
				return $this->getStdClassDocumentProperty($key);
			}
		}
		
		return null;
	}
	
	/**
	 * Get document properties from stdClass response
	 *
	 * @param string $key
	 * @return mixed
	 */
	protected function getStdClassDocumentProperty($key)
	{
		switch ($key) {
			case 'id':
				return $this->document->id ?? null;
			case 'uid':
				return $this->document->uid ?? null;
			case 'type':
				return $this->document->type ?? null;
			case 'href':
				return $this->document->href ?? null;
			case 'tags':
				return $this->document->tags ?? [];
			case 'slug':
				return $this->document->slug ?? null;
			case 'slugs':
				return $this->document->slugs ?? [];
			case 'lang':
				return $this->document->lang ?? null;
			case 'alternate_languages':
				return $this->document->alternate_languages ?? [];
			case 'data':
				return $this->document->data ?? null;
			case 'first_publication_date':
				return $this->document->first_publication_date ?? null;
			case 'last_publication_date':
				return $this->document->last_publication_date ?? null;
			default:
				return null;
		}
	}
	
	/**
	 * Get a field from document data - works with both Document and stdClass
	 *
	 * @param string $path
	 * @return mixed
	 */
	protected function getDocumentField($path)
	{
		if ($this->document instanceof Document) {
			return $this->document->get($path);
		} else {
			// For stdClass, navigate the path manually
			$parts = explode('.', $path);
			$current = $this->document;
			
			foreach ($parts as $part) {
				if (is_object($current) && isset($current->$part)) {
					$current = $current->$part;
				} elseif (is_array($current) && isset($current[$part])) {
					$current = $current[$part];
				} else {
					return null;
				}
			}
			
			return $current;
		}
	}
	
	/**
	 * Cast an attribute to a native PHP type.
	 *
	 * @param  string $key
	 * @param  mixed $value
	 * @return mixed
	 */
	protected function castAttribute($key, $value)
	{
		if (null === $value) {
			return $value;
		}
		
		// Handle Prismic SDK v5 fragment structure
		if (is_object($value) && isset($value->type, $value->value)) {
			$cast = $this->getCastType($key);
			
			if ('html' === $cast) {
				return new HtmlString($this->convertToHtml($value));
			}
			
			if ('text' === $cast) {
				return $this->convertToText($value);
			}
		}
		
		// Handle legacy FragmentInterface (SDK v4 compatibility)
		if ($value instanceof FragmentInterface) {
			$cast = $this->getCastType($key);
			
			if ('html' === $cast) {
				return new HtmlString($value->asHtml(static::$api->resolver));
			}
			
			if ('text' === $cast) {
				return $value->asText();
			}
		}
		
		return $this->eloquentCastAttribute($key, $value);
	}

	/**
	 * Convert Prismic SDK v5 fragment to HTML
	 *
	 * @param object $fragment
	 * @return string
	 */
	protected function convertToHtml($fragment)
	{
		if ($fragment->type === 'StructuredText' && is_array($fragment->value)) {
			$html = '';
			foreach ($fragment->value as $block) {
				$html .= $this->convertBlockToHtml($block);
			}
			return $html;
		}
		
		return '';
	}

	/**
	 * Convert Prismic SDK v5 fragment to text
	 *
	 * @param object $fragment
	 * @return string
	 */
	protected function convertToText($fragment)
	{
		if ($fragment->type === 'StructuredText' && is_array($fragment->value)) {
			$text = '';
			foreach ($fragment->value as $block) {
				$text .= ($block->text ?? '') . "\n";
			}
			return trim($text);
		}
		
		return '';
	}

	/**
	 * Convert a single block to HTML
	 *
	 * @param object $block
	 * @return string
	 */
	protected function convertBlockToHtml($block)
	{
		$text = $block->text ?? '';
		$spans = $block->spans ?? [];
		
		// Apply spans in reverse order to maintain correct positioning
		$sortedSpans = collect($spans)->sortByDesc('start');
		
		foreach ($sortedSpans as $span) {
			$start = $span->start;
			$end = $span->end;
			$spanText = substr($text, $start, $end - $start);
			
			if ($span->type === 'strong') {
				$wrappedText = "<strong>{$spanText}</strong>";
			} elseif ($span->type === 'em') {
				$wrappedText = "<em>{$spanText}</em>";
			} else {
				$wrappedText = $spanText;
			}
			
			$text = substr_replace($text, $wrappedText, $start, $end - $start);
		}
		
		// Wrap in appropriate block tag
		switch ($block->type) {
			case 'heading1':
				return "<h1>{$text}</h1>";
			case 'heading2':
				return "<h2>{$text}</h2>";
			case 'heading3':
				return "<h3>{$text}</h3>";
			case 'heading4':
				return "<h4>{$text}</h4>";
			case 'heading5':
				return "<h5>{$text}</h5>";
			case 'heading6':
				return "<h6>{$text}</h6>";
			case 'paragraph':
				return "<p>{$text}</p>";
			default:
				return $text;
		}
	}
	
	/**
	 * Resolve a link as a relationship
	 *
	 * @param  string $method
	 * @return mixed
	 *
	 * @throws \LogicException
	 */
	protected function getRelationshipFromMethod($method)
	{
		$relation = $this->$method();
		
		if ($relation instanceof Collection) {
			$this->validateRelationType($method, $relation->first());
		} else {
			$this->validateRelationType($method, $relation);
		}
		
		$this->setRelation($method, $relation);
		
		return $relation;
	}
	
	/**
	 * Ensure that the relation either returns a Model or a Collection of models
	 *
	 * @param $method
	 * @param $relation
	 */
	protected function validateRelationType($method, $relation) : void
	{
		if (!$relation instanceof self) {
			throw new \LogicException(sprintf(
				'%s::%s must return a Prismoquent model instance.', static::class, $method
			));
		}
	}
	
	/**
	 * Get the value of an attribute using its mutator.
	 *
	 * @param  string $key
	 * @param  mixed $value
	 * @return mixed
	 */
	protected function mutateAttribute($key, $value)
	{
		$mutator_key = str_replace('.', '_', $key);
		return $this->{'get'.Str::studly($mutator_key).'Attribute'}($value);
	}
	
	/**
	 * Get an attribute array of all arrayable attributes.
	 *
	 * @return array
	 */
	protected function getArrayableAttributes()
	{
		return $this->getArrayableItems($this->getAttributes());
	}
	
	/**
	 * Get an attribute from the $attributes array.
	 *
	 * @param  string $key
	 * @return mixed
	 */
	protected function getAttributeFromArray($key)
	{
		if ($value = $this->getDocumentAttribute($key)) {
			return $value;
		}
		
		// Unlike Eloquent, where relationships are loaded via a column with
		// a separate name, Prismic links are likely to share the desired name.
		// We use the 'LinkResolver' suffix, and look for relationships first
		// to address this (otherwise getAttribute would almost always hit first.
		if ($related = $this->getRelationValue("{$key}LinkResolver")) {
			return $related;
		}
		
		$type = $this->getType();
		$value = $this->getDocumentField("data.{$type}.{$key}");
		
		// Handle both old DocumentLink objects and new SDK v5 link structure
		if ($value instanceof DocumentLink) {
			return $this->resolveLink($value);
		} elseif (is_object($value) && isset($value->type) && $value->type === 'Link.document') {
			return $this->resolveLinkV5($value);
		}
		
		return $value;
	}
	
	/**
	 * Get an array attribute or return an empty array if it is not set.
	 *
	 * @param  string $key
	 * @return array
	 */
	protected function getArrayAttributeByKey($key)
	{
		return $this->getAttributeFromArray($key);
	}
	
	protected function hasOne($path, $class_name = null)
	{
		$type = $this->getType();
		$link = $this->getDocumentField("data.{$type}.{$path}");
		
		if ($link instanceof DocumentLink) {
			return $this->resolveLink($link, $class_name);
		} elseif (is_object($link) && isset($link->type) && $link->type === 'Link.document') {
			return $this->resolveLinkV5($link, $class_name);
		}
		
		return null;
	}
	
	protected function hasMany($path, $class_name = null) : Collection
	{
		$segments = explode('.', $path);
		$link_key = array_pop($segments);
		$group_path = implode('.', $segments);
		$type = $this->getType();
		
		$group = $this->getDocumentField("data.{$type}.{$group_path}");
		
		// Handle legacy Group objects (SDK v4)
		if ($group instanceof Group) {
			return Collection::make($group->getArray())
				->map(function(GroupDoc $doc) use ($link_key, $class_name) {
					$link = $doc->get($link_key);
					return $link instanceof DocumentLink
						? $this->resolveLink($link, $class_name)
						: null;
				})
				->filter();
		}
		
		// Handle new Group structure (SDK v5)
		if (is_object($group) && isset($group->type) && $group->type === 'Group' && is_array($group->value)) {
			return Collection::make($group->value)
				->map(function($item) use ($link_key, $class_name) {
					$link = $item->$link_key ?? null;
					if ($link && is_object($link) && isset($link->type) && $link->type === 'Link.document') {
						return $this->resolveLinkV5($link, $class_name);
					}
					return null;
				})
				->filter();
		}
		
		return new Collection();
	}
	
	protected function resolveLink(DocumentLink $link, $class_name = null) : ?self
	{
		/** @var self $model_class */
		$model_class = $class_name ?? $this->inferLinkClassName($link->getType());
		
		return $model_class::find($link->getId());
	}

	protected function resolveLinkV5($linkFragment, $class_name = null) : ?self
	{
		if (!isset($linkFragment->value->document->id)) {
			return null;
		}

		$document = $linkFragment->value->document;
		$model_class = $class_name ?? $this->inferLinkClassName($document->type);
		
		return $model_class::find($document->id);
	}
	
	protected function inferLinkClassName($type) : string
	{
		$namespace = substr(static::class, 0, strrpos(static::class, '\\'));
		$class_name = Str::studly($type);
		
		return "{$namespace}\\{$class_name}";
	}
}
