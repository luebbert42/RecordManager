<?php
/**
 * SolrUpdater Class
 *
 * PHP version 5
 *
 * Copyright (C) Ere Maijala, The National Library of Finland 2012
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
require_once 'PerformanceCounter.php';

/**
 * SolrUpdater Class
 *
 * This is a class for updating the Solr index.
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */
class SolrUpdater
{
    protected $db;
    protected $log;
    protected $settings;
    protected $buildingHierarchy;
    protected $verbose;
    protected $counts;

    /**
     * Constructor 
     * 
     * @param MongoDB $db                 Database connection
     * @param string  $basePath           RecordManager main directory 
     * @param array   $dataSourceSettings Data source settings
     * @param object  $log                Logger
     * @param boolean $verbose            Whether to output verbose messages
     * 
     * @throws Exception
     */
    public function __construct($db, $basePath, $dataSourceSettings, $log, $verbose)
    {
        global $configArray;
        
        $this->db = $db;
        $this->basePath = $basePath;
        $this->log = $log;
        $this->verbose = $verbose;
        $this->counts = isset($configArray['Mongo']['counts']) && $configArray['Mongo']['counts'];
        
        // Special case: building hierarchy
        $this->buildingHierarchy = isset($configArray['Solr']['hierarchical_facets'])
            && in_array('building', $configArray['Solr']['hierarchical_facets']);

        // Load settings and mapping files
        $this->settings = array();
        foreach ($dataSourceSettings as $source => $settings) {
            if (!isset($settings['institution'])) {
                throw new Exception("Error: institution not set for $source\n");
            }
            if (!isset($settings['format'])) {
                throw new Exception("Error: format not set for $source\n");
            }
            $this->settings[$source] = $settings;
            $this->settings[$source]['idPrefix'] = isset($settings['idPrefix']) && $settings['idPrefix'] ? $settings['idPrefix'] : $source;
            $this->settings[$source]['componentParts'] = isset($settings['componentParts']) && $settings['componentParts'] ? $settings['componentParts'] : 'as_is';
            $this->settings[$source]['indexMergedParts'] = isset($settings['indexMergedParts']) ? $settings['indexMergedParts'] : true;
            $this->settings[$source]['solrTransformationXSLT'] = isset($settings['solrTransformation']) && $settings['solrTransformation'] ? new XslTransformation($this->basePath . '/transformations', $settings['solrTransformation']) : null;
            $this->settings[$source]['mappingFiles'] = array();
            
            foreach ($settings as $key => $value) {
                if (substr($key, -8, 8) == '_mapping') {
                    $field = substr($key, 0, -8);
                    $this->settings[$source]['mappingFiles'][$field] = $this->readMappingFile($this->basePath . '/mappings/' . $value);
                }
            }
        }

        $this->commitInterval = isset($configArray['Solr']['max_commit_interval'])
            ? $configArray['Solr']['max_commit_interval'] : 50000;
        $this->maxUpdateRecords = isset($configArray['Solr']['max_update_records'])
            ? $configArray['Solr']['max_update_records'] : 5000;
        $this->maxUpdateSize = isset($configArray['Solr']['max_update_size'])
            ? $configArray['Solr']['max_update_size'] : 1024;
        $this->maxUpdateSize *= 1024;
    }

