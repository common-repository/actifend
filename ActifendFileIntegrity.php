<?php
/**
 * ActifendFileIntegrity
 *
 * This is class to check the integrity of the files on the wordpress site
 *
 * @category FileIntegrity
 * @package  actifend
 */
class ActifendFileIntegrity {

    private $totalFiles = 0;
    private $totalFolders = 0;
    private $wordpress_directory = "";
    private $ignore_directory = array();
    private $ignore_extension = array( "log", "swp", "htaccess", "conf" );

    // Identify WP version update
    private $false_positive = 0;

    /**
     * Class Initializer
     * @param directory that should be considered for file integrity
     */
    public function __construct($wordpress_directory) {
        Utility::initFileSystem();
        global $wp_filesystem;
        $content_path = $wp_filesystem->wp_content_dir();

        $this->wordpress_directory = realpath($wordpress_directory);
        $prefix = trailingslashit( basename( $content_path ));
        $this->ignore_directory = array(
            $prefix . 'actifend_tmp',
            $prefix . 'uploads',
            $prefix . 'upgrade',
            $prefix . 'languages',
            $prefix . 'plugins',
            $prefix . 'themes',
            $prefix . 'mu-plugins',
            $prefix . 'mu-themes',
            $prefix . 'wp_backup',
            $prefix . 'wflogs',
            $prefix . 'afend_quarantine');
    }

    /**
     * Total files scanned
     * @return total files scanned during file integrity baseline
     */
    public function getTotalScannedFiles() {
        return $this->totalFiles;
    }

    /**
     * takeSnapshot
     * This function will take a snapshot of the current folder / file structure
     * in the wordpress directory
     */
    public function takeSnapshot($type='mtime', $filepath='', $skip=array()) {
        try {
            // folder path
            if (empty($filepath))
                $filepath = $this->wordpress_directory;
            // extensions to fetch, an empty array will return all extensions
            $ext = $this->ignore_extension;
            // directories to ignore, an empty array will check all directories
            if (empty( $skip )) {
                $skip = $this->ignore_directory;
            }

            if ($type == 'mtime') {
                $files = $this->getDirectoryTree_bySize_and_mtime($filepath, $skip, $ext);
            } else {
                $files = $this->getDirectoryTree_byHash($filepath, $skip, $ext);
            }
            debug_log("Snapshot taken successfully!");
            return $files;
        } catch (Exception $e) {
            debug_log($e->getMessage());
            throw new Exception("Exception 2x01: " . $e->getMessage());
        }
    }

    /**
     * createBaseline
     * This function will take a snapshot of the folder structure and
     * writes the file hashes / size and modified time to the database
     * @param type of baseline; mtime or hash; default mtime
     * @return Time elaspsed in creating the baseline
     */
    public function createBaseline($type="mtime") {
        try {
            // start timer
            $startTime = $this->microtime_float();

            // Take snapshot and write to database
            $this->totalFiles = 0;
            $files = $this->takeSnapshot($type);
            if ($type == "mtime") {
                $res = $this->writeToDatabase_size_and_mtime($files);
            } else {
                $res = $this->writeToDatabase_filehash($files);
            }
            if ($res) {
                // end timer
                $endTime = $this->microtime_float();
                // calculate time elapsed
                $timeDiff = $endTime - $startTime;
                debug_log("File integrity baseline created!");
                debug_log("Time elapsed: $timeDiff Seconds.");
                debug_log("Total Files Scanned: $this->totalFiles");
                debug_log("Total Folders Scanned: $this->totalFolders");
                return $timeDiff;
            } else {
                debug_log("Failed to write file integrity hashes / details to database!");
                return false;
            }
        } catch (Exception $e){
            debug_log($e->getMessage());
            return false;
        }
    }

    /**
     * microtime_float
     * This function will time with microseconds
     */
    public function microtime_float() {
        list($usec, $sec) = explode(" ", microtime());
        return ((float)$usec + (float)$sec);
    }

