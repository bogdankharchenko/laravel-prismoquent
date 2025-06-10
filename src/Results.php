<?php

namespace Galahad\Prismoquent;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Prismic\Document;
use Prismic\Response;

/**
 * @method \Galahad\Prismoquent\Model|null first(callable $callback = null, $default = null)
 * @method \Galahad\Prismoquent\Model[] all()
 */
class Results extends LengthAwarePaginator
{
	/**
	 * @var object
	 */
	public $response;
	
	/**
	 * Constructor
	 *
	 * @param \Galahad\Prismoquent\Model $model
	 * @param object $response
	 */
	public function __construct(Model $model, $response)
	{
		// Handle both Response objects and stdClass objects from Prismic API v5
		if ($response instanceof Response) {
			$results = $response->getResults();
			$total = $response->getTotalResultsSize();
			$perPage = $response->getResultsPerPage();
			$currentPage = $response->getPage();
		} else {
			// stdClass response from Prismic API v5
			$results = $response->results ?? [];
			$total = $response->total_results_size ?? 0;
			$perPage = $response->results_per_page ?? 20;
			$currentPage = $response->page ?? 1;
		}
		
		$items = Collection::make($results)
			->map(function($document) use ($model) {
				return $model->newInstance($document);
			});
		
		parent::__construct(
			$items,
			$total,
			$perPage,
			$currentPage
		);
		
		$this->response = $response;
	}
}
