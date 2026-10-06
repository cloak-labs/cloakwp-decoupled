<?php

/** Run through wp eval-file against a local WordPress fixture site. No content writes. */
use CloakWP\Decoupled\Support\PostTerms;

$ids = get_posts(['post_type' => ['post', 'page', 'project', 'attachment'], 'posts_per_page' => 80,
    'post_status' => ['publish', 'inherit'], 'fields' => 'ids', 'suppress_filters' => true]);
_prime_post_caches($ids, true, true);
$checks = 0;
$compare = static function ($id, $taxonomy) use (&$checks): void {
    $expected = wp_get_post_terms($id, $taxonomy);
    $actual = PostTerms::get($id, $taxonomy);
    if (serialize($expected) !== serialize($actual)) {
        throw new RuntimeException("Term parity failed for $id/$taxonomy");
    }
    $checks++;
};
$fixture = null;
foreach ($ids as $id) {
    foreach (get_object_taxonomies(get_post_type($id)) as $taxonomy) {
        $compare($id, $taxonomy);
        $fixture ??= [$id, $taxonomy];
    }
}
if (!$fixture) throw new RuntimeException('No taxonomy fixtures found.');
[$id, $taxonomy] = $fixture;
$custom = static fn($terms) => array_reverse($terms);
foreach (['get_object_terms', 'wp_get_object_terms', 'get_terms'] as $hook) {
    add_filter($hook, $custom, 99);
    $compare($id, $taxonomy);
    remove_filter($hook, $custom, 99);
}
$definition = get_taxonomy($taxonomy);
$previous = $definition->args;
$definition->args = ['orderby' => 'term_id', 'order' => 'DESC'];
$compare($id, $taxonomy);
$definition->args = $previous;
$invalid = PostTerms::get($id, 'cloakwp_nonexistent_test_taxonomy');
if (!is_wp_error($invalid)) throw new RuntimeException('Invalid taxonomy handling changed.');
if (is_multisite()) {
    $site = get_sites(['number' => 1, 'site__not_in' => [get_current_blog_id()]])[0] ?? null;
    if ($site) {
        switch_to_blog($site->blog_id);
        try { $compare($id, $taxonomy); }
        finally { restore_current_blog(); }
        $compare($id, $taxonomy);
    }
}
echo "WordPress term parity: $checks checks passed.\n";