    /**
     * Update Solr index (individual records)
     * 
     * @param string|null $fromDate Starting date for updates (if empty 
     *                              string, last update date stored in the database
     *                              is used and if null, all records are processed)
     * @param string      $sourceId Source ID to update, or empty or * for all 
     *                              source
     * @param string      $singleId Export only a record with the given ID
     * @param bool        $noCommit If true, changes are not explicitly committed
     * 
     * @return void
     */
    public function updateIndividualRecords($fromDate = null, $sourceId = '', $singleId = '', $noCommit = false)
    {
        if (isset($fromDate) && $fromDate) {
            $mongoFromDate = new MongoDate(strtotime($fromDate));
        }

        $needCommit = false;
        foreach ($this->settings as $source => $settings) {
            try {
                if ($sourceId && $sourceId != '*' && $source != $sourceId) {
                    continue;
                }
                if (empty($source) || empty($settings)) {
                    continue;
                }

                if (!isset($fromDate)) {
                    $state = $this->db->state->findOne(array('_id' => "Last Index Update $source"));
                    if (isset($state)) {
                        $mongoFromDate = $state['value'];
                    } else {
                        unset($mongoFromDate);
                    }
                }
                $from = isset($mongoFromDate) ? date('Y-m-d H:i:s', $mongoFromDate->sec) : 'the beginning';
                $this->log->log('updateIndividualRecords', "Creating record list (from $from), source '$source')...");
                // Take the last indexing date now and store it when done
                $lastIndexingDate = new MongoDate();
                $params = array();
                if ($singleId) {
                    $params['_id'] = $singleId;
                    $params['source_id'] = $source;
                    $lastIndexingDate = null;
                } else {
                    $params['source_id'] = $source;
                    if (isset($mongoFromDate)) {
                        $params['updated'] = array('$gte' => $mongoFromDate);
                    }
                    $params['update_needed'] = false;
                }
                $records = $this->db->record->find($params);
                $records->immortal(true);

                $total = $this->counts ? $records->count() : 'the';
                $count = 0;
                $mergedComponents = 0;
                $deleted = 0;
                if ($noCommit) {
                    $this->log->log('updateIndividualRecords', "Indexing $total records (with no forced commits) from '$source'...");
                } else {
                    $this->log->log('updateIndividualRecords', "Indexing $total records (max commit interval {$this->commitInterval} records) from '$source'...");
                }
                $pc = new PerformanceCounter();
                $this->initBufferedUpdate();
                foreach ($records as $record) {
                    if ($record['deleted']) {
                        $this->bufferedDelete((string)$record['key']);
                        ++$deleted;
                    } else {
                        $data = $this->createSolrArray($record, $mergedComponents);
                        if ($data === false) {
                            continue;
                        }

                        if ($this->verbose) {
                            echo "Metadata for record {$record['_id']}: \n";
                            print_r($data);
                        }

                        ++$count;                       
                        $res = $this->bufferedUpdate($data, $count, $noCommit);
                        if ($res) {
                            $pc->add($count);
                            $avg = $pc->getSpeed();
                            $this->log->log('updateIndividualRecords', "$count records (of which $deleted deleted) with $mergedComponents merged parts indexed from '$source', $avg records/sec");
                        }
                    }
                }
                $this->flushUpdateBuffer();

                if (isset($lastIndexingDate)) {
                    $state = array('_id' => "Last Index Update $source", 'value' => $lastIndexingDate);
                    $this->db->state->save($state);
                }
                $needCommit = $count > 0;
                $this->log->log('updateIndividualRecords', "Completed with $count records (of which $deleted deleted) with $mergedComponents merged parts indexed from '$source'");
            } catch (Exception $e) {
                $this->log->log('updateIndividualRecords', 'Exception: ' . $e->getMessage(), Logger::FATAL);
            }
        }
        if (!$noCommit && $needCommit) {
            $this->log->log('updateIndividualRecords', "Final commit...");
            $this->solrRequest('{ "commit": {} }');
            $this->log->log('updateIndividualRecords', "Commit complete");
        }
    }
    
