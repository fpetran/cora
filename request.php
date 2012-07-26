<?php

/** @file request.php
 * Handle requests sent via JavaScript.
 *
 * This file is intended to be called from JavaScript code to perform
 * database requests.  It will usually pass them on to a
 * RequestHandler object, which then typically outputs a JSON object
 * string (depending on the nature of the request).
 */

session_name("PHPSESSID_CORA");

require_once( "lib/globals.php" );
require_once( "lib/sessionHandler.php" );
require_once( "lib/requestHandler.php" );

$sh = new SessionHandler();     /**< An instance of the SessionHandler object. */
$rq = new RequestHandler($sh);  /**< An instance of the RequestHandler object. */
$lang = $sh->getLanguageArray();

if($_SESSION["loggedIn"]) {
  $rq->handleJSONRequest( $_GET, $_POST );
}

?>