<?php

/** @file connect.php
 * Provide objects for database access.
 *
 * @author Marcel Bollmann
 * @date January 2012
 *
 * @todo Database access information should be moved outside of the
 * web server directory!
 */

/** The database settings. */
require_once 'globals.php';

/** Exception when an SQL query fails; currently only used by @c
 *  LongSQLQuery class.
 */
class SQLQueryException extends Exception { }

/** Class enabling arbitrarily long SQL queries by breaking them up
 *  into smaller parts.
 */
class LongSQLQuery {
  private $head;       /**< String to be put before each query. */
  private $tail;       /**< String to be put after each query. */
  private $db;         /**< The database connector to be used. */
  private $len;
  private $qa;

  public function __construct($db, $head="", $tail="") {
    $this->db   = $db;
    $this->head = $head;
    $this->tail = $tail;
    $this->len  = strlen($head) + strlen($tail) + 2;
    $this->qa   = array();
  }

  /** Append a new list (i.e., comma-separated) item to the query. */
  public function append($item) {
    $newlen = $this->len + strlen($item) + 2;
    // if query length would exceed maximum, perform a flush first:
    if($newlen>DB_MAX_QUERY_LENGTH){
      $this->flush();
      $newlen = $this->len + strlen($item) + 2;
    }
    $this->len  = $newlen;
    $this->qa[] = $item;
    return False;
  }

  /** Send the query to the server. */
  public function flush() {
    if(empty($this->qa)){ return False; }

    $query  = $this->head . ' ';
    $query .= implode(', ', $this->qa);
    $query .= ' ' . $this->tail;

    if(!$this->db->query($query)){
      throw new SQLQueryException(mysql_errno().": ".mysql_error());
    }

    $this->len = strlen($this->head) + strlen($this->tail) + 2;
    $this->qa  = array();
    return False;
  }
}


/** Class providing a database connection.
 *
 * This class sets up a database connection and provides helper
 * functions to perform database queries.  Access information (server,
 * database name, username, password) is currently hard-coded.
 */
class DBConnector {
  private $dbobj;                     /**< Database object as returned by @c mysql_connect(). */
  private $db_server   = DB_SERVER;   /**< Name of the server to connect to. */
  private $db_user     = DB_USER;     /**< Username to be used for database access. */
  private $db_password = DB_PASSWORD; /**< Password to be used for database access. */
  public  $db          = MAIN_DB;     /**< Name of the database to be used. */
  private $transaction = false;

  /** Create a new DBConnector.
   *
   * Creates a new SQL connection using the access information
   * hard-coded into this class.
   */
  function __construct() {
    $this->dbobj = @mysql_connect( $this->db_server, $this->db_user, $this->db_password );
  }

  /** Check if a connection exists.
   * @return @c true if there is a database connection
   */
  public function isConnected() {
    if (!$this->dbobj) {return false;} else {return true;}
  }

  /** Set the default database. The name of the default database
   * should be referenced in every SQL query string.
   *
   * @param string $name Name of the database to be set as default.
   */
  public function setDefaultDatabase( $name ) {
    $this->db = $name;
  }

  /** Select a database.
   *
   * @param string $name Name of the database to be selected.
   * @deprecated Database names should always be explicitly included
   * in the query string now.
   */
  protected function selectDatabase( $name ) {
    $status = mysql_select_db( $name, $this->dbobj );
    if ($status) {
      mysql_query( "SET NAMES 'utf8'", $this->dbobj ); 
    }
    return $status;
  }

  /** Start an SQL transaction. */
  public function startTransaction() {
    $status = mysql_query( "START TRANSACTION", $this->dbobj );
    if ($status) {
      $status = $this->selectDatabase( $this->db );
      $this->transaction = true;
    }
    return $status;
  }

  /** Commits an SQL transaction. */
  public function commitTransaction() {
    $this->transaction = false;
    return mysql_query( "COMMIT", $this->dbobj );
  }

  /** Rollback an SQL transaction. */
  public function rollback() {
    $this->transaction = false;
    return mysql_query( "ROLLBACK", $this->dbobj );
  }

  /** Perform a database query.
   *
   * @param string $query Query string in SQL syntax.
   * @return The result of the respective @c mysql_query() command.
   */
   public function query( $query ) { 
     $this->selectDatabase( $this->db );
     return mysql_query( $query, $this->dbobj ); 
   }

  /** Perform a critical database query.
   *
   * Executes a database query which is always expected to succeed and
   * critical for further operation. If the query fails, PHP execution
   * is halted and a critical error is sent.
   *
   * @param string $query Query string in SQL syntax.
   * @return The result of the respective @c mysql_query() command.
   */
  protected function criticalQuery($query) {
    $status = $this->query($query);
    if (!$status) {
      if ($this->transaction) {
	$this->rollback();
      }
      header("HTTP/1.1 500 Internal Server Error");
      echo("Database error while processing the following query: {$query}");
      die();
    }
    return $status;
  }

  /** Clone of RequestHandler::escapeSQL() */
  protected function escapeSQL( $obj ) {
    if(is_string($obj)) {
      return mysql_real_escape_string(stripslashes($obj));
    }
    else if(is_array($obj)) {
      $newarray = array();
      foreach($obj as $k => $v) {
	$newarray[$k] = self::escapeSQL($v);
      }
      return $newarray;
    }
    else if(is_object($obj) && get_class($obj)=='SimpleXMLElement') {
      return self::escapeSQL((string) $obj);
    }
    else {
      return $obj;
    }
  }
}


/** An interface for application-specific database requests.
 *
 * This class implements all application-specific functionality for
 * database access.  If some part of the application requires that one
 * or more SQL queries be sent to the database, these queries should
 * be encapsulated in a member function of this class.
 */
class DBInterface extends DBConnector {

  /** Create a new DBInterface.
   *
   * Also sets the default database to the MAIN_DB constant.
   */
  function __construct() {
    parent::__construct();
    $this->setDefaultDatabase( MAIN_DB );
  }

  /** Return the hash of a given password string. */
  private function hashPassword($pw) {
    return md5(sha1($pw));
  }

