<?php
/**
 * OAI-PMH Harvesting Class
 *
 * Based on harvest-oai.php in VuFind
 *
 * PHP version 5
 *
 * Copyright (c) Demian Katz 2010.
 * Copyright (c) Ere Maijala 2011-2012.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */

require_once 'HTTP/Request2.php';

/**
 * HarvestOaiPmh Class
 *
 * This class harvests records via OAI-PMH using settings from datasources.ini.
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */
class HarvestOaiPmh
{
    protected $log;                   // Logger
    protected $db;                    // Mongo database
    protected $baseURL;               // URL to harvest from
    protected $set = null;            // Set to harvest (null for all records)
    protected $metadata = 'oai_dc';   // Metadata type to harvest
    protected $idPrefix = '';         // OAI prefix to strip from ID values
    protected $idSearch = array();    // Regular expression searches
    protected $idReplace = array();   // Replacements for regular expression matches
    protected $source;                // Source ID
    protected $startDate = null;      // Harvest start date (null for all records)
    protected $endDate = null;      // Harvest end date (null for all records)
    protected $granularity = 'auto';  // Date granularity
    protected $verbose = false;       // Whether to display debug output
    protected $deletedRecords = 0;    // Harvested deleted record count
    protected $debugLog = '';         // File where to dump OAI requests and responses for debugging
    protected $childPid = null;       // Child process id for record processing
    protected $resumptionToken = '';  // Override the first harvest request
    protected $transformation = null; // Transformation applied to the OAI-PMH responses before processing
    protected $serverDate = null;     // Date received from server via Identify command. Used to set the last harvest date

    /**
     * Constructor.
     *
     * @param object $logger     The Logger object used for logging messages.
     * @param object $db         Mongo database handle.
     * @param string $source     The data source to be harvested.
     * @param string $basePath   RecordManager main directory location 
     * @param array  $settings   Settings from datasources.ini.
     * @param string $startToken Optional override for the initial
     *                           harvest command (to resume interrupted harvesting)
     *
     * @access public
     */
    public function __construct($logger, $db, $source, $basePath, $settings, $startToken = '')
    {
        $this->log = $logger;
        $this->db = $db;
         
        // Don't time out during harvest
        set_time_limit(0);

        // Check if we have a start date
        $this->source = $source;
        $this->loadHarvestDate();

         $this->resumptionToken = $startToken;

        // Set up base URL:
        if (empty($settings['url'])) {
            throw new Exception("Missing base URL for {$source}");
        }
        $this->baseURL = $settings['url'];
        if (isset($settings['set'])) {
            $this->set = $settings['set'];
        }
        if (isset($settings['metadataPrefix'])) {
            $this->metadata = $settings['metadataPrefix'];
        }
        if (isset($settings['idPrefix'])) {
            $this->idPrefix = $settings['idPrefix'];
        }
        if (isset($settings['idSearch'])) {
            $this->idSearch = $settings['idSearch'];
        }
        if (isset($settings['idReplace'])) {
            $this->idReplace = $settings['idReplace'];
        }
        if (isset($settings['dateGranularity'])) {
            $this->granularity = $settings['dateGranularity'];
        }
        if (isset($settings['verbose'])) {
            $this->verbose = $settings['verbose'];
        }
        if (isset($settings['debuglog'])) {
            $this->debugLog = $settings['debuglog'];
        }
        if (isset($settings['oaipmhTransformation'])) {
            $style = new DOMDocument();
            if ($style->load("$basePath/transformations/". $settings['oaipmhTransformation']) === false) {
                throw new Exception("Could not load $basePath/transformations/" . $settings['oaipmhTransformation']);
            }
            $this->transformation = new XSLTProcessor();
            $this->transformation->importStylesheet($style);
        }
        
        $this->message('Identifying server');
        $response = $this->sendRequest('Identify');
        if ($this->granularity == 'auto') {
            $this->granularity = $this->getSingleNode($this->getSingleNode($response, 'Identify'), 'granularity')->nodeValue;
            $this->message("Detected date granularity: {$this->granularity}");
        }
        $this->serverDate = $this->normalizeDate($this->getSingleNode($response, 'responseDate')->nodeValue);
        $this->message('Current server date: ' . date('Y-m-d\TH:i:s\Z', $this->serverDate));
    }

