<?php
/**
 * EseRecord Class
 *
 * PHP version 5
 *
 * Copyright (C) Ere Maijala, The National Library of Finland 2011-2012
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
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */

require_once 'BaseRecord.php';
require_once 'MetadataUtils.php';

/**
 * EseRecord Class
 *
 * This is a class for processing ESE (Europeana) records.
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */
class EseRecord extends BaseRecord
{
    protected $doc = null;

    /**
     * Constructor
     *
     * @param string $data  Record metadata
     * @param string $oaiID Record ID in OAI-PMH
     * 
     * @access public
     */
    public function __construct($data, $oaiID)
    {
        $this->doc = simplexml_load_string($data);
    }

    /**
     * Return record ID (local)
     *
     * @return string
     * @access public
     */
    public function getID()
    {
        return '';
    }

    /**
     * Serialize the record for storing in the database
     *
     * @return string
     * @access public
     */
    public function serialize()
    {
        return MetadataUtils::trimXMLWhitespace($this->doc->asXML());
    }

    /**
     * Serialize the record into XML for export
     *
     * @return string
     * @access public
     */
    public function toXML()
    {
        return $this->doc->asXML();
    }

    /**
     * Set the ID prefix into all the ID fields (ID, host ID etc.)
     *
     * @param string $prefix (e.g. "source.")
     * 
     * @return void
     * @access public
     */
    public function setIDPrefix($prefix)
    {
    }

    /**
     * Return fields to be indexed in Solr
     *
     * @return string[]
     * @access public
     */
    public function toSolrArray()
    {
        $data = array();

        $doc = $this->doc;
        $data['ctrlnum'] = (string)$doc->recordID;
        $data['fullrecord'] = $doc->asXML();
          
        // allfields
        $allFields = array();
        foreach ($doc->children() as $tag => $field) {
            $allFields[] = $field;
        }
        $data['allfields'] = $allFields;
          
        // language
        $data['language'] = explode(' ', $doc->language);

        $data['format'] = (string)$doc->type;
        $data['author'] = (string)$doc->creator;
        $data['author2'] = $this->getValues('contributor');

        $data['title'] = $data['title_full'] = (string)$doc->title;
        $titleParts = explode(' : ', $data['title']);
        if (!empty($titleParts)) {
            $data['title_short'] = $titleParts[0];
            if (isset($titleParts[1])) {
                $data['title_sub'] = $titleParts[1];
            }
        }
        $data['title_sort'] = $this->getTitle(true);

        $data['publisher'] = (string)$doc->publisher;
        $data['publishDate'] = $this->getPublicationYear();

        $data['isbn'] = $this->getISBNs();

        $data['topic'] = $data['topic_facet'] = $this->getValues('subject');

        foreach ($this->getValues('identifier') as $identifier) {
            if (preg_match('/^https?/', $identifier)) {
                $data['url'] = $identifier;
            }
        }
        foreach ($this->getValues('description') as $description) {
            if (preg_match('/^https?/', $description)) {
                $data['url'] = $description;
            }
        }

        /*
        TODO: Can we handle any of the following fields?

        $data['series'] = $this->_getFieldsSubfields('440ap:800abcdfpqt:830ap');
          
        $data['physical'] = $this->_getFieldsSubfields('300abcefg:530abcd');
        $data['edition'] = $this->_getFieldSubfields('250a');
        $data['contents'] = $this->_getFieldsSubfields('505a:505t');
          
        $data['issn'] = $this->_getFieldsSubfields('022a:440x:490x:730x:776x:780x:785x');

        $data['callnumber'] = strtoupper(str_replace(' ', '', $this->_getFirstFieldSubfields('080ab:084ab:050ab')));
        $data['callnumber-a'] = $this->_getFirstFieldSubfields('080a:084a:050a');
        $data['callnumber-first-code'] = substr($this->_getFirstFieldSubfields('080a:084a:050a'), 0, 1);

        $data['genre'] = $this->_getFieldsSubfields('655');
        $data['geographic'] = $this->_getFieldsSubfields('651');
        $data['era'] = $this->_getFieldsSubfields('648');

        $data['genre_facet'] = $this->_getFieldsSubfields('600v:610v:611v:630v:648v:650v:651v:655a:655v');
        $data['geographic_facet'] = $this->_getFieldsSubfields('600z:610z:611z:630z:648z:650z:651a:651z:655z');
        $data['era_facet'] = $this->_getFieldsSubfields('600d:610y:611y:630y:648a:648y:650y:651y:655y');

        $data['illustrated'] = $this->_getIllustrated();
        */
        return $data;
    }

    /**
     * Dedup: Return full title (for debugging purposes only)
     *
     * @return string
     * @access public
     */
    public function getFullTitle()
    {
        return (string)$this->doc->title;
    }

    /**
     * Dedup: Return record title
     *
     * @param bool $forFiling Whether the title is to be used in filing (e.g. sorting, non-filing characters should be removed)
     * 
     * @return string
     * @access public
     */
    public function getTitle($forFiling = false)
    {
        // TODO: strip common articles when $forFiling = true?
        return (string)$this->doc->title;
    }

    /**
     * Dedup: Return main author (format: Last, First)
     *
     * @return string
     * @access public
     */
    public function getMainAuthor()
    {
        return (string)$this->doc->creator;
    }

    /**
     * Dedup: Return ISBNs in ISBN-13 format without dashes
     *
     * @return string[]
     * @access public
     */
    public function getISBNs()
    {
        $arr = array();
        foreach ($this->doc->identifier as $identifier) {
            $identifier = str_replace('-', '', $identifier);
            if (!preg_match('{([0-9]{9,12}[0-9xX])}', $identifier, $matches)) {
                continue;
            }
            $isbn = $matches[1];
            if (strlen($isbn) == 10) {
                $isbn = MetadataUtils::isbn10to13($isbn);
            }
            if ($isbn) {
                $arr[] = $isbn;
            }
        }
        return array_values(array_unique($arr));
    }

    /**
     * Dedup: Return series ISSN
     *
     * @return string
     * @access public
     */
    public function getSeriesISSN()
    {
        return '';
    }

    /**
     * Dedup: Return series numbering
     *
     * @return string
     * @access public
     */
    public function getSeriesNumbering()
    {
        return '';
    }

    /**
     * Dedup: Return format from predefined values
     *
     * @return string
     * @access public
     */
    public function getFormat()
    {
        return $this->doc->type ? (string)$this->doc->type : 'Unknown';
    }

    /**
     * Dedup: Return publication year (four digits only)
     *
     * @return string
     * @access public
     */
    public function getPublicationYear()
    {
        foreach ($this->doc->date as $date) {
            if (preg_match('{^(\d{4})$}', $date)) {
                return (string)$date;
            }
        }
    }

    /**
     * Dedup: Return page count (number only)
     *
     * @return string
     * @access public
     */
    public function getPageCount()
    {
        return '';
    }

    /**
     * Get values for a tag
     * 
     * @param string $tag XML tag
     * 
     * @return multitype:string
     */
    protected function getValues($tag)
    {
        $values = array();
        foreach ($this->doc->{$tag} as $value) {
            $values[] = (string)$value;
        }
        return $values;
    }

}