  /** Look up username and password. 
   *
   * @param string $user Username to be looked up.
   * @param string $pw Password corresponding to the username.
   *
   * @return An @em array containing the database entry for the given
   * user. If the username is not valid, or if the password is not
   * correct, the query will fail, and an empty object is returned.
   */
  public function getUserData( $user, $pw ) {
    $pw_hash = $this->hashPassword($pw);
    $qs = "SELECT name, admin, lastactive FROM {$this->db}.users "
      . "WHERE name='{$user}' AND password='{$pw_hash}' AND `id`!=1 LIMIT 1";
    $query = $this->query( $qs );
    return @mysql_fetch_array( $query );
  }

  /** Get user info by id.
   */
  public function getUserById($uid) {
    $qs = "SELECT `id`, name, admin, lastactive FROM {$this->db}.users "
      . "WHERE `id`={$uid}";
    $query = $this->query( $qs );
    return @mysql_fetch_array( $query );
  }
  
  /** Get user info by name.
   */
  public function getUserByName($uname) {
    $qs = "SELECT `id`, name, admin, lastactive FROM {$this->db}.users "
      . "WHERE name='{$uname}'";
    $query = $this->query( $qs );
    return @mysql_fetch_array( $query );
  }

  /** Return settings for the current user. 
   *
   * @param string $user Username
   *
   * @return An array with the database entries from the table 'user_settings' for the given user.
   */
  public function getUserSettings($user){
	$qs = "SELECT lines_per_page, lines_context, "
	    . "columns_order, columns_hidden, show_error "
	    . "FROM {$this->db}.users WHERE name='{$user}'";
	return @mysql_fetch_assoc( $this->query( $qs ) );
  }

  /** Get a list of all users.
   *
   * @return An @em array containing all usernames in the database and
   * information about their admin status.
   */
  public function getUserList() {
    $qs = "SELECT `id`, name, admin, lastactive FROM {$this->db}.users WHERE `id`!=1";
    $query = $this->query( $qs );
    $users = array();
    while ( @$row = mysql_fetch_array($query) ) {
      $users[] = $row;
    }
    return $users;
  }

  /** Get a list of all tagsets.
   *
   * @param string $lang A language code (@c de or @c en)
   *
   * @return A list of associative arrays, containing the names of the
   * tagset (in the respective language) and some basic information.
   */
  public function getTagsets( $lang ) {
    $result = array();
    $qs = "SELECT a.*, b.{$lang} "
      . "FROM {$this->db}.tagsets a, {$this->db}.tagset_strings b "
      . "WHERE a.tagset=b.tagset AND b.id=0 ORDER BY a.tagset";
    $query = $this->query($qs);
    while ( @$row = mysql_fetch_array( $query, $this->dbobj ) ) {
      $data = array();
      $data["shortname"] = $row["tagset"];
      $data["last_modified"] = $row["last_modified"];
      $data["last_modified_by"] = $row["last_modified_by"];
      $data["longname"] = $row[$lang];
      $result[] = $data;
    }
    return $result;
  }

  /** Build and return an array containing a full tagset.
   *
   * This function retrieves all tags, attributes, and attribute
   * values of a given tagset, as well as the 'linking' information,
   * i.e.\ which attributes are applicable for which tag. The
   * description of each tag and attribute is also retrieved in the
   * given language.
   *
   * @param string $tagset The short name of the tagset to be retrieved
   * @param string $lang A language code (@c de or @c en)
   *
   * @return An associative @em array containing the tagset information.
   */
  public function getTagset( $tagset, $lang ) {
    $tags = array();
    $attribs = array();

    // fetch tags
    $qs = "SELECT a.shortname, a.id, a.type, b.{$lang} "
      . "FROM {$this->db}.tagset_tags a, {$this->db}.tagset_strings b "
      . "WHERE a.tagset='{$tagset}' AND b.tagset='{$tagset}' "
      . "AND a.id=b.id ORDER BY a.shortname";
    $query = $this->query($qs); 
    while ( @$row = mysql_fetch_array( $query, $this->dbobj ) ) {
      if ($row['type'] == "tag") {
	$tags[$row['id']]['desc']      = $row[$lang];
	$tags[$row['id']]['shortname'] = $row['shortname'];
	$tags[$row['id']]['link']      = array();
      }
      else if ($row['type'] == "attrib") {
	$attribs[$row['id']]['desc']      = $row[$lang];
	$attribs[$row['id']]['shortname'] = $row['shortname'];
      }
    }
    
    // fetch links
    foreach ($tags as $id => $tag) {
      $qs = "SELECT attrib_id FROM {$this->db}.tagset_links "
	. "WHERE tagset='{$tagset}' AND tag_id='{$id}'";
      $query = $this->query($qs);
      while ( @$row = mysql_fetch_array( $query, $this->dbobj ) ) {
	$tags[$id]['link'][] = $row['attrib_id'];
      }
    }

    // fetch attribute values
    $qs = "SELECT `id`, value FROM {$this->db}.tagset_values "
      . "WHERE tagset='{$tagset}' ORDER BY value";
    $query = $this->query($qs);
    while ( @$row = mysql_fetch_array( $query, $this->dbobj ) ) {
      $attribs[$row['id']]['val'][] = $row['value'];
    }

    // combine and return
    $result = array("tags" => $tags, "attribs" => $attribs);
    return $result;
  }

  /** Save modifications to a tagset to the database.
   *
   * This functions saves modifications to a tagset to the
   * database. The language code determines the column to which tag
   * and attribute descriptions are written.
   *
   * @param array $data An array containing the tagset to be
   * saved. The keys of the array are expected to be as follows:
   * @arg @c tagset The name of the tagset
   * @arg @c created Tags/attributes that need to be created in the database.
   * @arg @c modified Tags/attributes which already exist in the database,
   * but should receive some kind of modification.
   * @arg @c deleted Tags/attributes in the database which should be deleted.
   *
   * @param string $lang A language code (@c de or @c en)
   *
   * @return @c true if the operation was successful, @c false otherwise.
   */

