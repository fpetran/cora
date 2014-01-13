<?php

/** @file xmlHandler.php
 * Import and export of files as XML data.
 *
 * @author Marcel Bollmann
 * @date May 2012, updated February 2013
 */

require_once( "documentModel.php" );


class XMLHandler {

  private $db; /**< A DBInterface object. */
  private $output_suggestions; /**< Boolean indicating whether to output tagger suggestions. */
  private $xml_header_options; /**< Valid attributes for the XML <header> tag. */

  function __construct($db) {
    $this->db = $db;
    $this->output_suggestions = true;
    $this->xml_header_options = array('sigle','name','tagset','progress');
  }

  /****** FUNCTIONS RELATED TO DATA IMPORT ******/

  private function setOptionsFromHeader(&$header, &$options) {
    // get header attributes if they are not already set in $options
    foreach($this->xml_header_options as $key) {
      if (isset($header[$key]) && !empty($header[$key])
	  && (!isset($options[$key]) || empty($options[$key]))) {
	$options[$key] = (string) $header[$key];
      }
    }
  }

  /** Process header information. */
  private function processXMLHeader(&$reader, &$options) {
    $doc = new DOMDocument();
    while ($reader->read()) {
      if ($reader->name == 'text') {
	$header = simplexml_import_dom($doc->importNode($reader->expand(), true));
	if (isset($header['id']) && !empty($header['id'])
	    && (!isset($options['ext_id']) || empty($options['ext_id']))) {
	  $options['ext_id'] = (string) $header['id'];
	}
	return False;
      }
    }
    return "XML-Format nicht erkannt: <text>-Tag nicht gefunden.";
  }

  /** Parses a range string ("t1..t4" or just "t1") into an array
      containing beginning and end of the range. */
  private function parseRange($range) {
    $x = explode("..", $range);
    $start = $x[0];
    $end = (isset($x[1]) ? $x[1] : $x[0]);
    return array($start, $end);
  }

  /** Process layout information. */
  private function processLayoutInformation(&$node, &$document) {
    $pages = array();
    $columns = array();
    $lines = array();
    $pagecount = 0;
    $colcount = 0;
    $linecount = 0;
    // pages
    foreach($node->page as $pagenode) {
      $page = array();
      $page['xml_id'] = (string) $pagenode['id'];
      $page['side']   = (string) $pagenode['side'];
      $page['name']   = (string) $pagenode['no'];
      $page['num']    = ++$pagecount;
      $page['range']  = $this->parseRange((string) $pagenode['range']);
      $pages[] = $page;
    }
    // columns
    foreach($node->column as $colnode) {
      $column = array();
      $column['xml_id'] = (string) $colnode['id'];
      $column['name']   = (string) $colnode['name'];
      $column['num']    = ++$colcount;
      $column['range']  = $this->parseRange((string) $colnode['range']);
      $columns[] = $column;
    }
    // lines
    foreach($node->line as $linenode) {
      $line = array();
      $line['xml_id'] = (string) $linenode['id'];
      $line['name']   = (string) $linenode['name'];
      $line['num']    = ++$linecount;
      $line['range']  = $this->parseRange((string) $linenode['range']);
      $lines[] = $line;
    }

    $document->setLayoutInfo($pages, $columns, $lines);
  }

  /** Process shift tag information. */
  private function processShiftTags(&$node, &$document) {
    $shifttags = array();
    $type_to_letter = array("rub" => "R",
			    "title" => "T",
			    "lat" => "L",
			    "marg" => "M",
			    "fm" => "F");
    foreach($node->children() as $tagnode) {
      $shifttag = array();
      $shifttag['type'] = (string) $tagnode->getName();
      $shifttag['type_letter'] = $type_to_letter[$shifttag['type']];
      $shifttag['range'] = $this->parseRange((string) $tagnode['range']);
      $shifttags[] = $shifttag;
    }
    $document->setShiftTags($shifttags);
  }

  private function processToken(&$node, &$tokcount, &$t, &$d, &$m) {
    $token = array();
    $thistokid       = (string) $node['id'];
    $token['xml_id'] = (string) $node['id'];
    $token['trans']  = (string) $node['trans'];
    $token['ordnr']  = $tokcount;
    $t[] = $token;
    // diplomatic tokens
    foreach($node->dipl as $diplnode) {
      $dipl = array();
      $dipl['xml_id'] = (string) $diplnode['id'];
      $dipl['trans']  = (string) $diplnode['trans'];
      $dipl['utf']    = (string) $diplnode['utf'];
      $dipl['parent_tok_xml_id'] = $thistokid;
      $d[] = $dipl;
    }
    // modern tokens
    foreach($node->mod as $modnode) {
      $modern = array('tags' => array());
      $modern['xml_id'] = (string) $modnode['id'];
      $modern['trans']  = (string) $modnode['trans'];
      $modern['ascii']  = (string) $modnode['ascii'];
      $modern['utf']    = (string) $modnode['utf'];
      $modern['parent_xml_id'] = $thistokid;
      // first, parse all automatic suggestions
      if($modnode->suggestions) {
	foreach($modnode->suggestions->children() as $suggnode) {
	  $sugg = array('source' => 'auto', 'selected' => 0);
	  $sugg['type']   = $suggnode->getName();
	  $sugg['tag']    = (string) $suggnode['tag'];
	  $sugg['score']  = (string) $suggnode['score'];
	  $modern['tags'][] = $sugg;
	}
      }
      // then, parse all selected annotations
      foreach($modnode->children() as $annonode) {
	$annotype = $annonode->getName();
	if($annotype=='cora-comment') {
	  $modern['comment'] = (string) $annonode;
	}
	else if($annotype!=='suggestions') {
	  $annotag  = (string) $annonode['tag'];
	  $found = false;
	  // loop over all suggestions to check whether the annotation
	  // is included there, and if so, select it
	  foreach($modern['tags'] as &$sugg) {
	    if($sugg['type']==$annotype && $sugg['tag']==$annotag) {
	      $sugg['selected'] = 1;
	      $found = true;
	      break;
	    }
	  }
	  unset($sugg);
	  // if it is not, create a new entry for it
	  if(!$found) {
	    $sugg = array('source' => 'user', 'selected' => 1, 'score' => null);
	    $sugg['type'] = $annotype;
	    $sugg['tag']  = $annotag;
	    $modern['tags'][] = $sugg;
	  }
	}
      }
      $m[] = $modern;
    }
    return $thistokid;
  }

