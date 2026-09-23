<?php

declare(strict_types=1);

namespace CloakWP\Decoupled\Tests\Media;

use CloakWP\Core\Media\LibraryFilter;
use CloakWP\Core\Media\LibraryFilters;
use CloakWP\Decoupled\Media\ImageLibraryQuery;
use CloakWP\Decoupled\Rest\Handlers\ListImageLibrary;
use CloakWP\Decoupled\Rest\Handlers\ListImageLibraryFilters;
use CloakWP\Decoupled\Tests\WpStubs;
use PHPUnit\Framework\TestCase;
use WP_REST_Request;

final class ImageLibraryQueryTest extends TestCase
{
  protected function setUp(): void
  {
    WpStubs::reset();
    LibraryFilters::reset();
    \CloakWP\Decoupled\Media\ProjectImageLookup::flushCache();
  }

  public function testBuildArgsAreImagesOnlyWithoutParentConstraint(): void
  {
    $query = new ImageLibraryQuery(new FakeImageFormatter());
    $args = $query->buildArgs(2, 20, [], []);

    $this->assertSame('attachment', $args['post_type']);
    $this->assertSame('inherit', $args['post_status']);
    $this->assertSame('image', $args['post_mime_type']);
    $this->assertSame(20, $args['posts_per_page']);
    $this->assertSame(2, $args['paged']);
    $this->assertArrayNotHasKey('post_parent', $args);
    $this->assertSame('ids', $args['fields']);
  }

  public function testBuildArgsKeepsPostObjectsWhenIncludingProject(): void
  {
    $query = new ImageLibraryQuery(new FakeImageFormatter());
    $args = $query->buildArgs(1, 20, [], [], true);

    $this->assertArrayNotHasKey('fields', $args);
  }

  public function testApplyIncludeAndExcludeViaLibraryFilters(): void
  {
    LibraryFilter::make('media_category')
      ->grid(LibraryFilter::GRID_CUSTOM)
      ->supportsExclude(true)
      ->query(static function (array $args, string $value): array {
        $args['applied'][] = $value;

        return $args;
      })
      ->register();

    $query = new ImageLibraryQuery(new FakeImageFormatter());
    $args = $query->buildArgs(1, 20, ['media_category' => '12,15'], ['media_category' => '20']);

    $this->assertSame(['12,15', 'not:20'], $args['applied']);
  }

  public function testRunFormatsItemsAndCapsPerPage(): void
  {
    $formatter = new FakeImageFormatter();
    $query = new ImageLibraryQuery($formatter, static function (array $args): object {
      return new FakeWpQuery(
        posts: [41, 42],
        found: 2,
        pages: 1,
        args: $args,
      );
    });

    $result = $query->run(1, 200, [], []);

    $this->assertSame(50, $result['perPage']);
    $this->assertSame(2, $result['total']);
    $this->assertCount(2, $result['items']);
    $this->assertSame(41, $result['items'][0]['id']);
    $this->assertSame('https://example.test/41.jpg', $result['items'][0]['full']['src']);
  }

  public function testFiltersFromRequestSplitIncludeAndExclude(): void
  {
    LibraryFilter::make('media_category')
      ->grid(LibraryFilter::GRID_CUSTOM)
      ->supportsExclude(true)
      ->query(static fn(array $args): array => $args)
      ->register();

    $request = new WP_REST_Request();
    $request->set_param('media_category', '12,15');
    $request->set_param('media_category_not', '20');
    $request->set_param('page', '3');
    $request->set_param('per_page', '10');

    $filters = ImageLibraryQuery::filtersFromRequest($request);

    $this->assertSame(['media_category' => '12,15'], $filters['include']);
    $this->assertSame(['media_category' => '20'], $filters['exclude']);
    $this->assertSame(3, ImageLibraryQuery::pageFromRequest($request));
    $this->assertSame(10, ImageLibraryQuery::perPageFromRequest($request));
  }

  public function testListHandlerSetsPaginationHeaders(): void
  {
    $query = new ImageLibraryQuery(new FakeImageFormatter(), static function (): object {
      return new FakeWpQuery(posts: [7], found: 21, pages: 3);
    });

    $response = (new ListImageLibrary($query))(new WP_REST_Request());

    $this->assertSame(21, $response->data['total']);
    $this->assertSame('21', $response->headers['X-WP-Total']);
    $this->assertSame('3', $response->headers['X-WP-TotalPages']);
  }

