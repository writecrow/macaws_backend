<?php

$lando_info = json_decode(getenv('LANDO_INFO'), TRUE);

$databases['default']['default'] = array(
  'driver' => 'mysql',
  'database' => $lando_info['database']['creds']['database'],
  'username' => $lando_info['database']['creds']['user'],
  'password' => $lando_info['database']['creds']['password'],
  'host' => $lando_info['database']['internal_connection']['host'],
  'prefix' => '',
);

$databases['default']['default'] = array(
  'database' => 'drupal8',
  'username' => 'drupal8',
  'password' => 'drupal8',
  'prefix' => '',
  'host' => 'database',
  'port' => '3306',
  'namespace' => 'Drupal\\Core\\Database\\Driver\\mysql',
  'driver' => 'mysql',
);

$settings['container_yamls'][] = $app_root . '/' . $site_path . '/development.services.yml';
$config['simple_oauth.settings']['public_key'] = "public.key";
$config['simple_oauth.settings']['private_key'] = "private.key";

# $config['reroute_email.settings']['enable'] = TRUE;

$settings['corpus_environment'] = 'local';
$settings['corpus_environment_config'] = [
  'local' => 'https://macaws-api.lndo.site',
  'production' => 'https://macaws.corporaproject.org',
];
# $settings['corpus_bcc_email'] = 'mfullmer@gmail.com';

$config['recaptcha.settings']['site_key'] = '';
$config['recaptcha.settings']['secret_key'] = '';

$config['smtp.settings'] = [
  'smtp_on' => TRUE,
  'smtp_host' => 'smtp.gmail.com',
  'smtp_hostbackup' => '',
  'smtp_port' => '587',
  'smtp_protocol' => 'tls',
  'smtp_autotls' => TRUE,
  'smtp_timeout' => '30',
  'smtp_username' => '',
  'smtp_password' => '',
  'smtp_from' => '',
  'smtp_fromname' => 'Crow, the Corpus & Repository of Writing',
  'smtp_client_hostname' => '',
  'smtp_client_helo' => '',
  'smtp_allowhtml' => '0',
  'smtp_test_address' => '',
  'smtp_debugging' => TRUE,
  'prev_mail_system' => 'php_mail',
  'smtp_keepalive' => FALSE,
];

// Should the basecamp api try to refresh (defaults to FALSE)?
// $settings['basecamp_api_do_refresh'] = TRUE;
