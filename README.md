# Corpus/Repository Backend

[![Drupal 8 site](https://img.shields.io/badge/drupal-8-blue.svg)](https://drupal.org)

## Overview
This project contains the canonical resources to build the backend for a
corpus/repository management framework which serves data over a REST API. This is built on the Drupal CMS, following conventions of [Entity API](https://www.drupal.org/docs/8/api/entity-api/introduction-to-entity-api-in-drupal-8), [Search API](https://www.drupal.org/project/search_api), and the [REST API](https://www.drupal.org/docs/8/api/restful-web-services-api/restful-web-services-api-overview), and its configuration/implementation should present no surprises for developers familiar with Drupal.

From a fresh installation, the database schema will provide a `text` entity type, which holds the corpus text data and metadata, and a `repository` entity type, which references materials related to the texts. These entity types and the metadata they contain can be modified or extended as needed to fit the individual corpus.

The configuration provided subsequently includes search indices for texts and repository materials, and a REST API for performing keyword or metadata searches against the dataset.

This codebase does not make any assumptions about the way the data provided by the API is displayed (in a frontend).

## Building the codebase
Developing your own version of this site assumes familiarity with, and local installation of, the [Composer(https://getcomposer.org/) package manager. This repository contains only the "kernel" of the customized code & configuration. It uses Composer to build all assets required for the site, including the Drupal codebase and a handful of corpus-related PHP libraries.

Run `composer install` from the document root. This will build all assets required for the site. That's it!

## Installing the site
The following assumes familiarity with local web development for a PHP/MySQL stack. Since Drupal is written in PHP and uses an SQL database, that means you'll need:
- PHP 5.5.9 or higher. See [Drupal 8 PHP versions supported](https://www.drupal.org/docs/8/system-requirements/drupal-8-php-requirements).
- A database server (MySQL, PostgreSQL, or SQLlite that meets the [minimum Drupal 8 requirements](https://www.drupal.org/docs/8/system-requirements/database-server)).
- A webserver that meets the minimum PHP requirements above. Typically, this means Apache, Nginx, or Microsoft IIS. See [Drupal webserver requirements](https://www.drupal.org/docs/8/system-requirements/web-server).

There are a number of pre-packaged solutions that simplify setup of the above. These includes [MAMP](https://www.mamp.info/en/), [Valet](https://laravel.com/docs/5.6/valet), and [Lando](https://docs.devwithlando.io/).

1. `cp sites/example.settings.local.php sites/default/settings.local.php`
2. Create a MySQL database, then add its connection credentials to the newly created `settings.local.php`. Example:

```php
$databases['default']['default'] = [
  'database' => 'MYSQL_DATABASE',
  'username' => 'MYSQL_USERNAME',
  'password' => 'MYSQL_PASSWORD',
  'host' => 'localhost',
  'port' => '3306',
  'driver' => 'mysql',
  'prefix' => '',
  'collation' => 'utf8mb4_general_ci',
];
```
3. Either navigate to your local site's domain and follow the web-based installation instructions, or if you prefer to use `drush`, run the drush [site-install](https://drushcommands.com/drush-8x/core/site-install/) command.
4. That's it! After signing in at `/user`, you should see the two available entity types at `/node/add`, the available metadata references at `/admin/structure/taxonomy` and the search configuration at `/admin/config/search/search-api`

## Importing data
Properly prepared text files can be imported via a drag-and-drop interface at `/admin/config/media/import`

Each text file needs to include the metadata elements in the file, followed by the actual text to be indexed. A model for that file structure is below:

```
<ID: 11165>
<Country: BGD>
<Assignment: 1>
<Draft: A>
<Semester in School: 2>
<Gender: M>
<Term writing: Fall 2015>
<College: E>
<Program: Engineering First Year>
<TOEFL-total: NA>
<TOEFL-reading: NA>
<TOEFL-listening: NA>
<TOEFL-speaking: NA>
<TOEFL-writing: NA>
Sed ut perspiciatis unde omnis iste natus error sit.

Voluptatem accusantium doloremque laudantium, totam rem aperiam, eaque ipsa quae ab illo inventore veritatis et quasi architecto beatae vitae dicta sunt explicabo. Nemo enim ipsam voluptatem quia voluptas sit aspernatur aut odit aut fugit, sed quia consequuntur magni dolores eos qui ratione voluptatem sequi nesciunt. Neque porro quisquam est, qui dolorem ipsum quia dolor sit amet, consectetur, adipisci velit, sed quia non numquam eius modi tempora incidunt ut labore et dolore magnam aliquam quaerat voluptatem. Ut enim ad minima veniam, quis nostrum exercitationem ullam corporis suscipit laboriosam, nisi ut aliquid ex ea commodi consequatur? Quis autem vel eum iure reprehenderit qui in ea voluptate velit esse quam nihil molestiae consequatur, vel illum qui dolorem eum fugiat quo voluptas nulla pariatur?
```
Alternative to the UI import, a directory of local text files can be imported via the drush `corpus-import` command. Example usage:

`drush corpus-import /Users/me/myfiles/`

## Performing search requests via the API
@todo