  public function saveTagset( $data, $lang ) {
    $tagset = $data['tagset'];

    $lock = $this->lockTagset($tagset);
    if (!$lock['success']) {
      return false;
    }

    $this->startTransaction();

    // newly created tags
    foreach($data['created'] as $i => $entry) {
      $qs = "INSERT INTO {$this->db}.tagset_tags (tagset, `id`, shortname, type) "
	. "VALUES ('{$tagset}', {$entry['id']}, "
	. "'{$entry['shortname']}', '{$entry['type']}') "
	. "ON DUPLICATE KEY UPDATE shortname='{$entry['shortname']}'";
      $this->criticalQuery($qs);
      $qs = "INSERT INTO {$this->db}.tagset_strings (tagset, `id`, {$lang}) "
	. "VALUES ('{$tagset}', {$entry['id']}, '{$entry['desc']}') "
	. "ON DUPLICATE KEY UPDATE {$lang}='{$entry['desc']}'";
      $this->criticalQuery($qs);

      if ($entry['type'] == 'tag') {
	foreach($entry['link'] as $i => $link_id) {
	  $qs = "INSERT IGNORE INTO {$this->db}.tagset_links (tagset, tag_id, attrib_id) "
	    . "VALUES ('{$tagset}', {$entry['id']}, {$link_id})";
	  $this->criticalQuery($qs);
	}
      }
      else if ($entry['type'] == 'attrib') {
	foreach($entry['val'] as $i => $value) {
	  $qs = "INSERT INTO {$this->db}.tagset_values (tagset, `id`, value) "
	    . "VALUES ('{$tagset}', {$entry['id']}, '{$value}')";
	  $this->criticalQuery($qs);
	}
      }
    }

    // modified tags
    foreach($data['modified'] as $i => $entry) {
      $qs = "UPDATE {$this->db}.tagset_tags "
	. "SET shortname='{$entry['shortname']}' "
	. "WHERE tagset='{$tagset}' AND `id`={$entry['id']}";
      $this->criticalQuery($qs);
      $qs = "UPDATE {$this->db}.tagset_strings "
	. "SET {$lang}='{$entry['desc']}' "
	. "WHERE tagset='{$tagset}' AND `id`={$entry['id']}";
      $this->criticalQuery($qs);

      // for links and attrib. values, delete all values first,
      // then re-create them (this is because we don't keep track of
      // links/values which have been deleted by the user)
      if ($entry['type'] == 'tag') {
	$qs = "DELETE FROM {$this->db}.tagset_links "
	  . "WHERE tagset='{$tagset}' AND tag_id={$entry['id']}";
	$this->criticalQuery($qs);
	foreach($entry['link'] as $i => $link_id) {
	  $qs = "INSERT INTO {$this->db}.tagset_links (tagset, tag_id, attrib_id) "
	    . "VALUES ('{$tagset}', {$entry['id']}, {$link_id})";
	  $this->criticalQuery($qs);
	}
      }
      else if ($entry['type'] == 'attrib') {
	$qs = "DELETE FROM {$this->db}.tagset_values "
	  . "WHERE tagset='{$tagset}' AND `id`={$entry['id']}";
	$this->criticalQuery($qs);
	foreach($entry['val'] as $i => $value) {
	  $qs = "INSERT INTO {$this->db}.tagset_values (tagset, `id`, value) "
	    . "VALUES ('{$tagset}', {$entry['id']}, '{$value}')";
	  $this->criticalQuery($qs);
	}
      }
    }

    // delete tags marked as 'deleted'
    foreach($data['deleted'] as $i => $id) {
      $qs = "DELETE FROM {$this->db}.tagset_tags WHERE tagset='{$tagset}' AND `id`={$id}";
      $this->criticalQuery($qs);
      $qs = "DELETE FROM {$this->db}.tagset_strings WHERE tagset='{$tagset}' AND `id`={$id}";
      $this->criticalQuery($qs);
      $qs = "DELETE FROM {$this->db}.tagset_values WHERE tagset='{$tagset}' AND `id`={$id}";
      $this->criticalQuery($qs);
      $qs = "DELETE FROM {$this->db}.tagset_links WHERE tagset='{$tagset}' "
	. "AND (tag_id={$id} OR attrib_id={$id})";
      $this->criticalQuery($qs);
    }

    // update last_modified information
    $qs = "UPDATE {$this->db}.tagsets "
	. "SET last_modified_by='{$_SESSION['user']}' "
	. "WHERE tagset='{$tagset}'";
    $this->query($qs); // non-critical, so return value not checked
    $this->commitTransaction();

    return true;
  }

  /** Copy a tagset
   *
   * This function copies all database entries in all concerned tables for a given tagset
   * 
   * @todo extend function for the possibility to add new data directly to the 'new' copied tagset
   *
   * @param array $data array containing additional tags for the copied tagset  (not yet implemented)
   * @param string $originTagset name of the original tagset
   * @param string $newTagset name of the new created (copied) tagset
   * @param string $lang language code (@c de or @c en)
   *
   */
  public function saveCopyTagset($data,$originTagset,$newTagset,$lang){
	$lock = $this->lockTagset($newTagset);
    if (!$lock['success']) {
      return false;
    }

    $this->startTransaction();
	
	$qs = "INSERT INTO {$this->db}.tagset_tags (tagset,id,shortname,type) SELECT '{$newTagset}',id,shortname,type FROM {$this->db}.tagset_tags WHERE tagset='{$originTagset}'";
	$this->criticalQuery($qs);

	$qs = "INSERT INTO {$this->db}.tagset_strings (tagset,de,en,id) SELECT '{$newTagset}',de,en,id FROM {$this->db}.tagset_strings WHERE tagset='{$originTagset}'";
	$this->criticalQuery($qs);

	$qs = "INSERT INTO {$this->db}.tagset_links (tagset,tag_id,attrib_id) SELECT '{$newTagset}',tag_id,attrib_id FROM {$this->db}.tagset_links WHERE tagset='{$originTagset}'";
	$this->criticalQuery($qs);

	$qs = "INSERT INTO {$this->db}.tagset_values (tagset,id,value) SELECT '{$newTagset}',id,value FROM {$this->db}.tagset_values WHERE tagset='{$originTagset}'";
	$this->criticalQuery($qs);
	
	$qs = "INSERT INTO {$this->db}.tagsets (tagset,last_modified_by) VALUES ('{$newTagset}','{$_SESSION['user']}')";
	$this->criticalQuery($qs);
	$this->commitTransaction();

	$this->unlockTagset($newTagset);

    return true;
	
  }

