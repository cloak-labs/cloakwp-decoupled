<?php

/**
 * Provide a admin area view for the plugin
 *
 * This file is used to markup the admin-facing aspects of the plugin.
 *
 * @link       https://https://github.com/cloak-labs
 * @since      0.6.0
 *
 * @package    CloakWP
 * @subpackage CloakWP/admin/partials
 */

function cloakwp_echo_variable($var)
{
  if (isset($var)) {
    if (gettype($var) === 'boolean') {
      if ($var === TRUE) {
        echo "<span>TRUE</span>";
      }
      if ($var === FALSE) {
        echo "<span>FALSE</span>";
      }
    }
    if (gettype($var) === 'string') {
      if (strlen($var) > 0) {
        echo "<span>'" . $var . "'</span>";
      }
      if (strlen($var) === 0) {
        echo "<span>''</span>";
      }
    }
    echo "<span> (" . gettype($var) . ")</span>";
  } else {
    echo "<span>Unset</span>";
  }
}

function cloakwp_settings_row($name)
{
  if (defined($name)) {
    $var = constant($name);
  } else {
    $var = NULL;
  }
?>
  <tr>
    <th scope="row">
      <?php echo $name ?>
    </th>
    <td><?php cloakwp_echo_variable($var); ?></td>
  </tr>
<?php
}
?>
<table class="form-table" role="presentation">
  <tbody>
    <?php
    cloakwp_settings_row('CLOAKWP_FRONTEND_URL');
    cloakwp_settings_row('CLOAKWP_PREVIEW_SECRET');
    cloakwp_settings_row('CLOAKWP_ENABLE_DEV_MODE');
    cloakwp_settings_row('CLOAKWP_ENABLE_ISR');
    cloakwp_settings_row('CLOAKWP_OVERRIDE_VIEW_POST_LINK');
    cloakwp_settings_row('CLOAKWP_OVERRIDE_PREVIEW_POST_LINK');
    cloakwp_settings_row('CLOAKWP_YOAST_USE_FRONTEND_URL');
    cloakwp_settings_row('CLOAKWP_ENABLE_FAVICON');
    cloakwp_settings_row('CLOAKWP_JWT_NO_EXPIRY');
    cloakwp_settings_row('CLOAKWP_ENABLE_PREVIEW_POST');
    cloakwp_settings_row('CLOAKWP_API_BASE_PATH');
    ?>
  </tbody>
</table>
<!-- This file should primarily consist of HTML with a little bit of PHP. -->