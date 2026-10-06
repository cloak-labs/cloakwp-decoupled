# WordPress integration checks

These checks use real WordPress/ACF APIs and existing local content. They do not
write content or options. Run from a bootable WordPress installation with this
package loaded and at least two attachments:

```sh
wp eval-file /path/to/cloakwp-decoupled/tests/Integration/PostTermsWordPress.php --url=https://wp.localhost/hyland/
wp eval-file /path/to/cloakwp-decoupled/tests/Integration/AcfMediaWordPress.php --url=https://wp.localhost/hyland/
wp eval-file /path/to/cloakwp-decoupled/tests/Integration/AcfIndexWordPress.php --url=https://wp.localhost/hyland/
```

The term check compares native queries with primed-cache reads, custom filters,
taxonomy ordering overrides, invalid taxonomies, and site switches. The media
check compares optimized and original pipelines, including recursion state,
missing/empty attachments, duplicate gallery IDs, return formats, relative URLs,
custom callbacks, cache isolation, and exception cleanup.

The field-index check compares native WordPress matching and ACF loaders,
including groups, repeaters, flexible content, clones, custom load hooks,
same-size registry edits, aliases, retained handles, and site switches. Temporary
field definitions exist only in memory and are restored after the check.

REST requests index ACF's native local-field registry before schema construction
and value extraction. No field or value hooks are skipped, and custom registries
retain their own implementations. Return false from `cloakwp/acf/index_fields`
to retain the previous registry behavior throughout REST setup; BlockParser's
existing scoped index is independently controlled by
`cloakwp/block_parser/index_acf_fields`.

ACF media optimization is confined to REST requests with the standard ACF and
CloakWP formatting callbacks. Extra media/query callbacks retain the original
pipeline. To opt out explicitly, return false from `cloakwp/acf_media/optimize`.
Attachments with ACF metadata and non-opted-in virtual fields retain their normal
enrichment. Post-term queries retain their original pipeline when custom query
filters or taxonomy arguments are present.
