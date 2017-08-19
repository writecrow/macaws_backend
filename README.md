# Corpus/Repository Backend

[![Drupal 8 site](https://img.shields.io/badge/drupal-8-blue.svg)](https://drupal.org)
## Overview

This project contains the canonical resources used to build the backend for a
corpus/repository management framework.

To be deployed, it must added to a Drupal 8 codebase, and `composer install` run
in order to retrieve 3rd party library dependencies.

It consists of:
- An installation profile that guides the corpus builder through installation
- Database schema for a single "text" type, as well as metadata fields for:
-- Assignment number
-- College
-- Country
-- Draft number
-- Gender
-- Program
-- Semester in School
-- Term Writing
- A UI importer for importing texts (see `corpus_importer`)
- A configured search index with UI for backend testing.
- A REST API for retrieving search results & texts via HTTP

![Screenshot of Search UI](https://raw.githubusercontent.com/writecrow/corpus_backend/master/screenshot.png)