  /** Lock a tagset for editing.
   *
   * Operates on a special lock table in the database. First deletes
   * all existing locks by the current user, then inserts a lock for
   * the current user and a given tagset. Note that if the tagset was
   * already locked by the current user, the operation will also
   * succeed.
   *
   * Should be called from JavaScript when a tagset is opened for
   * editing, and is also called by saveTagset() before performing any
   * modifications.
   *
   * @param string $tagset The short name of the tagset to be locked
   *
   * @return An @em array which minimally contains the key @c success,
   * which is set to @c true if the lock was successful. If set to @c
   * false, a key named @c lock contains further information about the
   * already-existing, conflicting lock.
   */
  public function lockTagset($tagset) {
    // first, delete all locks by the current user
    $qs = "DELETE FROM {$this->db}.tagset_locks "
      . "WHERE locked_by='{$_SESSION['user']}'";

    $this->query($qs);
    // then, try the lock
    $qs = "INSERT INTO {$this->db}.tagset_locks (tagset, locked_by) "
      . "VALUES ('{$tagset}', '{$_SESSION['user']}')";
    if (!$this->query($qs)) {
      // if lock failed, return info about user currently locking the file
      $qs = "SELECT locked_by, locked_since FROM {$this->db}.tagset_locks "
	. "WHERE tagset='{$tagset}'";
      $query = $this->query($qs);
      @$row = mysql_fetch_row( $query, $this->dbobj );
      return array("success" => false, "lock" => $row);
    }
    return array("success" => true);
  }

  /** Unlock a tagset.
   *
   * Unlocks a tagset locked with lockTagset(). Only succeeds if the
   * tagset was also locked by the current user.
   *
   * @param string $tagset The short name of the tagset to be unlocked
   *
   * @return The result of the corresponding @c mysql_query() command.
   */
  public function unlockTagset($tagset) {
    $qs = "DELETE FROM {$this->db}.tagset_locks "
      . "WHERE tagset='{$tagset}' AND locked_by='{$_SESSION['user']}'";
    return $this->query($qs);
  }

  /** Create a new user.
   *
   * @param string  $username The username to be created
   * @param string  $password The desired password
   * @param boolean $admin    Whether the user should have administrator status
   *
   * @return The result of the corresponding @c mysql_query() command.
   */ 
  public function createUser($username, $password, $admin) {
    $hashpw = $this->hashPassword($password);
    $adm = $admin ? 1 : 0;
    $qs = "INSERT INTO {$this->db}.users (name, password, admin) "
      . "VALUES ('{$username}', '{$hashpw}', '{$adm}')";
    return $this->query($qs);
  }

  /** Change the password for a user.
   *
   * @param string $username The username for which the password
   * should be changed.
   * @param string $password The new password
   *
   * @return The result of the corresponding @c mysql_query() command.
   */
  public function changePassword($username, $password) {
    $hashpw = $this->hashPassword($password);
    $qs = "UPDATE {$this->db}.users SET password='{$hashpw}' "
      . "WHERE name='{$username}'";
    return $this->query($qs);
  }

  /** Change project<->user associations.
   *
   * @param string $pid the project ID of the project to change
   * @param array $userlist an array of user names
   *
   * @return The result of the corresponding @c mysql_query() command
   */
  public function changeProjectUsers($pid, $userlist) {
    $this->startTransaction();
    $qs = "DELETE FROM {$this->db}.user2project WHERE project_id='".$pid."'";
    $message = "";
    $status = $this->query($qs);
    if ($status) {
      if (!empty($userlist)) {
	$qs = "INSERT INTO {$this->db}.user2project (project_id, user_id) VALUES ";
	$values = array();
	foreach ($userlist as $uname) {
	  $user = $this->getUserByName($uname);
	  $values[] = "('".$pid."', '".$user['id']."')";
	}
	$qs .= implode(", ", $values);
	$status = $this->query($qs);
      }
      if ($status) {
	$this->commitTransaction();
      } else {
	$message = mysql_error();
	$this->rollback();
      }
    }
    else {
      $message = mysql_error();
      $this->rollback();
    }
    return array('success' => $status, 'message' => $message);
  }

  /** Drop a user record from the database.
   *
   * @param string $username The username to be deleted
   *
   * @return The result of the corresponding @c mysql_query() command.
   */
  public function deleteUser($username) {
    $qs = "DELETE FROM {$this->db}.users WHERE name='{$username}' AND `id`!=1";
    return $this->query($qs);
  }

  /** Toggle administrator status of a user.
   *
   * @param string $username The username for which the administrator
   * status should be changed
   *
   * @return The result of the corresponding @c mysql_query() command.
   */
  public function toggleAdminStatus($username) {
    return $this->toggleUserStatus($username, 'admin');
  }

  /** Toggle visibility of normalization column for a user.
   *
   * @param string $username The username for which the status should
   * be changed
   *
   * @return The result of the corresponding @c mysql_query() command.
   */
  public function toggleNormStatus($username) {
    return $this->toggleUserStatus($username, 'normvisible');
  }

  /** Helper function for @c toggleAdminStatus() and @c toggleNormStatus(). */
  public function toggleUserStatus($username, $statusname) {
    $qs = "SELECT {$statusname} FROM {$this->db}.users WHERE name='{$username}'";
    $query = $this->query($qs);
    @$row = mysql_fetch_array($query, $this->dbobj);
    if (!$row)
      return false;
    $status = ($row[$statusname] == 1) ? 0 : 1;
    $qs = "UPDATE {$this->db}.users SET {$statusname}={$status} WHERE name='{$username}'";
    return $this->query($qs);
  }

  /** Find documents with a certain key/value pair in their metadata,
   *  e.g. documents with specific names or sigles.
   */
  public function queryForMetadata($metakey, $metavalue) {
    $qs  = "SELECT * FROM {$this->db}.files_metadata ";
    $qs .= "WHERE {$metakey}='{$metavalue}'";
    $query = $this->query($qs);
    @$row = mysql_fetch_array($query, $this->dbobj);
    return $row;
  }