    /**
     * Set a start date for the harvest (only harvest records AFTER this date).
     *
     * @param string $date Start date (YYYY-MM-DD format).
     *
     * @return void
     * @access public
     */
    public function setStartDate($date)
    {
        $this->startDate = $date;
    }

    /**
     * Set an end date for the harvest (only harvest records BEFORE this date).
     *
     * @param string $date End date (YYYY-MM-DD format).
     *
     * @return void
     * @access public
     */
    public function setEndDate($date)
    {
        $this->endDate = $date;
    }

    /**
     * Harvest all available documents.
     *
     * @param functionref $callback Function to be called to store a harvested record
     * 
     * @return void
     * @access public
     */
    public function harvest($callback)
    {
        $this->_normalRecords = 0;
        $this->deletedRecords = 0;
        $this->_callback = $callback;

        if ($this->resumptionToken) {
            $this->message('Incremental harvest from given resumptionToken');
            $token = $this->getRecordsByToken($this->resumptionToken);
        } else {
            // Start harvesting at the requested date:
            if (!empty($this->startDate)) {
                $this->message('Incremental harvest from timestamp ' . $this->startDate);
            } else {
                $this->message('Initial harvest for all records');
            }
            $token = $this->getRecordsByDate();
        }

        // Keep harvesting as long as a resumption token is provided:
        while ($token !== false) {
            $this->harvestProgressReport();
            $token = $this->getRecordsByToken($token);
        }
        $this->harvestProgressReport();
        if (isset($this->childPid)) {
            pcntl_waitpid($this->childPid, $status);
        }
    }

    /**
     * List identifiers of all available documents.
     *
     * @param functionref $callback Function to be called to process an identifier
     * 
     * @return void
     * @access public
     */
    public function listIdentifiers($callback)
    {
        $this->_normalRecords = 0;
        $this->deletedRecords = 0;
        $this->_callback = $callback;
    
        if ($this->resumptionToken) {
            $this->message('Incremental listing from given resumptionToken');
            $token = $this->getIdentifiersByToken($this->resumptionToken);
        } else {
            $this->message('Listing all identifiers');
            $token = $this->getIdentifiers();
        }
    
        // Keep harvesting as long as a resumption token is provided:
        while ($token !== false) {
            $this->listIdentifiersProgressReport();
            $token = $this->getIdentifiersByToken($token);
        }
        $this->listIdentifiersProgressReport();
        if (isset($this->childPid)) {
            pcntl_waitpid($this->childPid, $status);
        }
    }
    
    /**
     * Display harvesting progress 
     * 
     * @return void
     */
    protected function harvestProgressReport()
    {
        $this->message(
            'Harvested ' . $this->_normalRecords . ' normal records and '
            . $this->deletedRecords . ' deleted records from ' . $this->source
        );
    }

    /**
     * Display listing progress 
     *
     * @return void
     */
    protected function listIdentifiersProgressReport()
    {
        $this->message(
            'Listed ' . $this->_normalRecords . ' normal records and '
            . $this->deletedRecords . ' deleted records from ' . $this->source
        );
    }

    /**
     * Retrieve the date from the database and use it as our start
     * date if it is available.
     *
     * @return void
     * @access protected
     */
    protected function loadHarvestDate()
    {
        $state = $this->db->state->findOne(array('_id' => "Last Harvest Date {$this->source}"));
        if (isset($state)) {
            $this->setStartDate($state['value']);
        }
    }

    /**
     * Normalize a date to a Unix timestamp.
     *
     * @param string $date Date (ISO-8601 or YYYY-MM-DD HH:MM:SS)
     *
     * @return integer     Unix timestamp (or false if $date invalid)
     * @access protected
     */
    protected function normalizeDate($date)
    {
        // Remove timezone markers -- we don't want PHP to outsmart us by adjusting
        // the time zone!
        $date = str_replace(array('T', 'Z'), array(' ', ''), $date);

        // Translate to a timestamp:
        return strtotime($date);
    }