    /**
     * getModifiedFiles_byhash
     * This function will calculate the discrepancies in the given file structure
     * using the sha1 hash of the file
     * @return multidimentional array of file discrepancies
     */
    public function getModifiedFiles_byhash() {
        // take a new snapshot of the file structure
        $files = $this->takeSnapshot('hash');
        debug_log("Calculating the file system diff using hashes!");
        global $wpdb;
        $integrity_hashes_table = $wpdb->prefix . ACTIFEND_INTEGRITY_HASHES_TABLE;

        // specific check for discrepancies
        if (!empty($files)) {
            $files_with_type = array();
            foreach ($files as $key => $value) {
                if (is_dir($key))
                    $fType = 'dir';
                else
                    $fType = 'file';
                $files_with_type[$key] = $fType;
            }
            $result = $wpdb->get_results("SELECT * FROM {$integrity_hashes_table}");
            $diffs = array();
            if (!empty($result)) {
                $tmp = array();
                foreach ($result as $key => $value) {
                    if (!array_key_exists($value->file_path, $files)) {
                        if (is_dir($value->file_path))
                            $fType = 'dir';
                        else
                            $fType = 'file';
                        $diffs["del"][$value->file_path] = $fType;
                        $tmp[$value->file_path] = $fType;
                    }
                    else {
                        if (is_dir($files[$value->file_path]))
                            $fType = 'dir';
                        else
                            $fType = 'file';
                        if ($files[$value->file_path] != $value->file_hash) {
                            $diffs["alt"][$value->file_path] = $fType;
                            $tmp[$value->file_path] = $fType;
                        }
                        else {
                            // unchanged
                            $tmp[$value->file_path] = $fType;
                        }
                    }
                }
                // if (count($tmp) < count($files)) {
                $add_diff = array_diff_assoc($files_with_type, $tmp);
                if (!empty($add_diff)) $diffs["add"] = $add_diff;
                unset($tmp);
            }
            // Send the difference to Actifend BE, if not empty
            if (!empty($diffs)) {
                $actifend_array = array('disc' => $diffs);
                $utiObj = new Utility;
                $result = $utiObj->getActifendInfo();
                if (isset($result->asset_id) && !empty($result->asset_id)) {
                    $assetid = $result->asset_id;
                } else {
                    debug_log("No ASSET ID assigned .... ");
                    return $diffs;
                }
                $actifend_url = ACTIFEND_ASSETS_END_POINT . $assetid . "/sitecheck";

                $utiObj->actifend_postViaCurl($actifend_url, json_encode($actifend_array));
                debug_log("File integrity check result sent to Actifend BE.");
            }
            return $diffs;
        }

    }

