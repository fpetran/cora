<?php
/** @file config.defaults.php
 * Default configuration options for CorA.
 *
 * =============================================================================
 * DO NOT EDIT THIS FILE!
 * =============================================================================
 * Instead, clone this file under the name "config.php"
 * (if it does not exist already!) and adjust the configuration options there.
 * Alternatively, create an empty "config.php" and include only the lines
 * that you need.
 * =============================================================================
 * DO NOT EDIT THIS FILE!
 * =============================================================================
 */

// do not remove the following line
if (!defined('CORA_CONFIG_FILE')) { return; }

return array(
  /** This array should contain all the info required to connect to a CorA
  database instance. */
  "dbinfo" => array(
    /** The database server to connect to. */
    "HOST" => '127.0.0.1',
    /** The username for database login. */
    "USER" => 'cora',
    /** The password for database login. */
    "PASSWORD" => 'trustthetext',
    /** The name of the database. */
    "DBNAME" => 'cora'
  ),

  /** Default interface language for new users. */
  "default_language" => 'en-US',

  /** Directory to store external parametrizations for automatic annotators
      (e.g., tagger parametrizations that have been learned from certain
      projects. */
  "external_param_dir" => '/var/lib/cora/',

  /** Cost of the password-encryption algorithm. */
  "password_cost" => 12,

  /** Options describing this CorA instance. */
  "title" => "CorA",
  "longtitle" => "Corpus Annotator",
  "description" => "A corpus annotation tool for non-standard language varieties.",
  "keywords" => "annotation,corpus,POS",

  /** PHP session name; affects cookie name in browser. */
  "session_name" => 'PHPSESSID_CORA',

  /** Suffix for database user/password/name used for unit tests. */
  "test_suffix" => "test"
);

?>
