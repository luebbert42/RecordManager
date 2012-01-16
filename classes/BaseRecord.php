<?php
/**
 * BaseRecord Class
 *
 * PHP version 5
 *
 * Copyright (C) Ere Maijala 2011-2012.
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
 * @link
 */

/**
 * BaseRecord Class
 *
 * This is an abstract base class for processing records.
 *
 */
abstract class BaseRecord
{
    /**
     * Constructor
     *
     * @param string $data    Metadata
     * @param string $oaiID   Record ID received from OAI-PMH
     * @access public
     */
    public abstract function __construct($data, $oaiID);

    /**
     * Return record ID (local)
     *
     * @return string
     * @access public
     */
    public abstract function getID();

    /**
     * Serialize the record for storing in the database
     *
     * @return string
     * @access public
     */
    public abstract function serialize();

    /**
     * Serialize the record into XML for export
     *
     * @return string
     * @access public
     */
    public abstract function toXML();

    /**
     * Set the ID prefix into all the ID fields (ID, host ID etc.)
     *
     * @param  string $prefix The prefix (e.g. "source.")
     * @return void
     * @access public
     */
    public abstract function setIDPrefix($prefix);

    /**
     * Return host record ID for component part
     *
     * @return string
     * @access public
     */
    public function getHostRecordID()
    {
        return '';
    }

    /**
     * Return fields to be indexed in Solr
     *
     * @return array
     * @access public
     */
    public function toSolrArray()
    {
        return '';
    }

    /**
     * Merge component parts to this record
     *
     * @param MongoCollection $componentParts
     * @access public
     */
    public function mergeComponentParts($componentParts)
    {
    }

    /**
     * Dedup: Return full title (for debugging purposes only)
     *
     * @return string
     * @access public
     */
    public function getFullTitle()
    {
        return '';
    }

    /**
     * Dedup: Return record title
     *
     * @param bool $forFiling Whether the title is to be used in filing (e.g. sorting, non-filing characters should be removed)
     * @return string
     * @access public
     */
    public function getTitle($forFiling = false)
    {
        return '';
    }

    /**
     * Dedup: Return main author (format: Last, First)
     *
     * @return string
     * @access public
     */
    public function getMainAuthor()
    {
        return '';
    }

    /**
     * Dedup: Return ISBNs in ISBN-13 format without dashes
     *
     * @return array
     * @access public
     */
    public function getISBNs()
    {
        return array();
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
        return '';
    }

    /**
     * Dedup: Return publication year (four digits only)
     *
     * @return string
     * @access public
     */
    public function getPublicationYear()
    {
        return '';
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
}