    /**
     * getModifiedFiles_bySize_and_mtime
     * This function will calculate the discrepancies in the given file structure
     * using the size of the file and last modified time of the file
     * @return multidimentional array of file discrepancies
     */
    public function getModifiedFiles_bySize_and_mtime( $skip=array(), $send_diff_to_app=true ) {
        // take a new snapshot of the file structure
        debug_log("File integrity check initiated!");
        $files = $this->takeSnapshot( 'mtime', '', $skip );

        global $wpdb;
        $integrity_files_table = $wpdb->prefix . ACTIFEND_INTEGRITY_FILES_TABLE;

        // specific check for discrepancies
        if (!empty( $files )) {
            $result = $wpdb->get_results("SELECT * FROM {$integrity_files_table}");
            $diffs = array();
            if (!empty($result)) {
                $deltmp = array();
                $delkeys = array();
                $alttmp = array();
                $altkeys = array();
                $files_in_db = array();
                $files_in_sys = $this->get_values_from_assoc_array($files, 'file_path');
                foreach ( $result as $value ) {
                    // add filenames in db to files_in_db array
                    array_push($files_in_db, $value->file_path);
                    // check if the file is existing in both db and system
                    if (! in_array($value->file_path, $files_in_sys)) {
                        $fpath = substr( $value->file_path, strlen( ABSPATH ));
                        if ( !$fpath )
                            array_push($delkeys, $value->file_path);
                        else
                            array_push($delkeys, $fpath);
                        array_push($deltmp, $value->file_type);
                    } else {
                        // 21/9/2017 check if version.php file has changed
                        // if yes, it is a core update and should be considered false positive
                        if ( substr( $value->file_path, -11 ) == 'version.php') {
                            $diffs = array();
                            $this->false_positive = 1;
                            debug_log( "A WP core update has occured!" );
                            return $diffs;
                        }
                        // check if file's size or modified time has changed
                        if ($value->file_size != $this->get_size_of_file($files, $value->file_path)) {
                            $fpath = substr( $value->file_path, strlen( ABSPATH ));
                            if ( !$fpath )
                                array_push($altkeys, $value->file_path);
                            else
                                array_push($altkeys, $fpath);
                            array_push($alttmp, $value->file_type);
                        } elseif ($value->file_mtime != $this->get_mtime_of_file($files, $value->file_path)) {
                            if ( !is_link($value->file_path) && $value->file_path != WP_CONTENT_DIR ) {
                                $fpath = substr( $value->file_path, strlen( ABSPATH ));
                                if ( !$fpath )
                                    array_push($altkeys, $value->file_path);
                                else
                                    array_push($altkeys, $fpath);
                                array_push($alttmp, $value->file_type);
                            }
                        }
                    }
                }
                if (! empty($delkeys)) $diffs["del"] = array_combine($delkeys, $deltmp);
                if (! empty($altkeys)) $diffs["alt"] = array_combine($altkeys, $alttmp);
                // check if any new files are added
                // Note: Prior to PHP 5.5, empty() only supports variables;
                // anything else will result in a parse error.
                $add_diff_res = array_diff($files_in_sys, $files_in_db);

                if (! empty($add_diff_res)) {
                    $addkeys = array();
                    $addtmp = array();
                    // this is necessary because array_diff will retain the original keys
                    foreach ($add_diff_res as $key => $value) {
                        $fpath = substr( $value, strlen( ABSPATH ));
                        if ( !$fpath )
                            array_push($addkeys, $value);
                        else
                            array_push($addkeys, $fpath);

                        if (is_dir($value)) {
                            array_push($addtmp, 'dir');
                        } else {
                            array_push($addtmp, 'file');
                        }
                    }
                    $diffs["add"] = array_combine($addkeys, $addtmp);
                }

                unset($files_in_db);
                unset($files_in_sys);
                unset($deltmp);
                unset($delkeys);
                unset($alttmp);
                unset($altkeys);
                unset($addkeys);
                unset($addtmp);
            }
            debug_log("Difference is: ");
            debug_log($diffs);
            // Send the difference to Actifend BE, if not empty
            if ( !empty($diffs) && $send_diff_to_app ) {
                $actifend_array = array('disc' => $diffs);
                # Add the result to the options table
                update_option('actifend_fi_disc', $diffs);
                # Get the assetid
                $utiObj = new Utility;
                $result = $utiObj->getActifendInfo();
                if (isset($result->asset_id) && !empty($result->asset_id)) {
                    $assetid = $result->asset_id;
                } else {
                    debug_log("No ASSET ID assigned .... ");
                    return $diffs;
                }
                $actifend_url = ACTIFEND_ASSETS_END_POINT . $assetid . "/sitecheck";

                $utiObj->actifend_postViaCurl($actifend_url, json_encode($actifend_array));
                debug_log("File integrity check result sent to Actifend BE.");
            }
            return $diffs;
        }

    }

    /**
     * getDirectoryTree_byHash
     * gets the file hashes in a given folder
     * @param folder path
     * @param directories to skip as array
     * @param file extensions to skip as array
     * @return associated array with file path as key and its has as value
     */
    public function getDirectoryTree_byHash($folder, $skipdir, $skipext) {
        // build profile
        $dir = new RecursiveDirectoryIterator($folder, RecursiveDirectoryIterator::FOLLOW_SYMLINKS);
        $iter = new RecursiveIteratorIterator($dir, RecursiveIteratorIterator::SELF_FIRST, RecursiveIteratorIterator::CATCH_GET_CHILD);
        $files = array();
        while ($iter->valid()) {
            $each = array();
            // skip unwanted directories
            $subPath = $iter->getSubPath();
            foreach ($skipdir as $sdir) {
                if (strstr($subPath, $sdir)) {
                    debug_log("Skipping $subPath ...");
                    $subPath = $sdir;
                    break;
                }
            }

            if (!$iter->isDot() && !in_array($subPath, $skipdir)) {
                // get specific file extensions
                if (!empty($skipext)) {
                    // PHP 5.3.4: if (in_array($iter->getExtension(), $ext)) {
                    if (!in_array(pathinfo($iter->key(), PATHINFO_EXTENSION), $skipext)) {
                        $files[$iter->key()] = hash_file("sha1", $iter->key());
                    }
                }
                else {
                    // ignore file extensions
                    $files[$iter->key()] = hash_file("sha1", $iter->key());
                }
            }
            $iter->next();
        }
        $this->totalFiles = count($files);
        return $files;
    }

    /**
     * get_values_from_assoc_array
     * gets values of key from multi dimentional array as an array
     * @param multi dimentional associated array
     * @param key for which values need to be picked
     * @return simple array of values of the given key
     */
    public function get_values_from_assoc_array($files, $key) {
        $values = array();
        foreach ($files as $file) {
            array_push($values, $file[$key]);
        }
        return $values;
    }

