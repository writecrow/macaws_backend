<?php

namespace Drupal\corpus_importer;

use Drupal\Core\File\FileSystemInterface;
use Drupal\file\FileRepositoryInterface;
use Drupal\file\Entity\File;
use Drupal\node\Entity\Node;

/**
 * Class RepositoryImporter.
 *
 * @package Drupal\corpus_importer
 */
class RepositoryImporter extends ImporterService {

  /**
   * Helper function to save repository data.
   */
  public static function saveRepositoryNode($text, $repository_candidates) {
    // First check if we can find the file.
    $file = self::uploadRepositoryResource($text['full_path'], $repository_candidates);
    if (!$file) {
      return ['file not found' => 'Corresponding file not found for' . $text['filename']];
    }
    else {
      $path_parts = pathinfo($file->getFileUri());
      $text['File Type'] = $path_parts['extension'];
    }
    // The key *must* match what is provided in the original text file.
    $taxonomies = [
      'Institution' => 'institution',
      'Course Year' => 'course_year',
      'Course Semester' => 'course_semester',
      'Course' => 'course',
      'Macro Genre' => 'macro_genre',
      'Assignment Mode' => 'assignment_mode',
      'Assignment Topic' => 'assignment_topic',
      'Assignment Code' => 'assignment_code',
      'Document Type' => 'document_type',
      'Instructor' => 'instructor',
      'Target Language' => 'target_language',
    ];
    $fields = [];
    foreach ($taxonomies as $name => $machine_name) {
      $tid = 0;
      $save = TRUE;
      if (in_array($name, array_keys($text))) {
        // Skip N/A values.
        if (in_array($text[$name], ['NA', 'N/A'])) {
          $save = FALSE;
          continue;
        }
        if ($machine_name == 'document_type') {
          $doc_code = $text['Document Type'];
          $text['Document Type'] = ImporterService::$docTypes[$doc_code];
        }
        if (in_array($machine_name, ['assignment_topic'])) {
          if (is_string($text[$name])) {
            $multiples = preg_split("/\s?;\s?/", $text[$name]);
            if (isset($multiples[1])) {
              array_push($multiples, $text[$name]);
            }
            $text[$name] = $multiples;
          }
        }
        $tid = ImporterHelper::getTidByName($text[$name], $machine_name);
        if ($tid == 0) {
          ImporterHelper::createTerm($text[$name], $machine_name);
          $tid = ImporterHelper::getTidByName($text[$name], $machine_name);
        }
      }
      else {
        $save = FALSE;
      }
      if ($save) {
        $fields[$machine_name] = $tid;
      }
    }

    // Instantiate a new node object.
    $node = Node::create(['type' => 'resource']);
    $return = 'created';
    $node->set('title', $text['File ID']);
    $node->set('field_file', ['target_id' => $file->id()]);
    $node->set('field_filename', ['value' => $text['filename']]);
    foreach ($taxonomies as $name => $machine_name) {
      if (!empty($fields[$machine_name])) {
        $node->set('field_' . $machine_name, ['target_id' => $fields[$machine_name]]);
      }
    }

    $body = trim(html_entity_decode($text['text']));
    // Remove unnecessary <End Header> text.
    $body = str_replace('<End Header>', '', $body);
    $node->set('field_raw_text', ['value' => $body, 'format' => 'plain_text']);
    $node->save();
    // Send back metadata on what happened.
    return [$return => $text['filename']];
  }

  /**
   * Utility: save file to backend.
   */
  public static function uploadRepositoryResource($full_path, $repository_candidates) {
    $path_parts = pathinfo($full_path);
    $original_path = '';
    if (in_array($path_parts['filename'], array_keys($repository_candidates))) {
      $glob = glob($repository_candidates[$path_parts['filename']]);
    }
    else {
      $path_parts['dirname'] = str_replace('/Text/', '/Original/', $path_parts['dirname']);
      $original_path = $path_parts['dirname'] . '/' . $path_parts['filename'] . '.*';
      $glob = glob($original_path);
    }
    if (!empty($glob[0])) {
      $original_file = $glob[0];
      print_r("Importing original file " . $original_file . PHP_EOL);
      $original_parts = pathinfo($original_file);
      $file = File::create([
        'uid' => 1,
        'filename' => $original_parts['basename'],
        'uri' => 'public://resources/' . $original_parts['basename'],
        'status' => 1,
      ]);
      $file->save();
      $file_content = file_get_contents($original_file);
      $directory = 'public://resources/';
      \Drupal::service('file_system')->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY);
      \Drupal::service('file.repository')->writeData($file_content, $directory . basename($original_file), FileSystemInterface::EXISTS_REPLACE);
      return $file;
    }
    else {
      print_r('File not found! ' .$original_path);
    }
    return FALSE;
  }

}