    /**
     * Update Solr index (merged records)
     * 
     * @param string|null $fromDate Starting date for updates (if empty 
     *                              string, last update date stored in the database
     *                              is used and if null, all records are processed)
     * @param string      $sourceId Source ID to update, or empty or * for all 
     *                              source
     * @param string      $singleId Export only a record with the given ID
     * @param bool        $noCommit If true, changes are not explicitly committed
     * 
     * @return void
     */
    public function updateMergedRecords($fromDate = null, $sourceId = '', $singleId = '', $noCommit = false)
    {
        try {
            $needCommit = false;

            // Drop too old m/r collections
            foreach ($this->db->listCollections() as $collection) {
                if (strstr($collection, 'mr_record_') && time() - substr($collection, strrpos($collection, '_') + 1) > 7 * 60 * 60 * 24) {
                    $this->log->log('updateMergedRecords', "Cleanup: dropping old m/r collection $collection");
                    $this->db->dropCollection($collection);
                }
            }
            
            if (isset($fromDate) && $fromDate) {
                $mongoFromDate = new MongoDate(strtotime($fromDate));
            }
    
            if (!isset($fromDate)) {
                $state = $this->db->state->findOne(array('_id' => 'Last Index Update'));
                if (isset($state)) {
                    $mongoFromDate = $state['value'];
                } else {
                    unset($mongoFromDate);
                }
            }
            $from = isset($mongoFromDate) ? date('Y-m-d H:i:s', $mongoFromDate->sec) : 'the beginning';
            $this->log->log('updateMergedRecords', "Creating merged record list (from $from)...");
            // Take the last indexing date now and store it when done
            $lastIndexingDate = new MongoDate();
            $this->initBufferedUpdate();
    
            // Process deduped records
            $params = array();
            if ($singleId) {
                $params['_id'] = $singleId;
                $params['dedup_key'] = array('$exists' => true);
                $lastIndexingDate = null;
            } else {
                if (isset($mongoFromDate)) {
                    $params['updated'] = array('$gte' => $mongoFromDate);
                }
                if ($sourceId) {
                    $params['source_id'] = $sourceId;
                }
                $params['update_needed'] = false;
                $params['dedup_key'] = array('$exists' => true);
            }
            
            $map = new MongoCode("function() { emit(this.dedup_key, 1); }");
            $reduce = new MongoCode("function(k, vals) { return vals.length; }");
            $collectionName = 'mr_record_' . time();
            $mr = $this->db->command(
                array(
                    'mapreduce' => 'record', 
                    'map' => $map,
                    'reduce' => $reduce,
                    'out' => array('replace' => $collectionName),
                    'query' => $params,
                ),
                array(
                    'timeout' => 3000000
                )
            );
            if (!$mr['ok']) {
                $this->log->log('updateMergedRecords', "Mongo map/reduce failed: " . $mr['assertion'], Logger::FATAL);
                return; 
            }
            $keys = $this->db->{$collectionName}->find();
            $keys->immortal(true);
            $count = 0;
            $mergedComponents = 0;
            $deleted = 0;
            $this->initBufferedUpdate();
            if ($noCommit) {
                $this->log->log('updateMergedRecords', "Indexing the merged records (with no forced commits)...");
            } else {
                $this->log->log('updateMergedRecords', "Indexing the merged records (max commit interval {$this->commitInterval} records)...");
            }
            $pc = new PerformanceCounter();
            foreach ($keys as $key) {
                $records = $this->db->record->find(array('dedup_key' => $key['_id']));
                $children = array();
                $merged = array();
                foreach ($records as $record) {
                    if ($record['deleted']) {
                        $this->bufferedDelete($record['_id']);
                        ++$count;
                        continue;
                    }
                    $data = $this->createSolrArray($record, $mergedComponents);
                    if ($data === false) {
                        continue;
                    }
                    $merged = $this->mergeRecords($merged, $data);
                    $children[] = array('mongo' => $record, 'solr' => $data);
                }
                
                if (count($children) < 2) {
                    // A dedup key exists for a single record. This shouldn't happen, but try to manage
                    $child = $children[0];
                    $this->log->log('updateMergedRecords', "Found a single record with a dedup key: {$child['solr']['id']}", Logger::WARNING);
                    if ($this->verbose) {
                        echo "Original deduplicated but single record {$child['solr']['id']}:\n";
                        print_r($child['solr']);
                    }
                    // Delete any merged record
                    $this->bufferedDelete((string)$child['mongo']['dedup_key']);
                    
                    ++$count;
                    $res = $this->bufferedUpdate($child['solr'], $count, $noCommit);
                    if ($res) {
                        $pc->add($count);
                        $avg = $pc->getSpeed();
                        $this->log->log('updateMergedRecords', "$count merged records (of which $deleted deleted) with $mergedComponents merged parts indexed, $avg records/sec");
                    }
                } else {
                    foreach ($children as $child) {
                        $child = $child['solr'];
                        $child['merged_child_boolean'] = true;
                    
                        if ($this->verbose) {
                            echo "Original deduplicated record {$child['id']}:\n";
                            print_r($child);
                        }
                    
                        ++$count;
                        $res = $this->bufferedUpdate($child, $count, $noCommit);
                        if ($res) {
                            $pc->add($count);
                            $avg = $pc->getSpeed(); 
                            $this->log->log('updateMergedRecords', "$count merged records (of which $deleted deleted) with $mergedComponents merged parts indexed, $avg records/sec");
                        }
                    }
                    
                    $mergedId = (string)$key['_id']; 
                    if (empty($merged)) {
                        $this->bufferedDelete($mergedId);
                        ++$deleted;
                        continue;
                    }
                    $merged['id'] = $mergedId;
                    $merged['recordtype'] = 'merged';
                    $merged['merged_boolean'] = true;
                    
                    if ($this->verbose) {
                        echo "Merged record {$merged['id']}:\n";
                        print_r($merged);
                    }
                    
                    ++$count;
                    $res = $this->bufferedUpdate($merged, $count, $noCommit);
                    if ($res) {
                        $pc->add($count);
                        $avg = $pc->getSpeed();
                        $this->log->log('updateMergedRecords', "$count merged records (of which $deleted deleted) with $mergedComponents merged parts indexed, $avg records/sec");
                    }
                }
            }
            $this->db->dropCollection($collectionName);
            $this->flushUpdateBuffer();
            $needCommit = $count > 0;
            $this->log->log('updateMergedRecords', "Total $count merged records (of which $deleted deleted) with $mergedComponents merged parts indexed");

            $this->log->log('updateMergedRecords', "Creating individual record list (from $from)...");
            $params = array();
            if ($singleId) {
                $params['_id'] = $singleId;
                $params['dedup_key'] = array('$exists' => false);
                $lastIndexingDate = null;
            } else {
                if (isset($mongoFromDate)) {
                    $params['updated'] = array('$gte' => $mongoFromDate);
                }
                if ($sourceId) {
                    $params['source_id'] = $sourceId;
                }
                $params['dedup_key'] = array('$exists' => false);
                $params['update_needed'] = false;
            }
            $records = $this->db->record->find($params);
            $records->immortal(true);
    
            $total = $this->counts ? $records->count() : 'the';
            $count = 0;
            $mergedComponents = 0;
            $deleted = 0;
            if ($noCommit) {
                $this->log->log('updateMergedRecords', "Indexing $total individual records (with no forced commits)...");
            } else {
                $this->log->log('updateMergedRecords', "Indexing $total individual records (max commit interval {$this->commitInterval} records)...");
            }
            $pc->reset();
            $this->initBufferedUpdate();
            foreach ($records as $record) {
                if ($record['deleted']) {
                    $this->bufferedDelete((string)$record['_id']);
                    // Delete also any obsolete merged record having this key
                    if (!$this->db->record->findOne(array('dedup_key' => $record['key'], '_id' => array('$ne' => $record['_id'])), array())) {
                        $this->bufferedDelete((string)$record['key']);
                    }
                    ++$count;
                    ++$deleted;
                } else {
                    $data = $this->createSolrArray($record, $mergedComponents);
                    if ($data === false) {
                        continue;
                    }
                    
                    if ($this->verbose) {
                        echo "Metadata for record {$record['_id']}: \n";
                        print_r($data);
                    }
    
                    ++$count;                       
                    // Delete any obsolete merged record having this key
                    if (!$this->db->record->findOne(array('dedup_key' => $record['key'], '_id' => array('$ne' => $record['_id'])), array())) {
                        $this->bufferedDelete((string)$record['key']);
                    }
                    $res = $this->bufferedUpdate($data, $count, $noCommit);
                    if ($res) {
                        $pc->add($count);
                        $avg = $pc->getSpeed();
                        $this->log->log('updateMergedRecords', "$count individual records (of which $deleted deleted) with $mergedComponents merged parts indexed, $avg records/sec");
                    }
                }
            }
            $this->flushUpdateBuffer();
    
            if (isset($lastIndexingDate)) {
                $state = array('_id' => "Last Index Update", 'value' => $lastIndexingDate);
                $this->db->state->save($state);
            }
            if ($count > 0) {
                $needCommit = true;
            }
            $this->log->log('updateMergedRecords', "Total $count individual records (of which $deleted deleted) with $mergedComponents merged parts indexed");
            
            if (!$noCommit && $needCommit) {
                $this->log->log('updateMergedRecords', "Final commit...");
                $this->solrRequest('{ "commit": {} }');
                $this->log->log('updateMergedRecords', "Commit complete");
            }
        } catch (Exception $e) {
            $this->log->log('updateMergedRecords', 'Exception: ' . $e->getMessage(), Logger::FATAL);
        }
    }
    