    /**
     * Save the harvest date.
     *
     * @param string $date Date to save.
     *
     * @return void
     * @access protected
     */
    protected function saveHarvestDate($date)
    {
        $state = array('_id' => "Last Harvest Date {$this->source}", 'value' => $date);
        $this->db->state->save($state);
    }

    /**
     * Make an OAI-PMH request.  Throw an exception if there is an error; return a SimpleXML object
     * on success.
     *
     * @param string $verb   OAI-PMH verb to execute.
     * @param array  $params GET parameters for ListRecords method.
     *
     * @return object        SimpleXML-formatted response.
     * @access protected
     */
    protected function sendRequest($verb, $params = array())
    {
        // Set up the request:
        $request = new HTTP_Request2(
            $this->baseURL, 
            HTTP_Request2::METHOD_GET, 
            array('ssl_verify_peer' => false)
        );       
        $request->setHeader('User-Agent', 'RecordManager');

        // Load request parameters:
        $url = $request->getURL();
        $params['verb'] = $verb;
        $url->setQueryVariables($params);
        
        $urlStr = $url->getURL();
        $this->message("Sending request: $urlStr", true);
        if ($this->debugLog) {
            file_put_contents($this->debugLog, "Request:\n$urlStr\n", FILE_APPEND);
        }

        // Perform request and throw an exception on error:
        for ($try = 1; $try <= 5; $try++) {
            try {
                $response = $request->send();
            } catch (Exception $e) {
                if ($try < 5) {
                    $this->message(
                        "Request '$urlStr' failed (" . $e->getMessage() . "), retrying in 30 seconds...", 
                        false, 
                        Logger::WARNING
                    );
                    sleep(30);
                    continue;
                }
                throw $e;
            }
            if ($try < 5) {
                $code = $response->getStatus();
                if ($code >= 300) {
                    $this->message(
                        "Request '$urlStr' failed ($code), retrying in 30 seconds...",
                        false,
                        Logger::WARNING
                    );
                    sleep(30);
                    continue;
                }
            }
            break;
        }
        $code = $response->getStatus();
        if ($code >= 300) {
            $this->message("Request '$urlStr' failed: $code", false, Logger::FATAL);
            throw new Exception("Request failed: $code");
        }

        // If we got this far, there was no error -- send back response.
        $responseStr = $response->getBody();
        if ($this->debugLog) {
            file_put_contents($this->debugLog, "Response:\n$responseStr\n\n", FILE_APPEND);
        }
        return $this->processResponse($responseStr);
    }

    /**
     * Load XML into simplexml
     *
     * @param string $xml XML string
     * 
     * @return SimpleXMLElement
     * @access protected
     */
    protected function loadXML($xml)
    {
        $doc = new DOMDocument();
        if (!$doc->loadXML($xml)) {
            return false;
        }
        if ($this->transformation) {
            return $this->transformation->transformToDoc($doc);
        }
        return $doc;
    }

    /**
     * Process an OAI-PMH response into a SimpleXML object. Throw exception if an error is
     * detected.
     *
     * @param string $xml OAI-PMH response XML.
     *
     * @return object     SimpleXML-formatted response.
     * @access protected
     */
    protected function processResponse($xml)
    {
        // Parse the XML:
        $saveUseErrors = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $result = $this->loadXML($xml);
        if ($result === false || libxml_get_last_error() !== false) {
            // Assuming it's a character encoding issue, this might help...
            $this->message('Invalid XML received, trying encoding fix...', false, Logger::WARNING);
            $xml = iconv('UTF-8', 'UTF-8//IGNORE', $xml);
            libxml_clear_errors();
            $result = $this->loadXML($xml);
        }
        if ($result === false || libxml_get_last_error() !== false) {
            libxml_use_internal_errors($saveUseErrors);
            $errors = '';
            foreach (libxml_get_errors() as $error) {
                if ($errors) {
                    $errors .= '; ';
                }
                $errors .= 'Error ' . $error->code . ' at '
                    . $error->line . ':' . $error->column . ': ' . $error->message;
            }
            $this->message("Could not parse XML response: $errors\nXML:\n$xml", false, Logger::FATAL);
            throw new Exception("Failed to parse XML response");
        }
        libxml_use_internal_errors($saveUseErrors);

        // Detect errors and throw an exception if one is found:
        $error = $this->getSingleNode($result, 'error');
        if ($error) {
            $code = $error->getAttribute('code');
            if ($code != 'noRecordsMatch') {
                $value = $result->saveXML($error);
                $this->message(
                    "OAI-PMH server returned error $code ($value)", 
                    false,
                    Logger::FATAL
                );
                throw new Exception(
                    "OAI-PMH error -- code: $code, " .
                    "value: $value\n"
                );
            }
        }

        // If we got this far, we have a valid response:
        return $result;
    }