  /** Process XML data. */
  private function processXMLData(&$reader, &$options) {
    $doc = new DOMDocument();
    $document = new CoraDocument($options);

    $tokens  = array();
    $dipls   = array();
    $moderns = array();

    $token['xml_id'] = "t0";
    $token['trans']  = "";
    $token['ordnr']  = 1;
    $tokens[] = $token;
    $tokcount = 1;
    $lasttokid = "t0";

    while ($reader->read()) {
      // only handle opening tags
      if ($reader->nodeType!==XMLReader::ELEMENT) { continue; }

      $node = simplexml_import_dom($doc->importNode($reader->expand(), true));
      if ($reader->name == 'cora-header') {
	$this->setOptionsFromHeader($node, $options);
      }
      else if ($reader->name == 'header') {
	$document->setHeader(trim((string)$node));
      }
      else if ($reader->name == 'layoutinfo') {
	$this->processLayoutInformation($node, $document);
      }
      else if ($reader->name == 'shifttags') {
	$this->processShiftTags($node, $document);
      }
      else if ($reader->name == 'comment') {
	$document->addComment(null, $lasttokid, (string) $node, (string) $node['type']);
      }
      else if ($reader->name == 'token') {
        ++$tokcount;
	$lasttokid = $this->processToken($node, $tokcount, $tokens, $dipls, $moderns);
      }
    }

    $document->setTokens($tokens, $dipls, $moderns);
    $document->mapRangesToIDs();
    return $document;
  }

  /** Check if data should be considered normalized, POS-tagged,
   *  and/or morph-tagged; and, if tags are present, whether they
   *  conform to the chosen tagset.
   */
  private function checkIntegrity(&$options, &$data) {
    $warnings = array();

    return $warnings;
  }

  /** Import XML data into the database as a new document.
   *
   * Parses XML data and sends database queries to import the data.
   * Data will be imported as a new document; adding information to an
   * already existing document is not (yet) supported.
   *
   * @param string $xmlfile Name of a file containing XML data to
   * import; typically a temporary file generated from user-uploaded
   * data
   * @param array $options Array containing metadata (e.g. sigle,
   * name, tagset) for the document; if there is a conflict with
   * the same type of data being supplied in the XML file,
   * the @c $options array takes precedence
   */
  public function import($xmlfile, $options) {
    // check for validity
    libxml_use_internal_errors(true);
    $doc = new DOMDocument('1.0', 'utf-8');
    $doc->loadXML(file_get_contents($xmlfile['tmp_name']));
    $errors = libxml_get_errors();
    if (!empty($errors) && $errors[0]->level > 0) {
      $message  = "Datei enthält kein wohlgeformtes XML. Parser meldete:\n";
      $message .= $errors[0]->message.' at line '.$errors[0]->line.'.';
      return array("success"=>False, "errors"=>array("XML-Fehler: ".$message));
    }

    // process XML
    $reader = new XMLReader();
    if(!$reader->open($xmlfile['tmp_name'])) {
      return array("success"=>False,
		   "errors"=>array("Interner Fehler: Konnte temporäre Datei '".$xmlfile['tmp_name']."' nicht öffnen."));
    }
    $format = '';
    $xmlerror = $this->processXMLHeader($reader, $options, $format);
    if($xmlerror){
      $reader->close();
      return array("success"=>False, 
		   "errors"=>array($xmlerror));
    }
    try {
      $data = $this->processXMLData($reader, $options, $format);
    }
    catch (DocumentValueException $e) {
      $reader->close();
      return array("success"=>False,
		   "errors"=>array($e->getMessage()));
    }
    $reader->close();

    // check for data integrity
    $warnings = $this->checkIntegrity($options, $data);
    if(!(isset($options['name']) && !empty($options['name'])) &&
       !(isset($options['sigle']) && !empty($options['sigle']))) {
      array_unshift($warnings, "Dokument hat weder Name noch Sigle; benutze Dateiname als Dokumentname.");
      $options['name'] = $xmlfile['name'];
    }
    if(!(isset($options['ext_id']) && !empty($options['ext_id']))) {
      $options['ext_id'] = '';
    }

    // insert data into database
    $sqlerror = $this->db->insertNewDocument($options, $data);
    if($sqlerror){
      return array("success"=>False, 
		   "errors"=>array("SQLError: ".$sqlerror));
    }

    return array("success"=>True, "warnings"=>$warnings);
  }

  /****** FUNCTIONS RELATED TO DATA EXPORT ******/
  
  // currently none

}

?>