  public function testFiltersEndpointReturnsPublicSchema(): void
  {
    LibraryFilter::make('orientation')
      ->options(['portrait' => 'Portrait'])
      ->metaKey('_media_orientation')
      ->register();

    $response = (new ListImageLibraryFilters())();

    $this->assertSame('orientation', $response->data[0]['id']);
    $this->assertSame('Portrait', $response->data[0]['options'][0]['label']);
  }

  public function testIncludeProjectFromRequest(): void
  {
    $on = new WP_REST_Request();
    $on->set_param('include_project', '1');
    $this->assertTrue(ImageLibraryQuery::includeProjectFromRequest($on));

    $off = new WP_REST_Request();
    $this->assertFalse(ImageLibraryQuery::includeProjectFromRequest($off));
  }

  public function testRunAttachesRelatedOnlyWhenIncludeProjectIsOn(): void
  {
    \CloakWP\Decoupled\Media\ProjectImageLookup::flushCache();
    WpStubs::$posts[50] = (object) [
      'ID' => 50,
      'post_type' => 'project',
      'post_status' => 'publish',
      'post_title' => 'Baycrest',
      'post_name' => 'baycrest',
      'post_content' => '',
    ];
    WpStubs::$postMeta[50]['after_images'] = ['41'];
    WpStubs::$postMeta[50]['subtitle'] = 'A compact yard';

    $factory = static function (array $args): object {
      return new FakeWpQuery(
        posts: [(object) ['ID' => 41, 'post_parent' => 0]],
        found: 1,
        pages: 1,
        args: $args,
      );
    };

    $without = (new ImageLibraryQuery(new FakeImageFormatter(), $factory))->run(1, 20, [], [], false);
    $this->assertArrayNotHasKey('related', $without['items'][0]);

    $with = (new ImageLibraryQuery(new FakeImageFormatter(), $factory))->run(1, 20, [], [], true);
    $this->assertSame('Baycrest', $with['items'][0]['related']['title']);
    $this->assertSame('A compact yard', $with['items'][0]['related']['subtitle']);
    $this->assertSame('/portfolio/baycrest/', $with['items'][0]['related']['href']);
  }

  public function testListHandlerReadsIncludeProject(): void
  {
    $captured = [];
    $query = new ImageLibraryQuery(new FakeImageFormatter(), static function (array $args) use (&$captured): object {
      $captured = $args;

      return new FakeWpQuery(posts: [7], found: 1, pages: 1, args: $args);
    });
    $request = new WP_REST_Request();
    $request->set_param('include_project', '1');

    $response = (new ListImageLibrary($query))($request);

    $this->assertArrayNotHasKey('fields', $captured);
    $this->assertSame(7, $response->data['items'][0]['id']);
  }

  public function testScatterByProjectReordersAcrossPagesWithoutReformattingTheWholeLibrary(): void
  {
    \CloakWP\Decoupled\Media\ProjectImageLookup::flushCache();
    WpStubs::$posts[10] = (object) [
      'ID' => 10,
      'post_type' => 'project',
      'post_status' => 'publish',
      'post_title' => 'Oak',
      'post_name' => 'oak',
      'post_content' => '',
    ];
    WpStubs::$posts[20] = (object) [
      'ID' => 20,
      'post_type' => 'project',
      'post_status' => 'publish',
      'post_title' => 'Pine',
      'post_name' => 'pine',
      'post_content' => '',
    ];
    WpStubs::$postMeta[10]['after_images'] = ['1', '2', '3'];
    WpStubs::$postMeta[20]['after_images'] = ['4', '5'];

    $formatted = new \stdClass();
    $formatted->ids = [];
    $captured = [];
    $posts = [];
    foreach ([1, 2, 3, 4, 5, 6] as $id) {
      $posts[] = (object) ['ID' => $id, 'post_parent' => 0];
    }
    $formatter = new class($formatted) implements \CloakWP\Decoupled\Contracts\ImageFormatter {
      public function __construct(private \stdClass $formatted)
      {
      }

      public function format(mixed $imageId): mixed
      {
        $id = (int) $imageId;
        $this->formatted->ids[] = $id;

        return ['full' => ['src' => "https://example.test/{$id}.jpg"]];
      }
    };
    $factory = static function (array $args) use (&$captured, $posts): object {
      $captured = $args;

      return new FakeWpQuery(posts: $posts, found: 0, pages: 0, args: $args);
    };
    $query = new ImageLibraryQuery($formatter, $factory);

    $page1 = $query->run(1, 3, [], [], true, ImageLibraryQuery::SCATTER_PROJECT);
    $page1Ids = array_column($page1['items'], 'id');
    $formattedOnPage1 = $formatted->ids;
    $page2 = $query->run(2, 3, [], [], false, ImageLibraryQuery::SCATTER_PROJECT);

    $this->assertSame(-1, $captured['posts_per_page']);
    $this->assertSame(['date' => 'DESC', 'ID' => 'DESC'], $captured['orderby']);
    $this->assertSame([1, 2, 4], $page1Ids);
    $this->assertSame('Oak', $page1['items'][0]['related']['title']);
    $this->assertSame([1, 2, 4], $formattedOnPage1);
    $this->assertSame([5, 6, 3], array_column($page2['items'], 'id'));
    $this->assertArrayNotHasKey('related', $page2['items'][0]);
    $this->assertSame(6, $page1['total']);
    $this->assertSame(2, $page1['totalPages']);
  }