    /**
     * Delete all records belonging to the given source from the index
     * 
     * @param string $sourceId Source ID
     * 
     * @return void
     */
    public function deleteDataSource($sourceId)
    {
        $this->solrRequest('{ "delete": { "query": "id:' . $sourceId . '.*" } }');
        $this->solrRequest('{ "commit": {} }', 4 * 60 * 60);
    }

    /**
     * Optimize the Solr index
     * 
     * @return void
     */
    public function optimizeIndex()
    {
        $this->solrRequest('{ "optimize": {} }', 4 * 60 * 60);
    }
    
    /**
     * Count distinct values in the specified field (that would be added to the Solr index)
     * 
     * @param string $field Field name
     * 
     * @return void
     */
    public function countValues($field)
    {
        $this->log->log('countValues', "Creating record list...");
        $records = $this->db->record->find(array('deleted' => false));
        $records->immortal(true);
        $this->log->log('countValues', "Counting values...");
        $values = array();
        $count = 0;
        foreach ($records as $record) {
            $source = $record['source_id'];
            if (!isset($this->settings[$source])) {
                $this->log->log('countValues', "No settings found for data source '$source'", Logger::FATAL);
                throw new Exception('countValues', "No settings found for data source '$source'");
            }
            $settings = $this->settings[$source];
            $mergedComponents = 0;
            $metadataRecord = RecordFactory::createRecord($record['format'], MetadataUtils::getRecordData($record, true), $record['oai_id']);
            if (isset($settings['solrTransformationXSLT'])) {
                $params = array(
                    'source_id' => $source,
                    'institution' => $settings['institution'],
                    'format' => $settings['format'],
                    'id_prefix' => $settings['idPrefix']
                );
                $data = $settings['solrTransformationXSLT']->transformToSolrArray($metadataRecord->toXML(), $params);
            } else {
                $data = $metadataRecord->toSolrArray();
            }
            if (isset($data[$field])) {
                foreach (is_array($data[$field]) ? $data[$field] : array($data[$field]) as $value) {
                    if (!isset($values[$value])) {
                        $values[$value] = 1;
                    } else {
                        ++$values[$value];
                    }
                }
            }
            ++$count;                       
            if ($count % 1000 == 0) {
                $this->log->log('countValues', "$count records processed");
            }
        }
        arsort($values, SORT_NUMERIC);
        foreach ($values as $key => $value) {
            echo "$key: $value\n";
        }
    }
    
