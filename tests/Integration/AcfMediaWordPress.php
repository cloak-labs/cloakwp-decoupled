<?php

/** Run through wp eval-file against a local WordPress fixture site. No content writes. */
use CloakWP\VirtualFields\VirtualField;

if (!defined('REST_REQUEST')) define('REST_REQUEST', true);
$ids = get_posts(['post_type' => 'attachment', 'post_status' => 'inherit', 'posts_per_page' => 3,
    'fields' => 'ids', 'suppress_filters' => true]);
if (count($ids) < 2) throw new RuntimeException('At least two attachment fixtures are required.');
$fields = [];
foreach ($GLOBALS['wp_filter']['the_posts']->callbacks as $entries) {
    foreach ($entries as $entry) {
        if (!$entry['function'] instanceof Closure) continue;
        foreach ((new ReflectionFunction($entry['function']))->getStaticVariables()['virtualFields'] ?? [] as $field) {
            $fields[spl_object_id($field)] = $field;
        }
    }
}
$state = new ReflectionProperty(VirtualField::class, 'state');
$reset = static function () use ($fields, $state): void {
    acf_get_store('values')->reset();
    foreach ($fields as $field) { $state->setValue($field, []); $field->_resetRecursiveIterationCount(); }
};
$disable = static fn() => false;
$checks = 0;
$compare = static function ($value, $type, $format = 'array') use ($reset, $disable, &$checks, $fields): void {
    $field = acf_validate_field(['name' => 'cloakwp_media_test', 'key' => 'field_cloakwp_media_test',
        'type' => $type, 'return_format' => $format]);
    $states = static function () use ($fields): array {
        $result = [];
        foreach ($fields as $id => $field) $result[$id] = $field->getState();
        return $result;
    };
    $reset();
    add_filter('cloakwp/acf_media/optimize', $disable);
    try { $expected = acf_format_value($value, 0, $field); $expectedStates = $states(); }
    finally { remove_filter('cloakwp/acf_media/optimize', $disable); }
    $reset();
    $actual = acf_format_value($value, 0, $field);
    if (serialize($expected) !== serialize($actual) || $expectedStates !== $states()) {
        throw new RuntimeException('Media output or recursion state changed: ' . json_encode([$type, $format, $value]));
    }
    $checks++;
};
foreach (['array', 'id', 'url'] as $format) {
    foreach ([$ids[0], 0, false, 'invalid', 999999999] as $value) $compare($value, 'image', $format);
    foreach ([$ids, array_reverse($ids), [$ids[0], $ids[0], 999999999, $ids[1]], [], [999999999]] as $value) $compare($value, 'gallery', $format);
}
$typeCallback = static function ($value) {
    if (is_array($value)) return ['custom' => $value];
    return $value;
};
foreach (['image', 'gallery'] as $type) {
    add_filter('acf/format_value/type=' . $type, $typeCallback, 11);
    $compare($type === 'image' ? $ids[0] : $ids, $type);
    remove_filter('acf/format_value/type=' . $type, $typeCallback, 11);
}
$attachmentCallback = static fn($value) => array_replace($value, ['ID' => $ids[1]]);
add_filter('acf/load_attachment', $attachmentCallback);
$compare($ids[0], 'image');
$compare($ids, 'gallery');
remove_filter('acf/load_attachment', $attachmentCallback);
$_GET['relative_images'] = '1';
$compare($ids[0], 'image');
$compare($ids, 'gallery');
unset($_GET['relative_images']);
$queryCallback = static fn($args) => $args;
add_filter('acf/acf_get_posts/args', $queryCallback, 30);
$compare($ids, 'gallery');
remove_filter('acf/acf_get_posts/args', $queryCallback, 30);
$postCallback = static fn($posts) => $posts;
add_filter('the_posts', $postCallback, 30);
$compare($ids, 'gallery');
remove_filter('the_posts', $postCallback, 30);
$reset();
$throw = static function ($value, $id) use ($ids) {
    if (in_array((int) $id, $ids, true)) throw new RuntimeException('media test exception');
    return $value;
};
$before = array_keys($GLOBALS['wp_filter']['acf/acf_get_posts/args']->callbacks[PHP_INT_MAX] ?? []);
add_filter('get_post_metadata', $throw, 10, 2);
try {
    $field = acf_validate_field(['name' => 'media_exception', 'key' => 'field_media_exception',
        'type' => 'gallery', 'return_format' => 'array']);
    acf_format_value($ids, 0, $field);
    throw new RuntimeException('Expected metadata callback to throw.');
} catch (RuntimeException $e) {
    if ($e->getMessage() !== 'media test exception') throw $e;
} finally {
    remove_filter('get_post_metadata', $throw, 10);
}
if ($before !== array_keys($GLOBALS['wp_filter']['acf/acf_get_posts/args']->callbacks[PHP_INT_MAX] ?? [])) {
    throw new RuntimeException('Temporary query filter leaked after an exception.');
}
$checks++;
$customCalls = 0;
register_virtual_fields('attachment', [VirtualField::make('custom_media_test')->value(function ($post) use (&$customCalls) {
    $customCalls++;
    if (!isset($post->pathname)) throw new RuntimeException('An earlier virtual field was discarded before a custom callback.');
    return $post->pathname;
})]);
$compare($ids, 'gallery');
if ($customCalls !== count($ids) * 2) throw new RuntimeException('A custom virtual callback was skipped.');
if (property_exists(get_post($ids[0]), 'custom_media_test')) throw new RuntimeException('An enriched post leaked into the object cache.');
foreach (['image' => 20, 'gallery' => 99] as $type => $priority) {
    $hook = 'acf/format_value/type=' . $type;
    $installed = reset($GLOBALS['wp_filter'][$hook]->callbacks[10])['function'];
    $formatter = reset($GLOBALS['wp_filter'][$hook]->callbacks[$priority])['function'];
    remove_filter($hook, $installed, 10);
    $original = [acf_get_field_type($type), 'format_value'];
    add_filter($hook, $original, 10, 3);
    add_filter($hook, $typeCallback, 10);
    $before = $GLOBALS['wp_filter'][$hook]->callbacks;
    \CloakWP\Decoupled\Media\AcfMediaValues::wrap($type, $formatter, $priority);
    if ($before !== $GLOBALS['wp_filter'][$hook]->callbacks) throw new RuntimeException('A custom callback sharing the native priority was reordered.');
    $checks++;
}
echo "WordPress media output/state parity: $checks checks passed.\n";
