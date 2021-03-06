<?php
/**
 * Command line interface for Record Manager
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
 * @link     https://github.com/KDK-Alli/RecordManager
 */

require_once 'cmdline.php';

/**
 * Main function 
 * 
 * @param string[] $argv Program parameters
 * 
 * @return void
 */
function main($argv)
{
    $params = parseArgs($argv);
    if (!isset($params['func'])) {
        echo "Usage: manage --func=... [...]\n\n";
        echo "Parameters:\n\n";
        echo "--func             renormalize|deduplicate|updatesolr|dump|deletesource|deletesolr|optimizesolr|count\n";
        echo "--source           Source ID to process\n";
        echo "--all              Process all records regardless of their state (deduplicate)\n";
        echo "                   or date (updatesolr)\n";
        echo "--from             Override the date from which to run the update (updatesolr)\n";
        echo "--single           Process only the given record id (deduplicate, updatesolr, dump)\n";
        echo "--nocommit         Don't ask Solr to commit the changes (updatesolr)\n";
        echo "--field            Field to analyze (count)\n";
        echo "--verbose          Enable verbose output for debugging\n\n";
        exit(1);
    }

    $manager = new RecordManager(true);
    $manager->verbose = isset($params['verbose']) ? $params['verbose'] : false;

    $source = isset($params['source']) ? $params['source'] : '';
    $single = isset($params['single']) ? $params['single'] : '';
    $noCommit = isset($params['nocommit']) ? $params['nocommit'] : false;
    
    switch ($params['func'])
    {
    case 'renormalize': 
        $manager->renormalize($source, $single); 
        break;
    case 'deduplicate': 
        $manager->deduplicate($source, isset($params['all']) ? true : false, $single); 
        break;
    case 'updatesolr': 
        $date = isset($params['all']) ? '' : (isset($params['from']) ? $params['from'] : null);
        $manager->updateSolrIndex($date, $source, $single, $noCommit); 
        break;
    case 'dump': 
        $manager->dumpRecord($single);
        break;
    case 'deletesource':
        $manager->deleteRecords($source);
        break;
    case 'deletesolr':
        $manager->deleteSolrRecords($source);
        break;
    case 'optimizesolr':
        $manager->optimizeSolr();
        break;
    case 'count':
        $manager->countValues(isset($params['field']) ? $params['field'] : null);
        break;
    default: 
        echo 'Unknown func: ' . $params['func'] . "\n"; 
        exit(1);
    }
}

main($argv);