    /**
     * Create Solr array for the given record
     * 
     * @param object  $record            Mongo record
     * @param integer &$mergedComponents Number of component parts merged to the record
     * 
     * @return string[]
     */
    protected function createSolrArray($record, &$mergedComponents)
    {
        global $configArray;
        
        $metadataRecord = RecordFactory::createRecord($record['format'], MetadataUtils::getRecordData($record, true), $record['oai_id']);
        
        $source = $record['source_id'];
        if (!isset($this->settings[$source])) {
            $this->log->log('createSolrArray', "No settings found for data source '$source'", Logger::FATAL);
            throw new Exception('createSolrArray', "No settings found for data source '$source'");
        }
        $settings = $this->settings[$source];
        $hiddenComponent = false;
        if ($record['host_record_id']) {
            if ($settings['componentParts'] == 'merge_all') {
                $hiddenComponent = true;
            } elseif ($settings['componentParts'] == 'merge_non_articles' || $settings['componentParts'] == 'merge_non_earticles') {
                $format = $metadataRecord->getFormat();
                if ($format != 'eJournalArticle' && $format != 'JournalArticle') {
                    $hiddenComponent = true;
                } elseif ($format == 'JournalArticle' && $settings['componentParts'] == 'merge_non_earticles') {
                    $hiddenComponent = true;
                }
            }
        }
        
        if ($hiddenComponent && !$settings['indexMergedParts']) {
            return false;
        }
        
        $hasComponentParts = false;
        $components = null;
        if (!$record['host_record_id']) {
            // Fetch info whether component parts exists and need to be merged
            $components = $this->db->record->find(array('host_record_id' => $record['_id'], 'deleted' => false));
            $hasComponentParts = $components->hasNext();
            $format = $metadataRecord->getFormat();
            $merge = false;
            if ($settings['componentParts'] == 'merge_all') {
                $merge = true;
            } elseif ($format != 'eJournal' && $format != 'Journal' && $format != 'Serial') {
                $merge = true;
            } elseif (($format == 'Journal' || $format == 'Serial') && $settings['componentParts'] == 'merge_non_earticles') {
                $merge = true;
            }
            if (!$merge) {
                unset($components);
            }
        }
        
        $metadataRecord->setIDPrefix($settings['idPrefix'] . '.');
        if (isset($components)) {
            $mergedComponents += $metadataRecord->mergeComponentParts($components);
        }
        if (isset($settings['solrTransformationXSLT'])) {
            $params = array(
                'source_id' => $source,
                'institution' => $settings['institution'],
                'format' => $settings['format'],
                'id_prefix' => $settings['idPrefix']
            );
            $data = $settings['solrTransformationXSLT']->transformToSolrArray($metadataRecord->toXML(), $params);
        } else {
            $data = $metadataRecord->toSolrArray();
        }
        
        $data['id'] = $record['_id'];
        
        // Record links between host records and component parts
        if ($metadataRecord->getIsComponentPart()) {
            $hostRecord = null;
            if ($record['host_record_id']) {
                $hostRecord = $this->db->record->findOne(array('_id' => $record['host_record_id']));
                $data['hierarchy_parent_id'] = $record['host_record_id'];
            }
            if (!$hostRecord) {
                $this->log->log('createSolrArray', "Host record '" . $record['host_record_id'] . "' not found for record '" . $record['_id'] . "'", Logger::WARNING);
                $data['container_title'] = $metadataRecord->getContainerTitle();
            } else {
                $hostMetadataRecord = RecordFactory::createRecord(
                    $hostRecord['format'],
                    MetadataUtils::getRecordData($hostRecord, true),
                    $hostRecord['oai_id']
                );
                $data['container_title'] = $data['hierarchy_parent_title'] = $hostMetadataRecord->getTitle();
            }
            $data['container_volume'] = $metadataRecord->getVolume();
            $data['container_issue'] = $metadataRecord->getIssue();
            $data['container_start_page'] = $metadataRecord->getStartPage();
            $data['container_reference'] = $metadataRecord->getContainerReference();
        }
        if ($hasComponentParts) {
            $data['is_hierarchy_id'] = $record['_id'];
            $data['is_hierarchy_title'] = $metadataRecord->getTitle();
        }
        
        if (!isset($data['institution'])) {
            $data['institution'] = $settings['institution'];
        }
        if (!isset($data['collection'])) {
            $data['collection'] = $record['source_id'];
        }
        
        // Map field values according to any mapping files
        foreach ($settings['mappingFiles'] as $field => $map) {
            if (isset($data[$field])) {
                if (is_array($data[$field])) {
                    foreach ($data[$field] as &$value) {
                        if (isset($map[$value])) {
                            $value = $map[$value];
                        } elseif (isset($map['##default'])) {
                            $value = $map['##default'];
                        }
                    }
                    $data[$field] = array_unique($data[$field]);
                } else {
                    if (isset($map[$data[$field]])) {
                        $data[$field] = $map[$data[$field]];
                    } elseif (isset($map['##default'])) {
                        $data[$field] = $map['##default'];
                    }
                }
            }
        }
        
        // Special case: Hierarchical facet support for building (institution/location)
        if ($this->buildingHierarchy) {
            if (isset($data['building']) && $data['building']) {
                $building = array('0/' . $settings['institution']);
                foreach ($data['building'] as $datavalue) {
                    $values = explode('/', $datavalue);
                    $hierarchyString = $settings['institution'];
                    for ($i = 0; $i < count($values); $i++) {
                        $hierarchyString .= '/' . $values[$i];
                        $building[] = ($i + 1) . "/$hierarchyString";
                    }
                }
                $data['building'] = $building;
            } else {
                $data['building'] = array(
                    '0/' . $settings['institution']
                );
            }
        }
        
        // Other hierarchical facets
        if (isset($configArray['Solr']['hierarchical_facets'])) {
            foreach ($configArray['Solr']['hierarchical_facets'] as $facet) {
                if ($facet == 'building' || !isset($data[$facet])) {
                    continue;
                }
                $array = array();
                if (!is_array($data[$facet])) {
                    $data[$facet] = array($data[$facet]);
                }
                foreach ($data[$facet] as $datavalue) {
                    $values = explode('/', $datavalue);
                    $hierarchyString = '';
                    for ($i = 0; $i < count($values); $i++) {
                        $hierarchyString .= '/' . $values[$i];
                        $array[] = ($i) . $hierarchyString;
                    }
                }
                $data[$facet] = $array;
            }
        }
        
        if (!isset($data['allfields'])) {
            $all = array();
            foreach ($data as $key => $field) {
                if (in_array($key, array('fullrecord', 'thumbnail', 'id', 'recordtype', 'ctrlnum'))) {
                    continue;
                }
                if (is_array($field)) {
                    $all[] = implode(' ', $field);
                } else {
                    $all[] = $field;
                }
            }
            $data['allfields'] = MetadataUtils::array_iunique($all);
        }
        
        
        $data['dedup_key'] = isset($record['dedup_key']) && $record['dedup_key']
            ? (string)$record['dedup_key'] : $record['_id'];
        $data['first_indexed'] = MetadataUtils::formatTimestamp($record['created']->sec);
        $data['last_indexed'] = MetadataUtils::formatTimestamp($record['updated']->sec);
        $data['recordtype'] = $record['format'];
        if (!isset($data['fullrecord'])) {
            $data['fullrecord'] = $metadataRecord->toXML();
        }
        if (!is_array($data['format'])) {
            $data['format'] = array($data['format']);
        }
        
        if ($hiddenComponent) {
            $data['hidden_component_boolean'] = true;
        }
        
        foreach ($data as $key => $value) {
            // Checking only for empty() won't work as 0 is empty too
            if (empty($value) && $value !== 0 && $value !== 0.0 && $value !== '0') {
                unset($data[$key]);
            }
        }

        return $data;
    }
    