    /**
     * Extract the ID from a record object (support method for processRecords()).
     *
     * @param object $header SimpleXML record header.
     *
     * @return string        The ID value.
     * @access protected
     */
    protected function extractID($header)
    {
        // Normalize to string:
        $id = $this->getSingleNode($header, 'identifier')->nodeValue;

        // Strip prefix if found:
        if (substr($id, 0, strlen($this->idPrefix)) == $this->idPrefix) {
            $id = substr($id, strlen($this->idPrefix));
        }

        // Apply regular expression matching:
        if (!empty($this->idSearch)) {
            $id = preg_replace($this->idSearch, $this->idReplace, $id);
        }

        // Return final value:
        return $id;
    }

    /**
     * Save harvested records and track the end date.
     *
     * @param object $records SimpleXML records.
     *
     * @return void
     * @access protected
     */
    protected function processRecords($records)
    {
        $this->message('Processing ' . count($records) . ' records', true);

        // Loop through the records:
        foreach ($records as $record) {
            $header = $this->getSingleNode($record, 'header');
            
            // Bypass the record if the record is missing its header:
            if ($header === false) {
                $this->message("Record header missing", false, Logger::ERROR);
                echo $this->_xml->saveXML($record) . "\n";
                continue;
            }

            // Get the ID of the current record:
            $id = $this->extractID($header);

            // Save the current record, either as a deleted or as a regular record:
            if (strcasecmp($header->getAttribute('status'), 'deleted') == 0) {
                call_user_func($this->_callback, $id, true, null);
                $this->deletedRecords++;
            } else {
                $recordNode = $this->getSingleNode($this->getSingleNode($record, 'metadata'), '*');
                if ($recordNode === false) {
                    $this->message("No metadata found for record $id", false, Logger::ERROR);
                    continue;
                }
                $this->_normalRecords += call_user_func($this->_callback, $id, false, trim($this->_xml->saveXML($recordNode)));
            }
        }
    }

    /**
     * Harvest records using OAI-PMH.
     *
     * @param array $params GET parameters for ListRecords method.
     *
     * @return mixed        Resumption token if provided, false if finished
     * @access protected
     */
    protected function getRecords($params)
    {
        // Make the OAI-PMH request:
        $this->_xml = $this->sendRequest('ListRecords', $params);

        // Save the records from the response:
        $listRecords = $this->getSingleNode($this->_xml, 'ListRecords');
        if ($listRecords !== false) {
            $records = $this->getImmediateChildrenByTagName($listRecords, 'record');
            if ($records) {
                $this->processRecords($records);
            }

            // If we have a resumption token, keep going; otherwise, we're done -- save
            // the end date.
            $token = $this->getSingleNode($listRecords, 'resumptionToken');
            if ($token !== false && $token->nodeValue) {
                return $token->nodeValue;
            }
        }
        $dateFormat = ($this->granularity == 'YYYY-MM-DD') ? 'Y-m-d' : 'Y-m-d\TH:i:s\Z';
        $this->saveHarvestDate(date($dateFormat, $this->serverDate));
        return false;
    }