    /**
     * get_size_of_file
     * gets size of given file from multi dimentional array as an array
     * @param multi dimentional associated array
     * @param filename for which size needs to be returned
     * @return size of a file or false if file not found in the array
     */
    public function get_size_of_file($files, $filename) {
        foreach ($files as $file) {
            if ($file['file_path'] == $filename) {
                return $file['file_size'];
            }
        }
        return false;
    }

    /**
     * get_mtime_of_file
     * gets modified time of given file from multi dimentional array as an array
     * @param multi dimentional associated array
     * @param filename for which modified time needs to be returned
     * @return modified time of a file or false if file not found in the array
     */
    public function get_mtime_of_file($files, $filename) {
        foreach ($files as $file) {
            if ($file['file_path'] == $filename) {
                return $file['file_mtime'];
            }
        }
        return false;
    }

    /**
     * get_filetype_of_file
     * gets modified time of given file from multi dimentional array as an array
     * @param multi dimentional associated array
     * @param filename for which modified time needs to be returned
     * @return file type of a file or false if file not found in the array
     */
    public function get_filetype_of_file($files, $filename) {
        foreach ($files as $file) {
            if ($file['file_path'] == $filename) {
                return $file['file_type'];
            }
        }
        return false;
    }

    /**
     * getDirectoryTree_bysize_and_mtime
     * gets the details of the files and folders in a given folder
     * @param folder path
     * @return associated array of files with file_path, file_size and file_mtime as keys
     */
    public function getDirectoryTree_bySize_and_mtime($folder, $skipdir, $skipext) {
        // build profile
        global $wp_filesystem;

        $dir = new RecursiveDirectoryIterator($folder, RecursiveDirectoryIterator::FOLLOW_SYMLINKS);
        $iter = new RecursiveIteratorIterator($dir, RecursiveIteratorIterator::SELF_FIRST, RecursiveIteratorIterator::CATCH_GET_CHILD);
        $files = array();
        while ($iter->valid()) {
            $each = array();
            // skip unwanted directories
            // $subPath = $iter->getSubPath();
            if (!$iter->isDot()) {
                // get specific file extensions
                if (!empty($skipext)) {
                    // PHP 5.3.4: if (in_array($iter->getExtension(), $ext)) {
                    if (!in_array(pathinfo($iter->key(), PATHINFO_EXTENSION), $skipext)) {
                        $file = array();
                        $file['file_path'] = $iter->key();
                        $file['file_size'] = filesize($iter->key());
                        $file['file_mtime'] = filemtime($iter->key());
                        // Add file type if it is a directory
                        if ($iter->isDir()) {
                            $file['file_type'] = 'dir';
                            $this->totalFolders += 1;
                        } else {
                            $file['file_type'] = 'file';
                        }
                        array_push($files, $file);
                    }
                } else {
                    // ignore file extensions
                    $file = array();
                    $file['file_path'] = $iter->key();
                    // $file['file_size'] = filesize($iter->key());
                    $file['file_size'] = $wp_filesystem->size( $iter->key() );
                    // $file['file_mtime'] = filemtime($iter->key());
                    $file['file_mtime'] = $wp_filesystem->mtime( $iter->key() );
                    if ($iter->isDir()) {
                        $file['file_type'] = 'dir';
                        $this->totalFolders += 1;
                    } else {
                        $file['file_type'] = 'file';
                    }
                    array_push($files, $file);
                }
            }
            $iter->next();
        }

        $content_path = $wp_filesystem->wp_content_dir();

        // take care of escape chars in windows installs
        if (strtoupper(substr(PHP_OS, 0, 3)) === "WIN") {
            $wpContentDir = str_replace("/", "\\", $content_path);
        } else {
            $wpContentDir = $content_path;
        }

        // eliminate dirs to be skipped and files inside them!
        $remCount = 0;
        foreach ($skipdir as $sdir) {
            debug_log("Dir to skip for snapshot: " . $sdir);

            foreach ($files as $file) {
                if (strstr($file['file_path'], $sdir)) {
    				$key = array_search($file, $files);
                    unset($files[$key]);
                    $remCount++;
                }
                // Skipping wp-content folder alone but not files inside
                // Change of content inside even debug logs would change the timestamp on the folder
                // which will become a nuisense if alerts get raised
                if (strcmp($file['file_path'], $wpContentDir) === 0) {
    				$key = array_search($file, $files);
                    unset($files[$key]);
                    debug_log("Skipped wp-content folder!");
                }
            }
        }

        $this->totalFiles = count($files) - $this->totalFolders;
        debug_log("Skipped {$remCount} Files from unwanted folders... ");
        debug_log("Found {$this->totalFolders} folders in the system!");
        debug_log("Found {$this->totalFiles} files in the system!");
        unset($iter);
        unset($dir);
        return $files;

    }