    /**
     * Merge two Solr records
     * 
     * @param string[] $merged Merged (base) record
     * @param string[] $add    Record to merge into $merged
     * 
     * @return string[] Resulting merged record
     */
    protected function mergeRecords($merged, $add)
    {
        $mergedFields = array('institution', 'collection', 'building', 'language', 
            'physical', 'publisher', 'publishDate', 'contents', 'url', 'ctrlnum',
            'author2', 'author_additional', 'title_alt', 'title_old', 'title_new', 
            'dateSpan', 'series', 'series2', 'topic', 'genre', 'geographic', 
            'era', 'long_lat');
        
        $checkedFields = array('title_auth', 'title', 'title_short', 'title_full', 'title_sort', 'author');
        
        if (empty($merged)) {
            $merged = $add;
            unset($merged['id']);
            $merged['local_ids_str_mv'] = array($add['id']);
            unset($merged['fullrecord']);
        } else {
            $merged['local_ids_str_mv'][] = $add['id'];
        } 
        foreach ($add as $key => $value) {
            if (substr($key, -3, 3) == '_mv' || in_array($key, $mergedFields)) {
                if (!isset($merged[$key])) {
                    $merged[$key] = $value;
                }
                if (!is_array($merged[$key])) {
                    $merged[$key] = array($merged[$key]);
                }
                if (!is_array($value)) {
                    $value = array($value);
                }
                $merged[$key] = array_values(MetadataUtils::array_iunique(array_merge($merged[$key], $value)));
            } elseif (in_array($key, $checkedFields)) {
                if (!isset($merged[$key])) {
                    $merged[$key] = $value;
                }
            } elseif ($key == 'allfields') {
                if (!isset($merged['allfields'])) {
                    $merged['allfields'] = array();
                }
                $merged['allfields'] = array_values(
                    MetadataUtils::array_iunique(
                        array_merge(
                            $merged['allfields'], 
                            $add['allfields']
                        )
                    )
                );
            }
        }
        
        return $merged;
    }