  /** Insert a new document.
   *
   * @param array $options Metadata for the document
   * @param array $data Document data
   *
   * @return An error message if insertion failed, False otherwise
   */
  public function insertNewDocument(&$options, &$data){
    $this->startTransaction();

    // Insert metadata
    $metadata  = "INSERT INTO {$this->db}.files_metadata ";
    $metadata .= "(file_name, sigle, byUser, created, tagset, ext_id, ";
    $metadata .= "POS_tagged, morph_tagged, norm, project_id) VALUES ('";
    $metadata .= mysql_real_escape_string($options['name']) . "', '";
    $metadata .= mysql_real_escape_string($options['sigle']) . "', '";
    $metadata .= $_SESSION['user'] . "', CURRENT_TIMESTAMP, '";
    $metadata .= mysql_real_escape_string($options['tagset']) . "', '";
    $metadata .= mysql_real_escape_string($options['ext_id']) . "', ";
    $metadata .= $options['POS_tagged'] . ", ";
    $metadata .= $options['morph_tagged'] . ", ";
    $metadata .= $options['norm'] . ", ";
    $metadata .= $options['project'] . ")";

    if(!$this->query($metadata)){
      $errstr = "Fehler beim Schreiben in 'files_metadata':\n" .
	mysql_errno() . ": " . mysql_error() . "\n";
      $this->rollback();
      return $errstr;
    }

    $file_id = mysql_insert_id(); 
    $this->lockFile($file_id, "@@@system@@@");

    if(!empty($options['progress'])) {
      $this->markLastPosition($file_id,$options['progress'],'@@global@@');
    }

    // Insert data
    $data_head  = "INSERT INTO {$this->db}.files_data (file_id, line_id, ext_id,";
    $data_head .= "token, tag_POS, tag_morph, tag_norm, lemma, comment) VALUES";
    $data_table = new LongSQLQuery($this, $data_head, "");
    $sugg_head  = "INSERT INTO {$this->db}.files_tags_suggestion ";
    $sugg_head .= "(file_id, line_id, tag_suggestion_id, tagtype, ";
    $sugg_head .= "tag_name, tag_probability, lemma) VALUES"; 
    $sugg_table = new LongSQLQuery($this, $sugg_head, "");
    $err_head   = "INSERT INTO {$this->db}.files_errors ";
    $err_head  .= "(file_id, line_id, user, value) VALUES";
    $err_table  = new LongSQLQuery($this, $err_head, "");

    try {
      foreach($data as $index=>$token){
	$token = $this->escapeSQL($token);
	$qs = "('{$file_id}', {$index}, '".
	  mysql_real_escape_string($token['id'])."', '".
	  mysql_real_escape_string($token['form'])."', '".
	  mysql_real_escape_string($token['pos'])."', '".
	  mysql_real_escape_string($token['morph'])."', '".
	  mysql_real_escape_string($token['norm'])."', '".
	  mysql_real_escape_string($token['lemma'])."', '".
	  mysql_real_escape_string($token['comment'])."')";
	$status = $data_table->append($qs);
	if($status){ $this->rollback(); return $status; }
	foreach($token['suggestions'] as $sugg){
	  $qs = "('{$file_id}', {$index}, ".$sugg['index'].
	    ", '".$sugg['type']."', '".
	    mysql_real_escape_string($sugg['value']).
	    "', ".$sugg['score'].", '".
	    mysql_real_escape_string($token['lemma'])."')";
	  $status = $sugg_table->append($qs);
	  if($status){ $this->rollback(); return $status; }
	}
	if(!empty($token['error'])){
	  $qs = "('{$file_id}', {$index}, '@@global@@', '".
	    $token['error']."')";
	  $status = $err_table->append($qs);
	  if($status){ $this->rollback(); return $status; }
	}
      }
      $status = $data_table->flush();
      if($status){ $this->rollback(); return $status; }
      $status = $sugg_table->flush();
      if($status){ $this->rollback(); return $status; }
      $status = $err_table->flush();
      if($status){ $this->rollback(); return $status; }

      $this->unlockFile($file_id, "@@@system@@@");
      $this->commitTransaction();
    } catch (SQLQueryException $e) {
      $this->rollback();
      return "Fehler beim Importieren der Daten:\n" . $e . "\n";
    }
    
    return False;
  }

  /** Lock a file for editing.
   *
   * This function ensures restricted access when editing file data. In detail this is done by
   * a lock table where first all entries for the given user are deleted and then tries to insert
   * a new entry for the given file id. Note that if the file is already locked by another user,
   * the operation will fail und it is impossible to lock the file at the moment.
   *
   * Should be called before editing any database entries concerning the file content.
   *
   * $locksCount indicates the number of file which were locked by the given user and are unlocked now.
   *
   * @param string $fileid file id
   * @param string $user username
   *
   * @return An @em array which minimally contains the key @c success,
   * which is set to @c true if the lock was successful. If set to @c
   * false, a key named @c lock contains further information about the
   * already-existing, conflicting lock.
   */	  
  public function lockFile($fileid,$user) {
	    // first, delete all locks by the current user
	    $qs = "DELETE FROM {$this->db}.files_locked WHERE locked_by='{$user}'";
	    $this->query($qs);
		$locksCount = mysql_affected_rows();
	    // then, try the lock
	    $qs = "INSERT INTO {$this->db}.files_locked (file_id, locked_by) VALUES ('{$fileid}', '{$user}')";
	    if (!$this->query($qs)) {
	      // if lock failed, return info about user currently locking the file
	      $qs = "SELECT locked_by, locked_since FROM {$this->db}.files_locked WHERE file_id='{$fileid}'";
	      $query = $this->query($qs);
	      @$row = mysql_fetch_row( $query, $this->dbobj );
	      return array("success" => false, "lock" => $row);
	    }
	    return array("success" => true, "lockCounts" => (string) $locksCount);
	  }

	/** Get locked file for given user.
	*
	* Retrieves the lock entry for a given user from the file lock table.
	*
	* @param string $user username
	*
	* @return An @em array with file id and file name of the locked file
  	*/	
	public function getLockedFiles($user){
		$qs = "	SELECT a.file_id, b.file_name FROM {$this->db}.files_locked a, {$this->db}.files_metadata b 
				WHERE locked_by='{$user}' AND a.file_id=b.file_id 
				LIMIT 1";
		return @mysql_fetch_row($this->query( $qs ));
	}

