<?php

namespace Drupal\corpus_importer;

use Drupal\Component\Utility\Html;
use Drupal\file\Entity\File;
use Drupal\node\Entity\Node;
use Drupal\taxonomy\Entity\Term;
use writecrow\TagConverter\TagConverter;
use writecrow\LoremGutenberg\LoremGutenberg;
use writecrow\CountryCodeConverter\CountryCodeConverter;

/**
 * Class ImporterService.
 *
 * @package Drupal\corpus_importer
 */
class ImporterService {

  public static $assignments = [
    "AB" => "Annotated Bibliography",
    "AR" => "Argumentative Paper",
    "CA" => "Controversy Analysis",
    "CS" => "Case Study",
    "DE" => "Description and Explanation",
    "FA" => "Film Analysis",
    "GA" => "Genre Analysis",
    "GR" => "Genre Redesign",
    "IR" => "Interview Report",
    "LN" => "Literacy Narrative",
    "LR" => "Literature Review",
    "ME" => "Memo",
    "NR" => "Narrative",
    "OL" => "Open Letter",
    "PA" => "Public Argument",
    "PO" => "Portfolio",
    "PR" => "Profile",
    "PS" => "Position Argument",
    "RA" => "Rhetorical Analysis",
    "RE" => "Response",
    "RF" => "Reflection/Portfolio",
    "RR" => "Register Rewrite",
    "RP" => "Research Proposal",
    "SR" => "Summary and Response",
    "SY" => "Synthesis",
    "TA" => "Text Analysis",
    "VA" => "Variation Analysis",
  ];

  public static $docTypes = [
    "AC" => "Activity",
    "SL" => "Syllabus",
    "LP" => "Lesson Plan",
    "AS" => "Assignment Sheet/Prompt",
    "RU" => "Rubric",
    "PF" => "Peer Review Form",
    "QZ" => "Quiz",
    "HO" => "Handout",
    "SM" => "Supporting Material",
    "SP" => "Sample Paper",
    "HD" => "Handout",
    "NA" => "Not specific to any major assignment",
  ];

  public static $countryFixes = [
    'CHI' => 'CHN',
    'MLY' => 'MYS',
    'LEB' => 'LBN',
    'TKY' => 'TUR',
    'BRZ' => 'BRA',
    'SDA' => 'SAU',
  ];

  public static $draftFixes = [
    'D1' => '1',
    'D2' => '2',
    'D3' => '3',
    'D4' => '4',
    'DF' => 'F',
  ];

  public static $courseFixes = [
    '106' => 'ENGL 106',
    '107' => 'ENGL 107',
    '108' => 'ENGL 108',
  ];

  public static $collegeSpecific = [
    'Purdue University' => [
      'US' => 'Exploratory Studies',
      'E' => 'First Year Engineering',
      'M' => 'School of Management',
    ],
    'University of Arizona' => [
      'US' => 'College of Letters Arts & Sciences',
      'E' => 'College of Engineering',
      'M' => 'Eller College of Management',
    ],
  ];

