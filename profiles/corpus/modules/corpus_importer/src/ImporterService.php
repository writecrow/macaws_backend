<?php

namespace Drupal\corpus_importer;

use Drupal\file\Entity\File;
use Drupal\node\Entity\Node;
use Drupal\taxonomy\Entity\Term;
use markfullmer\TagConverter\TagConverter;
use writecrow\LoremGutenberg\LoremGutenberg;
use writecrow\CountryCodeConverter\CountryCodeConverter;

/**
 * Class ImporterService.
 *
 * @package Drupal\corpus_importer
 */
class ImporterService {

  /**
   * Main method: execute parsing and saving of redirects.
   *
   * @param mixed $files
   *   Simple array of filepaths.
   * @param string $options
   *   User-supplied default flags.
   */
  public static function import($files, $options = []) {

    if (PHP_SAPI == 'cli' && function_exists('drush_main')) {
      ini_set("memory_limit", "2048M");
      $paths = array_slice(scandir($files), 2);
      $objects = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($files));
      foreach ($objects as $name => $object) {
        if (strpos($name, '.txt') !== FALSE) {
          $absolute_paths[]['tmppath'] = $name;
        }
      }
      $texts = self::convert($absolute_paths);
      foreach ($texts as $text) {
        if ($text['type'] == 'corpus') {
          $result = self::saveCorpusNode($text, $options);
        }
        if ($text['type'] == 'repository') {
          $result = self::saveRepositoryNode($text, $options);
        }
        if (isset($result['created'])) {
          echo $result['created'] . PHP_EOL;
        }

      }
    }
    else {
      // Convert files into machine-readable array.
      $texts = self::convert($files);
      drupal_set_message(count($files) . ' files found.');

      // Perform validation logic on each row.
      $texts = array_filter($texts, ['self', 'preSave']);

      // Save valid texts.
      foreach ($texts as $text) {
        $operations[] = [
          ['\Drupal\corpus_importer\ImporterService', 'save'],
          [$text, $options],
        ];
      }

      $batch = [
        'title' => t('Saving Texts'),
        'operations' => $operations,
        'finished' => ['\Drupal\corpus_importer\ImporterService', 'finish'],
        'file' => drupal_get_path('module', 'corpus_importer') . '/corpus_importer.module',
      ];

      batch_set($batch);
    }
  }

  /**
   * Convert CSV file into readable PHP array.
   *
   * @param mixed $files
   *   Simple array of filepaths.
   *
   * @return mixed[]
   *   Converted texts, in array format.
   */
  protected static function convert($files) {
    $data = [];
    foreach ($files as $uploaded_file) {
      $file = file_get_contents($uploaded_file['tmppath']);
      $text = TagConverter::php($file);
      $text['filename'] = basename($uploaded_file['tmppath'], '.txt');
      if (isset($text['ID'])) {
        $text['type'] = 'corpus';
        $data[] = $text;
      }
      if (isset($text['File ID'])) {
        $text['type'] = 'repository';
        $text['full_path'] = $uploaded_file['tmppath'];
        $data[] = $text;
      }
    }

    return $data;
  }

  /**
   * Check for problematic data and remove or clean up.
   *
   * @param str[] $text
   *   Keyed array of texts.
   *
   * @return bool
   *   A TRUE/FALSE value to be used by array_filter.
   */
  public static function preSave(array $text) {
    return TRUE;
  }

  /**
   * Save an individual entity.
   *
   * @param str[] $text
   *   Keyed array of redirects, in the format
   *    [source, redirect, status_code, language].
   * @param str[] $options
   *   A 1 indicates that existing entities should be updated.
   * @param str[] $context
   *   Operational context for batch processes.
   */
  public static function save(array $text, array $options, array &$context) {
    if (isset($text['ID'])) {
      $result = self::saveCorpusNode($text, $options);
    }
    if (isset($text['File ID'])) {
      $result = self::saveRepositoryNode($text, $options);
    }
    $key = key($result);
    $context['results'][$key][] = $result[$key];
  }

  /**
   * Helper function to save corpus data.
   */
  public static function saveCorpusNode($text, $options = []) {
    // The key *must* match what is provided in the original text file.
    $taxonomies = [
      'Assignment' => 'assignment',
      'College' => 'college',
      'Country' => 'country',
      'Course' => 'course',
      'Draft' => 'draft',
      'Gender' => 'gender',
      'Institution' => 'institution',
      'Instructor' => 'instructor',
      'Program' => 'program',
      'Semester writing' => 'semester',
      'Year writing' => 'year',
      'Year in School' => 'year_in_school',
      'Semester in School' => 'year_in_school',
    ];

    $fields = [];
    foreach ($taxonomies as $name => $machine_name) {
      if (in_array($name, array_keys($text))) {
        // Standardize N/A values.
        if (in_array($machine_name, ['instructor', 'institution']) && in_array($text[$name], ['NA'])) {
          $text[$name] = 'N/A';
        }
        $tid = self::getTidByName($text[$name], $machine_name);
        if ($tid == 0) {
          // Convert country IDs to readable names.
          if ($machine_name == 'country') {
            $text[$name] = CountryCodeConverter::convert($text[$name]);
          }
          self::createTerm($text[$name], $machine_name);
          $tid = self::getTidByName($text[$name], $machine_name);
        }
      }
      $fields[$machine_name] = $tid;
    }

    if (isset($options['lorem']) && $options['lorem']) {
      $text['text'] = LoremGutenberg::generate(['sentences' => 10]);
    }
    if (isset($options['merge']) && $options['merge']) {
      $nodes = \Drupal::entityTypeManager()
        ->getStorage('node')
        ->loadByProperties(['title' => $text['filename']]);
      // Default to first instance found, in the unlikely event that there
      // are more than one.
      $node = reset($nodes);
      $return = 'updated';
    }
    if (!$node) {
      // Instantiate a new node object.
      $node = Node::create(['type' => 'text']);
      $return = 'created';
    }
    $node->set('title', $text['filename']);
    foreach ($taxonomies as $name => $machine_name) {
      $node->set('field_' . $machine_name, ['target_id' => $fields[$machine_name]]);
    }
    $node->set('field_id', ['value' => $text['ID']]);
    $node->set('field_toefl_total', ['value' => $text['TOEFL total']]);
    $node->set('field_toefl_writing', ['value' => $text['TOEFL writing']]);
    $node->set('field_toefl_speaking', ['value' => $text['TOEFL speaking']]);
    $node->set('field_toefl_reading', ['value' => $text['TOEFL reading']]);
    $node->set('field_toefl_listening', ['value' => $text['TOEFL listening']]);

    $body = trim(html_entity_decode($text['text']));
    // Remove unnecessary <End Header> text.
    $body = str_replace('<End Header>', '', $body);
    $node->set('field_body', ['value' => $body, 'format' => 'plain_text']);
    $node->save();
    // Send back metadata on what happened.
    return [$return => $text['filename']];
  }

  /**
   * Helper function to save repository data.
   */
  public static function saveRepositoryNode($text, $options = []) {
    // First check if we can find the file.
    $file = self::uploadRepositoryResource($text['full_path']);
    if (!$file) {
      return ['file not found' => 'Corresponding file not found for' . $text['filename']];
    }
    else {
      $path_parts = pathinfo($file->getFileUri());
      $text['File Type'] = $path_parts['extension'];
    }
    // The key *must* match what is provided in the original text file.
    $taxonomies = [
      'Assignment' => 'assignment',
      'Course' => 'course',
      'Mode' => 'mode',
      'Length' => 'length',
      'Institution' => 'institution',
      'Instructor' => 'instructor',
      'Document Type' => 'document_type',
      'Course Semester' => 'semester',
      'Course Year' => 'year',
      'File Type' => 'file_type',
    ];
    $fields = [];
    foreach ($taxonomies as $name => $machine_name) {
      if (in_array($name, array_keys($text))) {
        // Standardize N/A values.
        if (in_array($machine_name, ['instructor', 'institution']) && in_array($text[$name], ['NA'])) {
          $text[$name] = 'N/A';
        }
        $tid = self::getTidByName($text[$name], $machine_name);
        if ($tid == 0) {
          self::createTerm($text[$name], $machine_name);
          $tid = self::getTidByName($text[$name], $machine_name);
        }
      }
      $fields[$machine_name] = $tid;
    }

    if (isset($options['lorem']) && $options['lorem']) {
      $text['text'] = LoremGutenberg::generate(['sentences' => 10]);
    }
    if (isset($options['merge']) && $options['merge']) {
      $nodes = \Drupal::entityTypeManager()
        ->getStorage('node')
        ->loadByProperties(['title' => $text['File ID']]);
      // Default to first instance found, in the unlikely event that there
      // are more than one.
      $node = reset($nodes);
      $return = 'updated';
    }
    if (!$node) {
      // Instantiate a new node object.
      $node = Node::create(['type' => 'resource']);
      $return = 'created';
    }
    $node->set('title', $text['File ID']);
    $node->set('field_file', array('target_id' => $file->id()));
    $node->set('field_filename', ['value' => $text['filename']]);
    foreach ($taxonomies as $name => $machine_name) {
      $node->set('field_' . $machine_name, ['target_id' => $fields[$machine_name]]);
    }

    $body = trim(html_entity_decode($text['text']));
    // Remove unnecessary <End Header> text.
    $body = str_replace('<End Header>', '', $body);
    $node->set('field_raw_text', ['value' => $body, 'format' => 'plain_text']);
    $node->save();
    // Send back metadata on what happened.
    return [$return => $text['filename']];
  }

  public static function uploadRepositoryResource($full_path) {
    $path_parts = pathinfo($full_path);
    $path_parts['dirname'] = str_replace('/Text/', '/Original/', $path_parts['dirname']);
    $original_wildcard = $path_parts['dirname'] . '/' .  $path_parts['filename'] . '.*';
    $original_file = glob($original_wildcard);
    if (!empty($original_file[0])) {
      $original_parts = pathinfo($original_file[0]);
      $file = File::create([
        'uid' => 1,
        'filename' => $original_parts['basename'],
        'uri' => 'public://resources/' . $original_parts['basename'],
        'status' => 1,
      ]);
      $file->save();
      $file_content = file_get_contents($original_file[0]);
      $directory = 'public://resources/';
      file_prepare_directory($directory, FILE_CREATE_DIRECTORY);
      $file_image = file_save_data($file_content, $directory . basename($test_file),  FILE_EXISTS_REPLACE);
      return $file;
    }
    else {
      print_r('File not found! ' . $original_wildcard);
    }
    return FALSE;
  }  

  /**
   * Utility: find term by name and vid.
   *
   * @param string $name
   *   Term name.
   * @param string $vid
   *   Term vid.
   *
   * @return int
   *   Term id or 0 if none.
   */
  protected static function getTidByName($name = NULL, $vid = NULL) {
    $properties = [];
    if (!empty($name)) {
      $properties['name'] = $name;
    }
    if (!empty($vid)) {
      $properties['vid'] = $vid;
    }
    $terms = \Drupal::entityManager()->getStorage('taxonomy_term')->loadByProperties($properties);
    $term = reset($terms);

    return !empty($term) ? $term->id() : 0;
  }

  /**
   * Helper function.
   */
  public static function createTerm($name, $taxonomy_type) {
    $term = Term::create([
      'name' => $name,
      'vid' => $taxonomy_type,
    ])->save();
    return TRUE;
  }

  /**
   * Batch API callback.
   */
  public static function finish($success, $results, $operations) {
    if (!$success) {
      $message = t('Finished, with possible errors.');
      drupal_set_message($message, 'warning');
    }
    if (isset($results['updated'])) {
      drupal_set_message(count($results['updated']) . ' texts updated.');
    }
    if (isset($results['created'])) {
      drupal_set_message(count($results['created']) . ' texts created.');
    }

  }

}