	/** Unlock a file for the current user.
	*
	* Deletes the lock entry for the current user from the file lock table.
	*
	* @todo move SESSION data out of this function
	*
	* @param string $fileid file id
	* @param string $user username (defaults to current session user)
	*
	* @return @em array result of the mysql query
  	*/		
	public function unlockFile($fileid,$user="",$force=false) {
	  if (empty($user)) {
	    $user=$_SESSION['user'];
	  }
	  if ($force) {
	    $qs = "DELETE FROM {$this->db}.files_locked WHERE file_id='{$fileid}'";
	  } else {
	    $qs = "DELETE FROM {$this->db}.files_locked WHERE file_id='{$fileid}' AND locked_by='{$user}'";
	  }
	  return $this->query($qs);
	}

	/** Open a file.
	*
	* Retrieves metadata and users progress data for the given file.
	*
	* @todo move SESSION data out of this function
	*
	* @param string $fileid file id
	*
	* @return an @em array with at least the file meta data. If exists, the user's last edited row is also transmitted.
  	*/		
	public function openFile($fileid){
	  $locked = $this->lockFile($fileid, $_SESSION["user"]);
	  if(!$locked['success']) {
	    return array('success' => false);
	  }

		$qs = "SELECT * FROM {$this->db}.files_metadata WHERE file_id='{$fileid}'";
		if($query = $this->query($qs)){
			$qs = "SELECT new_line_id FROM {$this->db}.files_progress WHERE file_id='{$fileid}' AND user='@@global@@'";
			if($q = $this->query($qs)){
				$row = @mysql_fetch_row($q,$this->dbobj);
				$lock['lastEditedRow'] = $row[0];
			} else {
				$lock['lastEditedRow'] = -1;
			}
			$lock['data'] = @mysql_fetch_assoc($query);
			$lock['success'] = true;
		} else {
			$lock['success'] = false;
		}

		return $lock;		
	}

	/** Check whether a user is allowed to open a file.
	 *
	 * @param string $fileid file id
	 * @param string $user username
	 *
	 * @return boolean value indicating whether user may open the
	 *         file
	 */
	public function isAllowedToOpenFile($fileid, $user){
	  $qs = "SELECT a.project_id FROM ({$this->db}.project_users a, {$this->db}.files_metadata b) WHERE (a.username='{$user}' AND b.file_id='{$fileid}' AND a.project_id=b.project_id)";
	  $q = $this->query($qs);
	  if(@mysql_fetch_row($q,$this->dbobj)) {
	    return true;
	  } else {
	    return false;
	  }
	}

	/** Check whether a user is allowed to delete a file.
	 *
	 * @param string $fileid file id
	 * @param string $user username
	 *
	 * @return boolean value indicating whether user may delete the
	 *         file
	 */
	public function isAllowedToDeleteFile($fileid, $user){
	  return $this->isAllowedToOpenFile($fileid, $user);
	}

	/** Delete a file.
	*
	* Deletes ALL database entries linked with the given file id.
	*
	* @param string $fileid file id
	*
	* @return bool @c true 
  	*/	
	public function deleteFile($fileid){
	  $this->startTransaction();
	  $this->lockFile($fileid, "@@@system@@@");

		$qs = "	DELETE FROM {$this->db}.files_metadata WHERE file_id='{$fileid}'";							 
		if(!$this->query($qs)) { $this->rollback(); return "Query for metadata failed."; }

		$qs = "	DELETE FROM {$this->db}.files_tags_suggestion WHERE file_id='{$fileid}'";							 
		if(!$this->query($qs)) { $this->rollback(); return "Query for tags_suggestion failed."; }

		$qs = "	DELETE FROM {$this->db}.files_data WHERE file_id='{$fileid}'";
		if(!$this->query($qs)) { $this->rollback(); return "Query for data failed."; }
		
		$qs = "DELETE FROM {$this->db}.files_errors WHERE file_id='{$fileid}'";
		if(!$this->query($qs)) { $this->rollback(); return "Query for errors failed."; }

		$qs = "DELETE FROM {$this->db}.files_progress WHERE file_id='{$fileid}'";
		if(!$this->query($qs)) { $this->rollback(); return "Query for progress failed."; }
		
		$qs = "DELETE FROM {$this->db}.files_locked WHERE file_id='{$fileid}'";
		$this->query($qs);

	    $this->commitTransaction();

	    return false;
	}

	/** Get a list of all files.
	*
	* Retrieves meta information such as filename, created by
	* user, locked by user, etc for all files.  Should only be
	* called for administrators; otherwise, use @c
	* getFilesForUser() function.
	*
	* @return an two-dimensional @em array with the meta data
  	*/		
	public function getFiles(){
	  $qs = "SELECT a.*, d.id as project_id, d.name as project_name, "
	    . "c.name as opened, e.name as creator_name, f.name as changer_name "
	    . "     FROM {$this->db}.text a "
	    . "LEFT JOIN {$this->db}.locks b ON a.id=b.text_id "
	    . "LEFT JOIN {$this->db}.users c ON b.user_id=c.id "
	    . "LEFT JOIN {$this->db}.project d ON a.project_id=d.id "
	    . "LEFT JOIN {$this->db}.users e ON a.creator_id=e.id "
	    . "LEFT JOIN {$this->db}.users f ON a.changer_id=f.id "
	    . "ORDER BY sigle, fullname";
	    $query = $this->query($qs); 
		$files = array();
	    while ( @$row = mysql_fetch_array( $query, $this->dbobj ) ) {
	      $files[] = $row;
	    }
	    return $files;
	}
	
	/** Get a list of all files in projects accessible by a given
	 * user.
	*
	* Retrieves meta information such as filename, created by
	* user, locked by user, etc for all files.
	*
	* @param string $user username
	* @return an two-dimensional @em array with the meta data
  	*/		
	public function getFilesForUser($uname){
	  $user = $this->getUserByName($uname);
	  $uid = $user["id"];
	  $qs = "SELECT a.*, d.id as project_id, d.name as project_name, "
	    . "c.name as opened, e.name as creator_name, f.name as changer_name "
	    . "    FROM ({$this->db}.text a, {$this->db}.user2project g) "
	    . "LEFT JOIN {$this->db}.locks b ON a.id=b.text_id "
	    . "LEFT JOIN {$this->db}.users c ON b.user_id=c.id "
	    . "LEFT JOIN {$this->db}.project d ON a.project_id=d.id "
	    . "LEFT JOIN {$this->db}.users e ON a.creator_id=e.id "
	    . "LEFT JOIN {$this->db}.users f ON a.changer_id=f.id "
	    . "WHERE (a.project_id=g.project_id AND g.user_id={$uid}) "
	    . "ORDER BY sigle, fullname";
	    $query = $this->query($qs); 
	    $files = array();
	    while ( @$row = mysql_fetch_array( $query, $this->dbobj ) ) {
			$files[] = $row;
	    }
	    return $files;
	}
	
