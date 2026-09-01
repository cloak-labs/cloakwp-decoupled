<?php

declare(strict_types=1);

namespace CloakWP\Decoupled\Rest\Handlers;

use CloakWP\Decoupled\Media\ImageLibraryQuery;

final class ListImageLibrary
{
  public function __construct(
    private readonly ImageLibraryQuery $query,
  ) {
  }

  public function __invoke(mixed $request): mixed
  {
    $params = is_object($request) && method_exists($request, 'get_query_params')
      ? $request->get_query_params()
      : [];
    if (!is_array($params)) {
      $params = [];
    }

    $filters = is_object($request)
      ? ImageLibraryQuery::filtersFromRequest($request)
      : ['include' => [], 'exclude' => []];

    $page = is_object($request)
      ? ImageLibraryQuery::pageFromRequest($request, $params)
      : 1;
    $perPage = is_object($request)
      ? ImageLibraryQuery::perPageFromRequest($request, $params)
      : ImageLibraryQuery::DEFAULT_PER_PAGE;

    $result = $this->query->run($page, $perPage, $filters['include'], $filters['exclude']);
    $response = rest_ensure_response($result);

    if (is_object($response) && method_exists($response, 'header')) {
      $response->header('X-WP-Total', (string) $result['total']);
      $response->header('X-WP-TotalPages', (string) $result['totalPages']);
    }

    return $response;
  }
}