  public static $collegeGeneral = [
    'EU' => 'College of Education',
    'A' => 'College of Agriculture',
    'HH' => 'College of Health & Human Sci',
    'LA' => 'College of Liberal Arts',
    'PC' => 'College of Pharmacy',
    'S' => 'College of Science',
    'PI' => 'Polytechnic Institute',
    'T' => 'Polytechnic Institute',
    'PP' => 'Pre-Pharmacy',
    'CH' => 'School of Chemical Engineering',
    'EC' => 'School of Electrical & Computer Engineering',
    'ME' => 'School of Mechanical Engineering',
    'CFA' => 'College of Fine Arts',
    'SBS' => 'College of Social & Behavioral Sciences',
    'COH' => 'College of Humanities',
    'NUR' => 'College of Nursing',
    'APL' => 'College of Architecture, Planning, & Landscape',
    'MED' => 'College of Medicine',
  ];

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
      ini_set("memory_limit", "4096M");
      $paths = array_slice(scandir($files), 2);
      $absolute_paths = [];
      $repository_candidates = [];
      $objects = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($files));
      foreach ($objects as $filepath => $object) {
        if (stripos($filepath, '.txt') !== FALSE) {
          $absolute_paths[]['tmppath'] = $filepath;
        }
        if (stripos($filepath, '.txt') === FALSE) {
          $path_parts = pathinfo($filepath);
          // Get a filelist of repository materials eligible for upload.
          $repository_candidates[$path_parts['filename']] = $filepath;
        }
      }
      $texts = self::convert($absolute_paths);

      foreach ($texts as $text) {
        // Fix failures in corpus headers:
        if (empty($text['Institution'])) {
          $text['Institution'] = 'Purdue University';
        }
        if ($text['type'] == 'corpus') {
          $result = self::saveCorpusNode($text, $options);
        }
        if ($text['type'] == 'repository') {
          $result = self::saveRepositoryNode($text, $repository_candidates, $options);
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
   * Convert tagged file into readable PHP array.
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
      if (isset($text['Student ID'])) {
        $text['type'] = 'corpus';
        $data[] = $text;
      }
      elseif (isset($text['File ID'])) {
        $text['type'] = 'repository';
        $text['full_path'] = $uploaded_file['tmppath'];
        $data[] = $text;
      }
      elseif (isset($text['ID'])) {
        // Assume that files with "ID" are corpus files.
        $text['Student ID'] = $text['ID'];
        $text['type'] = 'corpus';
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
    if (isset($text['Student ID'])) {
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
      'Course Semester' => 'semester',
      'Course Year' => 'year',
      'Year in School' => 'year_in_school',
      'Year writing' => 'year',
      'Semester writing' => 'semester',
    ];
    $fields = [];
    foreach ($taxonomies as $name => $machine_name) {
      $tid = '';
      $save = TRUE;
      if (in_array($name, array_keys($text))) {
        // Skip N/A values.
        if (in_array($text[$name], ['NA', 'N/A'])) {
          $save = FALSE;
        }
        if (in_array($machine_name, ['program', 'college'])) {
          if (is_string($text[$name])) {
            $multiples = preg_split("/\s?;\s?/", $text[$name]);
            if (isset($multiples[1])) {
              array_push($multiples, $text[$name]);
              $text[$name] = $multiples;
            }

          }
        }
        if (in_array($machine_name, ['institution']) && empty($text['Institution'])) {
          $text['Institution'] = 'Purdue University';
        }
        if (in_array($machine_name, ['gender']) && $text['Gender'] == 'G') {
          $text['Gender'] = 'M';
        }
        if ($machine_name == 'assignment') {
          $assignment_code = $text['Assignment'];
          $text['Assignment'] = self::$assignments[$assignment_code];
        }
        // Standardize draft names.
        if ($machine_name == 'draft') {
          if (in_array($text[$name], array_keys(self::$draftFixes))) {
            $code = $text[$name];
            $text[$name] = self::$draftFixes[$code];
          }
        }
        // Standardize course names.
        if ($machine_name == 'course') {
          if (in_array($text[$name], array_keys(self::$courseFixes))) {
            $code = $text[$name];
            $text[$name] = self::$courseFixes[$code];
          }
        }  
        // Convert country IDs to readable names.
        if ($machine_name == 'country') {
          if (in_array($text[$name], array_keys(self::$countryFixes))) {
            $code = $text[$name];
            $text[$name] = self::$countryFixes[$code];
          }
          $text[$name] = CountryCodeConverter::convert($text[$name]);
        }
        if ($save) {
          if (is_array($text[$name])) {
            $tids = [];
            foreach ($text[$name] as $term) {
              if ($machine_name == 'college') {
                $instititution = $text['Institution'];
                if (in_array($term, array_keys(self::$collegeGeneral))) {
                  $term = self::$collegeGeneral[$term];
                }
                elseif (in_array($term, array_keys(self::$collegeSpecific[$instititution]))) {
                  $term = self::$collegeSpecific[$instititution][$term];
                }
                else {
                  continue;
                }
              }
              $tid = self::getTidByName($term, $machine_name);
              if ($tid == 0) {
                self::createTerm($term, $machine_name);
                $tid = self::getTidByName($term, $machine_name);
              }
              $tids[] = $tid;
            }
            $fields[$machine_name] = $tids;
          }
          else {
            $tid = self::getTidByName($text[$name], $machine_name);
            if ($tid == 0 && isset($text[$name])) {
              self::createTerm($text[$name], $machine_name);
              $tid = self::getTidByName($text[$name], $machine_name);
            }
            if ($tid != 0) {
              $fields[$machine_name] = $tid;
            }
          }
        }
      }
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
      if (!empty($fields[$machine_name])) {
        if (is_array($fields[$machine_name])) {
          $elements = [];
          foreach ($fields[$machine_name] as $delta => $term) {
            $elements[] = ['delta' => $delta, 'target_id' => $term];
          }
          $node->set('field_' . $machine_name, $elements);
        }
        else {
          $node->set('field_' . $machine_name, ['target_id' => $fields[$machine_name]]);
        }
      }
    }

    $node->set('field_id', ['value' => $text['Student ID']]);
    $node->set('field_toefl_total', ['value' => $text['TOEFL total']]);
    $node->set('field_toefl_writing', ['value' => $text['TOEFL writing']]);
    $node->set('field_toefl_speaking', ['value' => $text['TOEFL speaking']]);
    $node->set('field_toefl_reading', ['value' => $text['TOEFL reading']]);
    $node->set('field_toefl_listening', ['value' => $text['TOEFL listening']]);

    $body = trim(html_entity_decode($text['text']));
    // Remove unnecessary <End Header> text.
    $body = str_replace('<End Header>', '', $body);
    $node->set('field_body', ['value' => $body, 'format' => 'plain_text']);

    $clean = Html::escape(strip_tags($body));
    $node->set('field_wordcount', ['value' => str_word_count($clean)]);

    $node->save();
    // Send back metadata on what happened.
    return [$return => $text['filename']];
  }

  /**
   * Helper function to save repository data.
   */
  public static function saveRepositoryNode($text, $repository_candidates, $options = []) {
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
      'Assignment' => 'assignment',
      'Course' => 'course',
      'Mode' => 'mode',
      'Length' => 'course_length',
      'Institution' => 'institution',
      'Instructor' => 'instructor',
      'Document Type' => 'document_type',
      'Course Semester' => 'semester',
      'Course Year' => 'year',
      'File Type' => 'file_type',
      'Topic' => 'topic',
    ];
    $fields = [];
    foreach ($taxonomies as $name => $machine_name) {
      $save = TRUE;
      if (in_array($name, array_keys($text))) {
        // Skip N/A values.
        if (in_array($text[$name], ['NA', 'N/A'])) {
          $save = FALSE;
          continue;
        }
        if ($machine_name == 'document_type') {
          $doc_code = $text['Document Type'];
          $text['Document Type'] = self::$docTypes[$doc_code];
        }
        if ($machine_name == 'assignment') {
          $assignment_code = $text['Assignment'];
          $text['Assignment'] = self::$assignments[$assignment_code];
        }
        if (in_array($machine_name, ['topic'])) {
          if (is_string($text[$name])) {
            $multiples = preg_split("/\s?;\s?/", $text[$name]);
            if (isset($multiples[1])) {
              array_push($multiples, $text[$name]);
            }
            $text[$name] = $multiples;
          }
        }
        $tid = self::getTidByName($text[$name], $machine_name);
        if ($tid == 0) {
          self::createTerm($text[$name], $machine_name);
          $tid = self::getTidByName($text[$name], $machine_name);
        }
      }
      if ($save) {
        $fields[$machine_name] = $tid;
      }
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
    if (in_array($path_parts['filename'], array_keys($repository_candidates))) {
      $glob = glob($repository_candidates[$path_parts['filename']]);
    }
    else {
      $path_parts['dirname'] = str_replace('/Text/', '/Original/', $path_parts['dirname']);
      $original_wildcard = $path_parts['dirname'] . '/' . $path_parts['filename'] . '.*';
      $glob = glob($original_wildcard);
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
      file_prepare_directory($directory, FILE_CREATE_DIRECTORY);
      $file_saved = file_save_data($file_content, $directory . basename($original_file), FILE_EXISTS_REPLACE);
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
