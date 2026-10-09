<?php

declare(strict_types=1);

// wp eval-file tests/Integration/AcfFieldValuesWordPress.php --use-include --url=<site>
use CloakWP\Decoupled\Support\AcfFieldValues;
use Extended\ACF\Fields\{Group, Number, Text};

$checks = 0;
$same = static function ($expected, $actual, string $message) use (&$checks): void {
  if ($expected !== $actual) {
    throw new RuntimeException($message . ': ' . var_export([$expected, $actual], true));
  }
  $checks++;
};
$settings = register_extended_field_group([
  'title' => 'Projected ACF values', 'key' => 'projected_acf_values',
  'location' => [\Extended\ACF\Location::where('block', 'acf/projected-acf-values')],
  'fields' => [Text::make('Title'), Number::make('Count'), Group::make('Details')->fields([Text::make('Label')]), Text::make('Discard')],
]);
[$title, $count, $details, $discard] = $settings['fields'];
$id = 'block_projected_acf_values';
acf_setup_meta([
  'title' => 'Hello', '_title' => $title['key'], 'count' => '0', '_count' => $count['key'],
  'details' => '', '_details' => $details['key'],
  'details_label' => 'Nested', '_details_label' => $details['sub_fields'][0]['key'],
  'discard' => 'Expensive', '_discard' => $discard['key'],
], $id);
$full = get_fields($id);
$expected = $full;
unset($expected['discard']);
acf_get_store('values')->reset();
$discardedCalls = 0;
add_filter('acf/format_value/key=' . $discard['key'], static function ($value) use (&$discardedCalls) {
  $discardedCalls++;
  return $value;
});
$same($expected, AcfFieldValues::except($id, ['discard']), 'Retained values differ from get_fields');
$same(0, $discardedCalls, 'An excluded value was formatted');
$same([], AcfFieldValues::except($id, ['title', 'count', 'details', 'discard']), 'All-excluded result changed');
$same($full, AcfFieldValues::except($id, []), 'An empty exclusion list changed values');
$same(1, $discardedCalls, 'Normal formatting callbacks did not run');
acf_get_store('values')->reset();
$discardedCalls = 0;
$same($expected, AcfFieldValues::only($id, ['title', 'count', 'details', 'missing']), 'Included values differ from get_fields');
$same(0, $discardedCalls, 'An unselected value was formatted');
$same([], AcfFieldValues::only($id, []), 'An empty inclusion list read values');
$same([], AcfFieldValues::only($id, ['missing']), 'An unknown name invented a field value');
echo "Projected ACF values WordPress: $checks checks passed.\n";