	/** Get a list of all projects.  
	 *
	 * Should only be called for administrators; otherwise, use @c
	 * getProjectsForUser() function.
	 *
	 * @param string $user username
	 * @return a two-dimensional @em array with the project id and name
	 */
	public function getProjects(){
	  $qs = "SELECT * FROM {$this->db}.project ORDER BY name";
	  $query = $this->query($qs); 
	  $projects = array();
	  while ( @$row = mysql_fetch_array( $query, $this->dbobj ) ) {
	    $projects[] = $row;
	  }
	  return $projects;
	}

	/** Get a list of all project user groups.  
	 *
	 * Should only be called for administrators.
	 *
	 * @return a two-dimensional @em array with the project id and name
	 */
	public function getProjectUsers(){
	  $qs = "SELECT * FROM {$this->db}.user2project";
	  $query = $this->query($qs); 
	  $projects = array();
	  while ( @$row = mysql_fetch_array( $query, $this->dbobj ) ) {
	    $user = $this->getUserById($row["user_id"]);
	    $projects[] = array("project_id" => $row["project_id"],
				"username" => $user["name"]);
	  }
	  return $projects;
	}

	/** Get a list of all projects accessible by a given user.
	 *
	 * @param string $user username
	 * @return a two-dimensional @em array with the project id and name
	 */
	public function getProjectsForUser($uname){
	  $user = $this->getUserByName($uname);
	  $uid  = $user["id"];
	  $qs = "SELECT a.* FROM ({$this->db}.project a, {$this->db}.user2project b) WHERE (a.id=b.project_id AND b.user_id='{$uid}') ORDER BY project_id";
	  $query = $this->query($qs); 
	  $projects = array();
	  while ( @$row = mysql_fetch_array( $query, $this->dbobj ) ) {
	    $projects[] = $row;
	  }
	  return $projects;
	}

	/** Create a new project.
	 *
	 * @param string $name project name
	 * @return the project ID of the newly generated project
	 */
	public function createProject($name){
	  $qs = "INSERT INTO {$this->db}.project (`name`) VALUES ('{$name}')";
	  $query = $this->query($qs);
	  return mysql_insert_id();
	}

	/** Deletes a project.  Will fail unless no document is
	 * assigned to the project.
	 *
	 * @param string $pid the project id
	 * @return a boolean value indicating success
	 */
	public function deleteProject($pid){
	  $qs = "DELETE FROM {$this->db}.project WHERE `id`={$pid}";
	  if($this->query($qs)) {
	    return True;
	  }
	  return False;
	}

	/** Save settings for given user.
	*
	* @param string $user username
	* @param string $lpp number of lines per page
	* @param string $cl number of context lines
	*
	* @return bool result of the mysql query
  	*/			
	public function setUserSettings($user,$lpp,$cl){
		$qs = "UPDATE {$this->db}.users SET lines_per_page={$lpp},lines_context={$cl} WHERE name='{$user}' AND `id`!=1";
		return $this->query($qs);
	}

	/** Save a setting for given user.
	 *
	 * @param string $user username
	 * @param string $name name of the setting, e.g. "contextLines"
	 * @param string $value new value of the setting
	 *
	 * @return bool result of the mysql query
	 */
	public function setUserSetting($user,$name,$value) {
	  $validnames = array("lines_context", "lines_per_page", "show_error",
			      "columns_order", "columns_hidden");
	  if (in_array($name,$validnames)) {
	       	$qs = "UPDATE {$this->db}.users SET {$name}='{$value}' WHERE name='{$user}' AND `id`!=1";
		return $this->query($qs);
	    }
	  return false;
	}

	
	/** Return the total number of lines of a given file.
	 *
	 * @param string $fileid the ID of the file
	 *
	 * @return The number of lines for the given file
	 */
	public function getMaxLinesNo($fileid){
		$qs = "SELECT COUNT(line_id) FROM {$this->db}.files_data WHERE file_id='{$fileid}'";
		if($query = $this->query($qs)){
			$row = mysql_fetch_row($query);
			return $row[0];
		}		

		return 0;
	}
	
	/** Retrieve all tokens from a file.
	*
	* This function is called from the @c addData function.
	*
	* @param string $fileid the file id
	*
	* @return an @em array containing all tokens
 	*/	
	public function getToken($fileid){
		$qs = "SELECT token FROM {$this->db}.files_data WHERE file_id='{$fileid}'";
		$query = $this->query($qs);
		$data = array();
		while($row = @mysql_fetch_row($query,$this->dbobj)){
			$data[] = $row['token'];
		}
		return $data;
		
	}
	
	/** Retrieve all lines from a file, including error data,
	 *  but not tagger suggestions.
	 */
	public function getAllLines($fileid){
	  $qs = "SELECT a.*, b.value AS 'errorChk' FROM {$this->db}.files_data a LEFT JOIN {$this->db}.files_errors b ON (a.file_id=b.file_id AND a.line_id=b.line_id) WHERE a.file_id='{$fileid}' ORDER BY line_id";
	  $query = $this->query($qs);
	  $data = array();
	  while($row = @mysql_fetch_assoc($query,$this->dbobj)){
	    $data[] = $row;
	  }
	  return $data;
	}

	/** Retrieve all tagger suggestions for a line in a file. */
	public function getAllSuggestions($fileid, $lineid){
	  $qs = "SELECT * FROM {$this->db}.files_tags_suggestion WHERE file_id='{$fileid}' AND line_id='{$lineid}' ORDER BY tagtype DESC";
	  $q = $this->query($qs);

	  $tag_suggestions = array();
	  while($row = @mysql_fetch_assoc($q)){
	    $tag_suggestions[] = $row;
	  }
	  return $tag_suggestions;
	}

