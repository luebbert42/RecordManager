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
 */

require_once 'cmdline.php';

function main($argv)
{
    $params = parseArgs($argv);
    if (!isset($params['func']))
    {
        echo "Usage: manage --func=... --transformation=... [...]\n\n";
        echo "Parameters:\n\n";
        echo "--func             renormalize|deduplicate|updatesolr\n";
        echo "--source           Source ID to process\n";
        echo "--all              Process all records regardless of their state (deduplicate)\n";
        echo "--from             Override the date from which to run the update (updatesolr)\n";
        echo "--single           Process only the given record id (deduplicate, updatesolr)\n";
        echo "--verbose          Enable verbose output for debugging\n\n";
        exit(1);
    }

    $manager = new RecordManager(true);
    $manager->verbose = isset($params['verbose']) ? $params['verbose'] : false;

    $source = isset($params['source']) ? $params['source'] : '';
    $single = isset($params['single']) ? $params['single'] : '';

    switch ($params['func'])
    {
        case 'renormalize': $manager->renormalize($source, $single); break;
        case 'deduplicate': $manager->deduplicate($source, isset($params['all']) ? true : false, $single); break;
        case 'updatesolr': $manager->updateSolrIndex(isset($params['from']) ? $params['from'] : null, $source, $single); break;
        default: echo 'Unknown func: ' . $params['func'] . "\n"; exit(1);
    }
}

main($argv);
