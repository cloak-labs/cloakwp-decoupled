<?php

/** Run with wp eval-file against real WordPress/ACF. No database writes. */
use CloakWP\Decoupled\Providers\AcfPerformanceProvider;
use CloakWP\Decoupled\Support\IndexedAcfFieldStore;

if (!class_exists('ACF_Data', false)) throw new RuntimeException('ACF is required.');
$checks = 0;
$same = static function ($expected, $actual, string $message) use (&$checks): void {
    if ($expected !== $actual) throw new RuntimeException($message);
    $checks++;
};
$native = new ACF_Data([
    'field_one' => ['name' => 'one', 'parent' => 'group_example'],
    7 => ['name' => 'two', 'parent' => 'field_nested'],
    'field_three' => ['name' => 'three', 'parent' => 'field_nested'],
    'orphan' => ['name' => 'orphan'],
    'numeric' => ['name' => 'numeric', 'parent' => 12],
]);
$indexed = new IndexedAcfFieldStore($native);
$queries = [
    [['parent' => 'group_example'], 'AND'], [['parent' => 'field_nested'], 'AND'],
    [['parent' => 'missing'], 'AND'], [['parent' => '12'], 'AND'],
    [['parent' => '012'], 'AND'], [['name' => 'three'], 'AND'],
    [['parent' => 'field_nested', 'name' => 'two'], 'OR'],
    [['parent' => 'field_nested'], 'NOT'], [[], 'AND'],
];
$compare = static function () use ($native, $indexed, $queries, $same): void {
    foreach ($queries as [$args, $operator]) {
        $same($native->query($args, $operator), $indexed->query($args, $operator), 'Native matching, keys or order changed.');
    }
};
$compare();
$native->set('field_three', ['name' => 'changed', 'parent' => 'group_example']);
$compare(); // Same-size change through the original handle.
$indexed->set('added', ['name' => 'added', 'parent' => 'field_nested'])->alias('added', 'alias');
$same($native->get('alias'), $indexed->get('alias'), 'Aliases stopped sharing state.');
$compare();
$native->remove('added');
$compare();
$native->set('unexpected', ['parent' => true]);
$compare(); // WordPress uses loose comparison for unusual values.
$native->remove('unexpected');
$native->multisite = true;
$native->switch_site(999999, get_current_blog_id());
$compare();
$indexed->set('other_site', ['parent' => 'field_nested']);
$native->switch_site(get_current_blog_id(), 999999);
$compare();
$native->reset();
$compare();
$referenced = ['parent' => 'group_example'];
$native->data['reference'] =& $referenced;
$compare();
$referenced['parent'] = 'field_nested';
$compare(); // References must not hide same-size edits from invalidation.
$native->reset();
$parent = null;
$native->data['parent_reference'] = ['parent' => &$parent];
$compare();
$parent = 'field_nested';
$compare();

