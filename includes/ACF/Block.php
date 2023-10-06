<?php

namespace CloakWP\ACF;

use CloakWP\Utils;
use ErrorException;
use Extended\ACF\Fields\Accordion;
use Extended\ACF\Key;
use Extended\ACF\Location;
use InvalidArgumentException;

class Block
{
  protected array $settings;
  protected string $blockJson;
  protected array $fields;
  /**
   * @var callable|null $apiResponseCallback
   */
  protected $apiResponseCallback;

  public function __construct(string $blockJsonDir)
  {
    $this->blockJson = $blockJsonDir;
  }

  public static function make(string $blockJsonDir): static
  {
    return new static($blockJsonDir);
  }

  public function fields(array $fields): static
  {
    $this->fields = $fields;

    return $this;
  }

  public function apiResponse(callable $filterCallback): static
  {
    $this->apiResponseCallback = $filterCallback;

    return $this;
  }

  protected function register(string $blockJsonPath)
  {
    register_block_type(
      $blockJsonPath,
      array(
        'render_callback' => array($this, 'renderIframePreview') // idea here is to remove/abstract the need for dev to specify { ... "renderCallback": "function_name" ... } in block.json
      )
    );
  }

  public static function renderIframePreview ($block, $content, $is_preview, $post_id, $wp_block, $context) {
    include(Utils::cloakwp_plugin_path() . '/block-preview.php');
  }

  public static function wrapWithAccordion(string $accordionLabel, array $fields)
  {
    if ($fields && is_array($fields)) {
      return [
        Accordion::make($accordionLabel)
          ->open()
          ->multiExpand(), // Allow accordion to remain open when other accordions are opened.
        ...$fields,
        Accordion::make('Endpoint')
          ->endpoint()
          ->multiExpand(),
      ];
    }

    return [];
  }

  public function get()
  {
    $blockJsonPath = $this->blockJson;
    if (file_exists($blockJsonPath)) {
      // register block
      $this->register($blockJsonPath);

      // parse block.json contents
      $blockJsonContent = file_get_contents($blockJsonPath);
      $blockConfig = json_decode($blockJsonContent, true);

      if ($blockConfig !== null) {
        // $blockConfig now contains the parsed JSON data from block.json (access specific properties like $blockConfig['name'], $blockConfig['title'], etc.)
        $name = $blockConfig['name'];
        $title = $blockConfig['title'];

        // set this block's name to the name defined in its block.json file
        $this->settings['name'] = $name;

        // use name from block.json to set $block['location'] (required by Extended ACF)
        $this->settings['location'] = [Location::where('block', $name)];

        // auto-generate title property (required by Extended ACF) based on block.json's 'title'
        $this->settings['title'] = $title;

        $this->settings['fields'] = $this->wrapWithAccordion("$title - Fields", $this->fields);

        // this 'key' gets used in the 'Blocks' field class to prevent a block from being nested within itself 
        $this->settings['key'] = Key::sanitize('block_' . $title);

        if ($this->apiResponseCallback) {
          add_filter("cloakwp/rest/blocks/response_format/name=$name", $this->apiResponseCallback, 10, 2);
        }
      } else {
        // Handle JSON parsing error
        throw new ErrorException('Error parsing block.json file.');
      }
    } else {
      // Handle file not found error
      throw new InvalidArgumentException("Invalid block.json file path provided to Block::make() -- file not found.");
    }

    return $this->settings;
  }
}