    /**
     * Make a JSON request to the Solr server
     * 
     * @param string       $body    The JSON request
     * @param integer|null $timeout If specified, the HTTP call timeout in seconds
     * 
     * @return void
     */
    protected function solrRequest($body, $timeout = null)
    {
        global $configArray;

        if (!isset($this->request)) {
            $this->request = new HTTP_Request2(
                $configArray['Solr']['update_url'],
                HTTP_Request2::METHOD_POST, 
                array('ssl_verify_peer' => false)
            );
            if (isset($timeout)) {
                $this->request->setConfig('timeout', $timeout);
            }
            $this->request->setHeader('User-Agent', 'RecordManager');
            if (isset($configArray['Solr']['username']) && isset($configArray['Solr']['password'])) {
                $this->request->setAuth(
                    $configArray['Solr']['username'],
                    $configArray['Solr']['password'],
                    HTTP_Request2::AUTH_BASIC
                );
            }
        }
        $this->request->setHeader('Content-Type', 'application/json');
        $this->request->setBody($body);
        $response = $this->request->send();
        $code = $response->getStatus();
        if ($code >= 300) {
            throw new Exception("Solr server request failed ($code). Request:\n$body\n\nResponse:\n" . $response->getBody());
        }
    }

    /**
     * Initialize the record update buffer
     * 
     * @return void
     */
    protected function initBufferedUpdate()
    {
        $this->buffer = '';
        $this->bufferLen = 0;
        $this->buffered = 0;
        $this->bufferedDeletions = array();
    }