$original = $GLOBALS['acf_stores']['local-fields'];
$originalData = $original->data;
$originalAliases = $original->aliases;
$groups = acf_get_local_store('groups');
$groupData = $groups->data;
$groupAliases = $groups->aliases;
$loaded = acf_get_store('fields');
$loadedData = $loaded->data;
$loadedAliases = $loaded->aliases;
$disabled = static fn() => false;
try {
    // Register temporary definitions in memory, including every nested loader.
    acf_add_local_field_group([
        'key' => 'group_cloakwp_index_parity', 'title' => 'Index parity',
        'fields' => [
            ['key' => 'field_index_group', 'name' => 'index_group', 'type' => 'group', 'sub_fields' => [
                ['key' => 'field_index_text', 'name' => 'index_text', 'type' => 'text'],
                ['key' => 'field_index_repeater', 'name' => 'index_repeater', 'type' => 'repeater', 'sub_fields' => [
                    ['key' => 'field_index_row', 'name' => 'index_row', 'type' => 'text'],
                ]],
            ]],
            ['key' => 'field_index_flexible', 'name' => 'index_flexible', 'type' => 'flexible_content', 'layouts' => [
                ['key' => 'layout_index_one', 'name' => 'one', 'label' => 'One', 'sub_fields' => [
                    ['key' => 'field_index_layout_text', 'name' => 'text', 'type' => 'text'],
                ]],
                ['key' => 'layout_index_two', 'name' => 'two', 'label' => 'Two', 'sub_fields' => [
                    ['key' => 'field_index_layout_group', 'name' => 'group', 'type' => 'group', 'sub_fields' => [
                        ['key' => 'field_index_layout_nested', 'name' => 'nested', 'type' => 'text'],
                    ]],
                ]],
            ]],
            ['key' => 'field_index_clone', 'name' => 'index_clone', 'type' => 'clone', 'clone' => ['field_index_group']],
        ],
    ]);
    add_filter('cloakwp/acf/index_fields', $disabled);
    AcfPerformanceProvider::indexFields();
    $same($original, $GLOBALS['acf_stores']['local-fields'], 'Opt-out ignored.');
    remove_filter('cloakwp/acf/index_fields', $disabled);
    $custom = new class extends ACF_Data {};
    $GLOBALS['acf_stores']['local-fields'] = $custom;
    AcfPerformanceProvider::indexFields();
    $same($custom, $GLOBALS['acf_stores']['local-fields'], 'Custom registry replaced.');
    unset($GLOBALS['acf_stores']['local-fields']);
    AcfPerformanceProvider::indexFields();
    $same(false, isset($GLOBALS['acf_stores']['local-fields']), 'Missing registry replaced.');
    $GLOBALS['acf_stores']['local-fields'] = $original;
    AcfPerformanceProvider::indexFields();
    $optimized = $GLOBALS['acf_stores']['local-fields'];
    if (!$optimized instanceof IndexedAcfFieldStore) throw new RuntimeException('Native registry not indexed.');
    AcfPerformanceProvider::indexFields();
    $same($optimized, $GLOBALS['acf_stores']['local-fields'], 'Repeated installation wrapped the index again.');

    $parents = array_values(array_unique(array_column($original->data, 'parent')));
    foreach (array_slice($parents, 0, 40) as $parent) {
        $same($original->query(['parent' => $parent]), $optimized->query(['parent' => $parent]), 'Real registry children changed.');
    }
    $samples = ['text' => 'field_index_text', 'group' => 'field_index_group',
        'repeater' => 'field_index_repeater', 'flexible_content' => 'field_index_flexible', 'clone' => 'field_index_clone'];
    $trace = [];
    $observe = static function ($field) use (&$trace) {
        $trace[] = [$field['key'], $field['type']];
        $field['label'] = ($field['label'] ?? '') . ' [parity]';
        return $field;
    };
    add_filter('acf/load_field', $observe, 20);
    try {
        foreach ($samples as $type => $key) {
            $loaded->reset(); $trace = [];
            $GLOBALS['acf_stores']['local-fields'] = $original;
            $expected = acf_get_field($key); $expectedTrace = $trace;
            $loaded->reset(); $trace = [];
            $GLOBALS['acf_stores']['local-fields'] = $optimized;
            $actual = acf_get_field($key);
            $same($expected, $actual, "Loaded $type field changed.");
            $same($expectedTrace, $trace, "Load hooks changed for $type.");
        }
    } finally {
        remove_filter('acf/load_field', $observe, 20);
    }
} finally {
    remove_filter('cloakwp/acf/index_fields', $disabled);
    $GLOBALS['acf_stores']['local-fields'] = $original;
    $original->data = $originalData;
    $original->aliases = $originalAliases;
    $groups->data = $groupData;
    $groups->aliases = $groupAliases;
    $loaded->data = $loadedData;
    $loaded->aliases = $loadedAliases;
}
echo "WordPress ACF registry parity: $checks checks passed.\n";