    /**
     * writeToDatabase_filehash
     * This function will write the filename and its hash to the database table
     * @param array with filenames and their hashes
     * @return bool true or false
     */
    public function writeToDatabase_filehash($files) {
         try {
            global $wpdb;
            // clear old records, if any
            $integrity_hashes_table = $wpdb->prefix . ACTIFEND_INTEGRITY_HASHES_TABLE;
            // dbDelta("TRUNCATE {$integrity_hashes_table}");
            $wpdb->query("TRUNCATE TABLE $integrity_hashes_table");
            debug_log("$integrity_hashes_table Truncation Done!");
            $filesCount = $wpdb->get_var("SELECT COUNT(*) FROM {$integrity_hashes_table}");
            debug_log("{$integrity_hashes_table} table still contains {$filesCount} rows after truncation!");

            // insert updated records
            $sql = "INSERT INTO {$integrity_hashes_table} VALUES ";
            foreach ($files as $fname => $fhash) {
                $sql_vals[] = '("' . $fname . '", "' . $fhash . '")';
            }
            $sql .= implode(',', $sql_vals);
            // dbDelta($sql);
            $wpdb->query($sql);

            // foreach ($files as $fname => $fhash) {
            //     $ins_query = "INSERT INTO {$integrity_hashes_table} VALUES ('$fname', '$fhash')";
            //     dbDelta($ins_query);
            // }
            $filesCount = $wpdb->get_var("SELECT COUNT(*) FROM {$integrity_hashes_table}");
            debug_log("Wrote {$filesCount} rows to {$integrity_hashes_table} table!");

            return true;
        } catch (Exception $e) {
            debug_log( $e->getMessage() );
            throw new Exception("Exception 2x02: " . $e->getMessage());
        }
     }

    /**
     * writeToDatabase_size_and_mtime
     * This function will write the filename and its size and mtime to the database table
     * @param array with filename, size and mtime
     * @return bool true or false
     */
    public function writeToDatabase_size_and_mtime($files) {
         try {
            global $wpdb;
            global $wp_filesystem;
            $admin_dir = trailingslashit( $wp_filesystem->abspath() ) . 'wp-admin';

            if(!function_exists('dbDelta')) {
                require_once(trailingslashit( $admin_dir ) . 'includes/upgrade.php');
            }

            // clear old records, if any
            $integrity_files_table = $wpdb->prefix . ACTIFEND_INTEGRITY_FILES_TABLE;
            // Not using dbDelta with TRUNCATE in this case; sometimes TRUNCATE is not working
            // dbDelta("TRUNCATE TABLE " . $integrity_files_table);
            $wpdb->query("TRUNCATE TABLE $integrity_files_table");
            debug_log("$integrity_files_table Truncation Done!");
            $filesCount = $wpdb->get_var("SELECT COUNT(*) FROM {$integrity_files_table}");
            debug_log("{$integrity_files_table} table still contains {$filesCount} rows after truncation!");

            // insert updated records
            $sql = "INSERT INTO {$integrity_files_table} VALUES ";
            foreach ($files as $file) {
                $sql_vals[] = '("' . $file['file_path'] . '", "' . $file['file_size'] . '", "' . $file['file_mtime'] . '", "' . $file['file_type'] . '")';
            }
            $sql .= implode(", ", $sql_vals);
            if (strtoupper(substr(PHP_OS, 0, 3)) === "WIN") {
                $sql = str_replace("\\", "\\\\", $sql);
            }
            // dbDelta($sql);
            $wpdb->query($sql);

            $filesCount = $wpdb->get_var("SELECT COUNT(*) FROM {$integrity_files_table}");
            debug_log("Wrote {$filesCount} rows to {$integrity_files_table} table!");
            // debug_log("Write to $integrity_files_table complete!");
            return true;
        } catch (Exception $e) {
            debug_log($e->getMessage());
            throw new Exception("Exception 2x02: " . $e->getMessage());
        }
     }

}

?>