    /**
     * Harvest records via OAI-PMH using date and set.
     *
     * @return mixed        Resumption token if provided, false if finished
     * @access protected
     */
    protected function getRecordsByDate()
    {
        $params = array('metadataPrefix' => $this->metadata);
        if (!empty($this->startDate)) {
            $params['from'] = $this->startDate;
        }
        if (!empty($this->endDate)) {
            $params['until'] = $this->endDate;
        }
        if (!empty($this->set)) {
            $params['set'] = $this->set;
        }
        return $this->getRecords($params);
    }

    /**
     * Harvest records via OAI-PMH using resumption token.
     *
     * @param string $token Resumption token.
     *
     * @return mixed        Resumption token if provided, false if finished
     * @access protected
     */
    protected function getRecordsByToken($token)
    {
        return $this->getRecords(array('resumptionToken' => (string)$token));
    }

    /**
     * Get identifiers using OAI-PMH.
     *
     * @param array $params GET parameters for ListIdentifiers method.
     *
     * @return mixed        Resumption token if provided, false if finished
     * @access protected
     */
    protected function getIdentifiers($params = array())
    {
        // Make the OAI-PMH request:
        if (empty($params)) {
            $params = array('metadataPrefix' => $this->metadata);
        }
        $this->_xml = $this->sendRequest('ListIdentifiers', $params);
        
        // Process headers
        $listIdentifiers = $this->getSingleNode($this->_xml, 'ListIdentifiers');
        if ($listIdentifiers !== false) {
            $headers = $this->getImmediateChildrenByTagName($listIdentifiers, 'header');
            $this->processIdentifiers($headers);
            $token = $this->getSingleNode($listIdentifiers, 'resumptionToken');
            if ($token !== false && $token->nodeValue) {
                return $token->nodeValue;
            }
        }

        return false;
    }

    /**
     * Get identifiers via OAI-PMH using resumption token.
     *
     * @param string $token Resumption token.
     *
     * @return mixed        Resumption token if provided, false if finished
     * @access protected
     */
    protected function getIdentifiersByToken($token)
    {
        return $this->getIdentifiers(array('resumptionToken' => (string)$token));
    }

    /**
     * Process fetched identifiers.
     *
     * @param object $headers SimpleXML headers
     *
     * @return void
     * @access protected
     */
    protected function processIdentifiers($headers)
    {
        $this->message('Processing ' . count($headers) . ' identifiers', true);
    
        // Loop through the records:
        foreach ($headers as $header) {
            // Get the ID of the current record:
            $id = $this->extractID($header);
    
            // Process the current header, either as a deleted or as a regular record:
            if (strcasecmp($header->getAttribute('status'), 'deleted') == 0) {
                call_user_func($this->_callback, $id, true);
                $this->deletedRecords++;
            } else {
                call_user_func($this->_callback, $id, false);
                $this->_normalRecords++;
            }
        }
    }

    /**
     * Log a message and display on console in verbose mode.
     *
     * @param string $msg     Message
     * @param bool   $verbose Flag telling whether this is considered verbose output
     * @param level  $level   Logging level
     *
     * @return void
     * @access protected
     */
    protected function message($msg, $verbose = false, $level = Logger::INFO)
    {
        if ($this->verbose) {
            echo "$msg\n";
        }
        $this->log->log('harvestOaiPmh', $msg, $level);
    }
    
    /**
     * Get the first XML child node with the given name
     * 
     * @param DOMDocument $xml      The XML Document
     * @param string      $nodeName Node to get
     * 
     * @return DOMNode | false  Result node or false if not found
     * @access protected
     */
    protected function getSingleNode($xml, $nodeName)
    {
        $nodes = $xml->getElementsByTagName($nodeName);
        if ($nodes->length == 0) {
            return false;
        }
        return $nodes->item(0);
    }
    
    /**
     * Traverse all children and collect those nodes that
     * have the tagname specified in $tagName. Non-recursive
     *
     * @param DOMElement $element DOM Element
     * @param string     $tagName Tag to get
     * 
     * @return string[]
     */
    protected function getImmediateChildrenByTagName($element, $tagName)
    {
        $result = array();
        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement && $child->tagName == $tagName) {
                $result[] = $child;
            }
        }
        return $result;
    }
}