	/** Retrieves a specified number of lines from a file.
	*
	* @param string $fileid the file id
	* @param string $start line id of the first line to be retrieved
	* @param string $lim numbers of lines to be retrieved
	*
	* @return an @em array containing the lines
 	*/ 	
	public function getLines($fileid,$start,$lim){		
		// $qs = "SELECT * FROM {$this->db}.files_data WHERE file_id='{$fileid}'";
		$qs = "	SELECT a.*, b.value AS 'errorChk'
				FROM {$this->db}.files_data a LEFT JOIN {$this->db}.files_errors b ON (a.line_id=b.line_id AND a.file_id=b.file_id)
				WHERE a.file_id='{$fileid}'";
		// if($lim != 0)
		$qs .= " LIMIT {$start},{$lim}";
			
		$data = array();

		$query = $this->query($qs); 		
		while($line = @mysql_fetch_array($query)){			
			$qs = "	SELECT tag_name,tag_probability,tag_suggestion_id FROM {$this->db}.files_tags_suggestion
					WHERE file_id='{$fileid}' AND line_id='{$line['line_id']}' AND tagtype='pos' ORDER BY tag_probability";
			$q = $this->query($qs);

			$tag_suggestions_pos = array();
			while($row = @mysql_fetch_assoc($q)){
				$tag_suggestions_pos[] = $row;
			}

			$qs = "	SELECT tag_name,tag_probability,tag_suggestion_id FROM {$this->db}.files_tags_suggestion
					WHERE file_id='{$fileid}' AND line_id='{$line['line_id']}' AND tagtype='morph' ORDER BY tag_probability";
			$q = $this->query($qs);

			$tag_suggestions_morph = array();
			while($row = @mysql_fetch_assoc($q)){
				$tag_suggestions_morph[] = $row;
			}

			$data[$line['line_id']] = array_merge($line,array("suggestions_pos"=>$tag_suggestions_pos, "suggestions_morph"=>$tag_suggestions_morph));
		}
        
		
		return $data;
	    
	}

	/** Saves changed lines.
	*
	* This function is called from the session handler during the
	* saving process.  
	* 
	* @param string $fileid the file id
	*
	* @return @bool the result of the mysql query
 	*/ 		
	public function saveLines($fileid,$lasteditedrow,$lines) {
	  $locked = $this->lockFile($fileid, $_SESSION["user"]);
	  if(!$locked['success']) {
	    return "lock failed";
	  }

	  $this->startTransaction();

	  // data insertion query
	  $qhead  = "INSERT INTO {$this->db}.files_data (file_id, line_id, ";
	  $qhead .= "lemma, tag_POS, tag_morph, tag_norm, comment) VALUES";
	  $qtail  = "ON DUPLICATE KEY UPDATE lemma=VALUES(lemma), ";
	  $qtail .= "tag_POS=VALUES(tag_POS), tag_morph=VALUES(tag_morph), ";
	  $qtail .= "tag_norm=VALUES(tag_norm), comment=VALUES(comment)";
	  $query  = new LongSQLQuery($this, $qhead, $qtail);
	  // error highlighting query
	  $eonhd  = "INSERT INTO {$this->db}.files_errors (file_id, ";
	  $eonhd .= "line_id, user, value) VALUES";
	  $eontl  = "ON DUPLICATE KEY UPDATE user='@@global@@'";
	  $erron  = new LongSQLQuery($this, $eonhd, $eontl);
	  // error un-highlighting query
	  $eofhd  = "DELETE FROM {$this->db}.files_errors WHERE ";
	  $eofhd .= "file_id='{$fileid}' AND line_id IN (";
	  $eoftl  = ")";
	  $erroff = new LongSQLQuery($this, $eofhd, $eoftl);
	  
	  // build and perform the queries!
	  try {
	    foreach ($lines as $line) {
	      $qs  = "('{$fileid}', '".$line["line_id"]."', '".$line["lemma"];
	      $qs .= "', '".$line["tag_POS"]."', '".$line["tag_morph"]."', '";
	      $qs .= $line["tag_norm"]."', '".$line["comment"]."')";
	      $query->append($qs);
	      if($line["errorChk"]=="0" || $line["errorChk"]==null) {
		$erroff->append($line["line_id"]);
	      } else {
		$erron->append("('{$fileid}', '".$line["line_id"]."', '@@global@@', '".
			       $line["errorChk"]."')");
	      }
	    }
	    $query->flush();
	    $erron->flush();
	    $erroff->flush();
	    $this->commitTransaction();
	  } catch (SQLQueryException $e) {
	    $this->rollback();
	    return $e->getMessage();
	  }

	  // finally, one last query...
	  $result = $this->markLastPosition($fileid,$lasteditedrow,'@@global@@');
	  if (!$result) {
	    return mysql_errno().": ".mysql_error();
	  }

	  return False;
	}

   
	/** Save progress on the given file.
	*
	* Progress is shown by a green bar at the left side of the editor and indicates the last line for which changes have been made.
	*
	* CAUTION: This no longer works on a per-user, but rather on a per-file basis.
	*
	* This function is called by the session handler during the saving process.
	* 
	* @param string $file the file id
	* @param string $line the line id
	* @param string $user the username (currently not used)
	*
	* @return bool the result of the mysql query
 	*/
	public function markLastPosition($file,$line,$user){
		$qs = "INSERT INTO {$this->db}.files_progress (file_id,new_line_id,user) VALUES ('{$file}','{$line}','@@global@@') ON DUPLICATE KEY UPDATE old_line_id=new_line_id,new_line_id='{$line}'";
		return $this->query( $qs );
	}

	/** Get the highest tag id from an given tagset.
	*
	* Retrieves the maximal id of stored tags from a given tagset.
	*
	* This function is called from Java Script when a user adds tags to an tagset
	* after tag unmatches at a file import.
	* 
	* @param string $tagset the tagset
	*
	* @return string the maximal tag id
 	*/
	public function getHighestTagId($tagset){
		$qs = "SELECT max(id) FROM {$this->db}.tagset_tags WHERE tagset='{$tagset}'";
		$row = @mysql_fetch_array($this->query($qs));
		return $row[0];
	}



}


?>