    /**
     * Update Solr index in a batch
     * 
     * @param array $data     Record metadata
     * @param int   $count    Number of records processed so far
     * @param bool  $noCommit Whether to not do any explicit commits
     * 
     * @return boolean        False when buffering, true when buffer is flushed
     */
    protected function bufferedUpdate($data, $count, $noCommit)
    {
        $result = false;
        
        if (isset($data['allfields']) && is_array($data['allfields'])) {
            $data['allfields'] = implode(' ', $data['allfields']);
        }
        $jsonData = json_encode($data);
        if ($this->buffered > 0) {
            $this->buffer .= ",\n";
        }
        $this->buffer .= $jsonData;
        $this->bufferLen += strlen($jsonData);
        if (++$this->buffered >= $this->maxUpdateRecords || $this->bufferLen > $this->maxUpdateSize) {
            $this->solrRequest("[\n{$this->buffer}\n]");
            $this->buffer = '';
            $this->bufferLen = 0;
            $this->buffered = 0;
            $result = true;
        }
        if (!$noCommit && $count % $this->commitInterval == 0) {
            $this->log->log('bufferedUpdate', "Intermediate commit...");
            $this->solrRequest('{ "commit": {} }');
        }
        return $result;
    }

    /**
     * Delete Solr records in a batch
     * 
     * @param string $id Record ID
     * 
     * @return void 
     */
    protected function bufferedDelete($id)
    {
        $this->bufferedDeletions[] = '"delete":{"id":"' . $id . '"}';
        if (count($this->bufferedDeletions) >= 1000) {
            $this->solrRequest("{" . implode(',', $this->bufferedDeletions) . "}");
            $this->bufferedDeletions = array();
        }
    }
    
    /**
     * Flush the buffered updates to Solr
     * 
     * @return void
     */
    protected function flushUpdateBuffer()
    {
        if ($this->buffered > 0) {
            $this->solrRequest("[\n{$this->buffer}\n]");
        }
        if (!empty($this->bufferedDeletions)) {
            $this->solrRequest("{" . implode(',', $this->bufferedDeletions) . "}");
            $this->bufferedDeletions = array();
        }
    }
    
    /**
     * Read a mapping file (two strings separated by ' = ' per line)
     * 
     * @param string $filename Mapping file name
     * 
     * @throws Exception
     * @return string[string] Mappings
     */
    protected function readMappingFile($filename)
    {
        $mappings = array();
        $handle = fopen($filename, 'r');
        if (!$handle) {
            throw new Exception("Could not open mapping file '$filename'");
        }
        $lineno = 0;
        while (($line = fgets($handle))) {
            ++$lineno;
            $line = rtrim($line);
            if (!$line || $line[0] == ';') {
                continue;
            }
            $values = explode(' = ', $line, 2);
            if (!isset($values[1])) {
                if (strstr($line, ' =') === false) {
                    fclose($handle);
                    throw new Exception("Unable to parse mapping file '$filename' line (no ' = ' found): ($lineno) $line");
                }
                $mappings[$values[0]] = '';
            } else {
                $mappings[$values[0]] = $values[1];
            }
        }
        fclose($handle);
        return $mappings;
    }
}