  public function testScatterGroupsDraftProjectImagesWithoutPublicLinks(): void
  {
    \CloakWP\Decoupled\Media\ProjectImageLookup::flushCache();
    WpStubs::$posts[30] = (object) [
      'ID' => 30,
      'post_type' => 'project',
      'post_status' => 'draft',
      'post_title' => 'Whiterock',
      'post_name' => 'whiterock',
      'post_content' => '',
    ];
    WpStubs::$posts[40] = (object) [
      'ID' => 40,
      'post_type' => 'project',
      'post_status' => 'publish',
      'post_title' => 'Oak',
      'post_name' => 'oak',
      'post_content' => '',
    ];
    WpStubs::$postMeta[30]['after_images'] = ['1', '2', '3'];
    WpStubs::$postMeta[40]['after_images'] = ['4', '5'];

    $posts = [];
    foreach ([1, 2, 3, 4, 5, 6] as $id) {
      $posts[] = (object) ['ID' => $id, 'post_parent' => 0];
    }
    $posts[] = (object) ['ID' => 8, 'post_parent' => 30];
    $query = new ImageLibraryQuery(new FakeImageFormatter(), static function () use ($posts): object {
      return new FakeWpQuery(posts: $posts, found: count($posts), pages: 1);
    });

    $result = $query->run(1, 20, [], [], true, ImageLibraryQuery::SCATTER_PROJECT);
    $byId = [];
    foreach ($result['items'] as $item) {
      $byId[(int) $item['id']] = $item;
    }

    $this->assertSame([1, 2, 4, 5, 6, 3, 8], array_column($result['items'], 'id'));
    $this->assertArrayNotHasKey('related', $byId[1]);
    $this->assertArrayNotHasKey('related', $byId[8]);
    $this->assertSame('Oak', $byId[4]['related']['title']);
  }

  public function testScatterNoneKeepsThePagedQuery(): void
  {
    $captured = [];
    $query = new ImageLibraryQuery(new FakeImageFormatter(), static function (array $args) use (&$captured): object {
      $captured = $args;

      return new FakeWpQuery(posts: [1, 2], found: 2, pages: 1, args: $args);
    });

    $result = $query->run(1, 20, [], [], false, 'nope');

    $this->assertSame(20, $captured['posts_per_page']);
    $this->assertSame(1, $captured['paged']);
    $this->assertSame([1, 2], array_column($result['items'], 'id'));
  }

  public function testListHandlerReadsScatter(): void
  {
    $captured = [];
    $query = new ImageLibraryQuery(new FakeImageFormatter(), static function (array $args) use (&$captured): object {
      $captured = $args;

      return new FakeWpQuery(posts: [7], found: 1, pages: 1, args: $args);
    });
    $request = new WP_REST_Request();
    $request->set_param('scatter', 'project');

    (new ListImageLibrary($query))($request);

    $this->assertSame(-1, $captured['posts_per_page']);
  }
}

final class FakeImageFormatter implements \CloakWP\Decoupled\Contracts\ImageFormatter
{
  public function format(mixed $imageId): mixed
  {
    $id = (int) $imageId;

    return [
      'full' => ['src' => "https://example.test/{$id}.jpg", 'width' => 800, 'height' => 600],
      'alt' => '',
    ];
  }
}

final class FakeWpQuery
{
  /** @param list<int> $posts */
  public function __construct(
    public array $posts = [],
    public int $found_posts = 0,
    public int $max_num_pages = 0,
    public array $args = [],
    int $found = 0,
    int $pages = 0,
  ) {
    if ($found > 0) {
      $this->found_posts = $found;
    }
    if ($pages > 0) {
      $this->max_num_pages = $pages;
    }
  }
}
