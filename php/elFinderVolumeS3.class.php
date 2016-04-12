<?php

/**
 * @file
 * 
 * elFinder driver for Amazon S3 (SOAP) filesystem.
 *
 * @author Dmitry (dio) Levashov,
 * @author Alexey Sukhotin
 * */
class elFinderVolumeS3 extends elFinderVolumeDriver {
    protected $driverId = 's3s';
    
    protected $s3;
    
    public function __construct() {
        $opts = array(
            'accesskey'          => '',
            'secretkey'          => '',
            'bucket'          => '',
            'tmpPath' => '',
        );
        $this->options = array_merge($this->options, $opts); 
        $this->options['mimeDetect'] = 'internal';

    }
    
    
    protected function init() {
        if (!$this->options['accesskey'] 
        ||  !$this->options['secretkey'] 
        ||  !$this->options['bucket']) {
            return $this->setError('Required options undefined.');
        }

        $this->s3 = \Aws\S3\S3Client::factory([
            'key'    => $this->options['accesskey'],
            'secret' => $this->options['secretkey'],
        ]);
        
        $this->root = $this->options['path'];
        
        $this->rootName = 's3';
        
        return true;
    }
    
    protected function configure() {
        parent::configure();
        $this->tmpPath = '';
        if (!empty($this->options['tmpPath'])) {
            if ((is_dir($this->options['tmpPath']) || @mkdir($this->options['tmpPath'])) && is_writable($this->options['tmpPath'])) {
                $this->tmpPath = $this->options['tmpPath'];
            }
        }
        if (!$this->tmpPath && ($tmp = elFinder::getStaticVar('commonTempPath'))) {
            $this->tmpPath = $tmp;
        }
        $this->mimeDetect = 'internal';
    }
    
    /**
     * Return parent directory path
     *
     * @param  string  $path  file path
     * @return string
     * @author Dmitry (dio) Levashov
     **/
    protected function _dirname($path) {
    
        $newpath =  preg_replace("/\/$/", "", $path);
        $dn = substr($path, 0, strrpos($newpath, '/')) ;
        
        if (substr($dn, 0, 1) != '/') {
         $dn = "/$dn";
        }
        
        return $dn;
    }

    /**
     * Return file name
     *
     * @param  string  $path  file path
     * @return string
     * @author Dmitry (dio) Levashov
     **/
    protected function _basename($path) {
        return basename($path);
    }

    
    
    /**
     * Join dir name and file name and return full path.
     * Some drivers (db) use int as path - so we give to concat path to driver itself
     *
     * @param  string  $dir   dir path
     * @param  string  $name  file name
     * @return string
     * @author Dmitry (dio) Levashov
     **/
        protected function _joinPath($dir, $name) {
        return $dir.DIRECTORY_SEPARATOR.$name;
    }
    
    /**
     * Return normalized path, this works the same as os.path.normpath() in Python
     *
     * @param  string  $path  path
     * @return string
     * @author Troex Nevelin
     **/
    protected function _normpath($path) {
        $tmp =  preg_replace("/^\//", "", $path);
        $tmp =  preg_replace("/\/\//", "/", $tmp);
        $tmp =  preg_replace("/\/$/", "", $tmp);
        return $tmp;
    }

    /**
     * Return file path related to root dir
     *
     * @param  string  $path  file path
     * @return string
     * @author Dmitry (dio) Levashov
     **/
    protected function _relpath($path) {
    
        
        $newpath = $path;
        
        
        if (substr($path, 0, 1) != '/') {
            $newpath = "/$newpath";
        }
        
        $newpath =  preg_replace("/\/$/", "", $newpath);
    
        $ret = ($newpath == $this->root) ? '' : substr($newpath, strlen($this->root)+1);
        
        return $ret;
    }
    
    /**
     * Convert path related to root dir into real path
     *
     * @param  string  $path  file path
     * @return string
     * @author Dmitry (dio) Levashov
     **/
    protected function _abspath($path) {
        return $path == $this->separator ? $this->root : $this->root.$this->separator.$path;
    }
    
    /**
     * Return fake path started from root dir
     *
     * @param  string  $path  file path
     * @return string
     * @author Dmitry (dio) Levashov
     **/
    protected function _path($path) {
        return $this->rootName.($path == $this->root ? '' : $this->separator.$this->_relpath($path));
    }
    
    /**
     * Return true if $path is children of $parent
     *
     * @param  string  $path    path to check
     * @param  string  $parent  parent path
     * @return bool
     * @author Dmitry (dio) Levashov
     **/
    protected function _inpath($path, $parent) {
        return $path == $parent || strpos($path, $parent.'/') === 0;
    }

    
    /**
     * Converting array of objects with name and value properties to
     * array[key] = value
     * @param  array  $metadata  source array
     * @return array
     * @author Alexey Sukhotin
     **/
    protected function metaobj2array($metadata) {
        $arr = array();
        
        if (is_array($metadata)) {
            foreach ($metadata as $meta) {
                $arr[$meta->Name] = $meta->Value;
            }
        } else {
            $arr[$metadata->Name] = $metadata->Value;
        }
        return $arr;
    }
    
    /**
     * Return stat for given path.
     * Stat contains following fields:
     * - (int)    size    file size in b. required
     * - (int)    ts      file modification time in unix time. required
     * - (string) mime    mimetype. required for folders, others - optionally
     * - (bool)   read    read permissions. required
     * - (bool)   write   write permissions. required
     * - (bool)   locked  is object locked. optionally
     * - (bool)   hidden  is object hidden. optionally
     * - (string) alias   for symlinks - link target path relative to root path. optionally
     * - (string) target  for symlinks - link target path. optionally
     *
     * If file does not exists - returns empty array or false.
     *
     * @param  string  $path    file path 
     * @return array|false
     * @author Rikhard (Richi) Gildman
     **/
    protected function _stat($path) {

        $stat = array(
            'size' => 0,
            'ts' => time(),
            'read' => true,
            'write' => true,
            'locked' => false,
            'hidden' => false,
            'mime' => 'directory',
        );

        if ($this->root == $path) {
            return $stat;
        }

        $np = $this->_normpath($path);

        try {
            $objects = $this->s3->getIterator(
                'ListObjects',
                array(
                    'Bucket' => $this->options['bucket'],
                    'Delimiter' => '/',
                    'Prefix' => $np
                ),
                array(
                    'return_prefixes' => true,
                )
            );

            foreach ($objects as $object) {
                if (!empty($object['Key'])) {
                    $stat['ts'] = $object['LastModified'];
                    $stat['size'] = $object['Size'];

                    // get mimi type
                    $obj = $this->s3->getObject([
                        'Bucket' => $this->options['bucket'],
                        'Key' => $object['Key'],
                    ]);

                    $stat['mime'] = $obj['ContentType'];

                    return $stat;

                } elseif (!empty($object['Prefix'])) {
                    return [
                        'mime' => 'directory'
                    ];
                }
            }
        } catch (Exception $e) {}

        return [];
    }
    

    /***************** file stat ********************/

        
    /**
     * Return true if path is dir and has at least one childs directory
     *
     * @param  string  $path  dir path
     * @return bool
     * @author Alexey Sukhotin
     **/
    protected function _subdirs($path) {
        $stat = $this->_stat($path);
        
        if ($stat['mime'] == 'directory') {
         $files = $this->_scandir($path);
         foreach ($files as $file) {
            $fstat = $this->_stat($file);
            if ($fstat['mime'] == 'directory') {
                return true;
            }
         }
        
        }
        
        return false;
    }
    
    /**
     * Return object width and height
     * Ususaly used for images, but can be realize for video etc...
     *
     * @param  string  $path  file path
     * @param  string  $mime  file mime type
     * @return string|false
     * @author Dmitry (dio) Levashov
     * @author Naoki Sawada
     **/
    protected function _dimensions($path, $mime) {
        $ret = false;
        if ($imgsize = $this->getImageSize($path, $mime)) {
            $ret = $imgsize['dimensions'];
        }
        return $ret;
    }
    
    /******************** file/dir content *********************/

    /**
     * Return files list in directory
     *
     * @param  string  $path  dir path
     * @return array
     * @author Rikhard (Richi) Gildman,
     **/
    protected function _scandir($path) {

        $s3path = preg_replace("/^\//", "", $path) . '/';

        $objects = $this->s3->getIterator(
            'ListObjects',
            array(
                'Bucket' => $this->options['bucket'],
                'Delimiter' => '/',
                'Prefix' => $s3path
            ),
            array(
                'return_prefixes' => true,
            )
        );

        $finalfiles = array();

        foreach ($objects as $object) {
            if (isset($object['Prefix'])) {
                // Dirs
                $finalfiles[] = $object['Prefix'];

                $stat = array(
                    'size' => 0,
                    'ts' => time(),
                    'read' => true,
                    'write' => true,
                    'locked' => false,
                    'hidden' => false,
                    'mime' => 'directory',
                );

                $this->updateCache($object['Prefix'], $stat);
            } else {
                // Files
                $finalfiles[] = $object['Key'];

                $stat = array(
                    'size' => $object['Size'],
                    'ts' => $object['LastModified'],
                    'read' => true,
                    'write' => true,
                    'locked' => false,
                    'hidden' => false,

                    // TODO: check it
                    'tmb' => $object['Key']
                );

                $this->updateCache($object['Key'], $stat);
            }
        }

        sort($finalfiles);

        return $finalfiles;
    }

    /**
     * Open file and return file pointer
     *
     * @param  string  $path  file path
     * @param  string  $mode open mode
     * @return resource|false
     * @author Dmitry (dio) Levashov,
     * @author Alexey Sukhotin
     **/
    protected function _fopen($path, $mode="rb") {
    
        $tn = $this->getTempFile($path);
    
        $fp = $this->tmbPath
            ? @fopen($tn, 'w+')
            : @tmpfile();
        

        if ($fp) {

            try {
                $obj = $this->s3->GetObject([
                    'Bucket' => $this->options['bucket'],
                    'Key' => $this->_normpath($path) ,
                    'GetMetadata' => true,
                    'InlineData' => true,
                    'GetData' => true
                ]);
            }    catch (Exception $e) {
        
            }

            //fwrite($fp, $obj->GetObjectResponse->Data);
            fwrite($fp, $obj['Body']);
            rewind($fp);
            return $fp;
        }
        
        return false;
    }
    
    /**
     * Close opened file
     * 
     * @param  resource  $fp    file pointer
     * @param  string    $path  file path
     * @return bool
     * @author Dmitry (dio) Levashov
     **/
    protected function _fclose($fp, $path='') {
        @fclose($fp);
        if ($path) {
            @unlink($this->getTempFile($path));
        }
    }
    
    /********************  file/dir manipulations *************************/
    
    /**
     * Create dir and return created dir path or false on failed
     *
     * @param  string  $path  parent dir path
     * @param string  $name  new directory name
     * @return string|bool
     * @author Dmitry (dio) Levashov,
     * @author Alexey Sukhotin
     **/
    protected function _mkdir($path, $name) {

        $newkey = $this->_normpath($path);
        $newkey = preg_replace("/\/$/", "", $newkey);
        $newkey = "$newkey/$name/";

        try {
            // TODO: check it
            $obj = $this->s3->PutObject([
                'Bucket' => $this->options['bucket'],
                'Key' => $newkey ,
                'ContentLength' => 0,
                'Body' => ''
            ]);
        } catch (Exception $e) {
        
        }
        
        if (isset($obj)) {
            return "$path/$name";
        }
        
        return false;
    }
    
    /**
     * Create file and return it's path or false on failed
     *
     * @param  string  $path  parent dir path
     * @param string  $name  new file name
     * @return string|bool
     * @author Dmitry (dio) Levashov,
     * @author Alexey Sukhotin
     **/
     protected function _mkfile($path, $name) {
        $newkey = $this->_normpath($path);
        $newkey = preg_replace("/\/$/", "", $newkey);
        $newkey = "$newkey/$name";

        try {
            // TODO: check it
            $obj = $this->s3->PutObject([
                'Bucket' => $this->options['bucket'],
                'Key' => $newkey ,
                'ContentLength' => 0,
                'Body' => '',
                'ContentType' => 'text/plain'
            ]);
        } catch (Exception $e) {
        
        }
        
        if (isset($obj)) {
            return "$path/$name";
        }
        
        return false;

     }
    
    /**
     * Create symlink
     *
     * @param  string  $source     file to link to
     * @param  string  $targetDir  folder to create link in
     * @param  string  $name       symlink name
     * @return bool
     * @author Dmitry (dio) Levashov
     **/
     protected function _symlink($source, $targetDir, $name) {
        return false;
     }

    /**
     * Copy file into another file (only inside one volume)
     *
     * @param  string  $source  source file path
     * @param  string  $targetDir  target dir path
     * @param  string  $name    file name
     * @return bool
     * @author Dmitry (dio) Levashov
     **/
     protected function _copy($source, $targetDir, $name) {
         $target = $this->_joinPath($targetDir, $name);

         try {
             $this->s3->copyObject([
                 'Bucket' => $this->options['bucket'],
                 'CopySource' => $this->getOption('bucket') . $source,
                 'Key' => $target,
                 'ACL' => 'public-read'
             ]);
         } catch (Exception $e) {
             return false;
         }

         clearstatcache();
         return $target;
     }
    
    /**
     * Move file into another parent dir.
     * Return new file path or false.
     *
     * @param  string  $source  source file path
     * @param  string  $targetDir  target dir path
     * @param  string  $name    file name
     * @return string|bool
     * @author Dmitry (dio) Levashov
     **/
    protected function _move($source, $targetDir, $name) {
        try {
            $target = $this->_copy($source, $targetDir, $name);
            $this->_unlink($source);

            clearstatcache();
            return $target;
        } catch (Exception $e) {

        }

        return false;
    }
    
    /**
     * Remove file
     *
     * @param  string  $path  file path
     * @return bool
     * @author Dmitry (dio) Levashov
     **/
    protected function _unlink($path) {
        
        $newkey = $this->_normpath($path);
        $newkey = preg_replace("/\/$/", "", $newkey);

        try {
            $obj = $this->s3->DeleteObject(array('Bucket' => $this->options['bucket'], 'Key' => $newkey));
            // TODO: check it?
            return true;
        } catch (Exception $e) {
        
        }
        
        return false;
    }

    /**
     * Remove dir
     *
     * @param  string  $path  dir path
     * @return bool
     * @author Rikhard (Richi) Gildman
     **/
    protected function _rmdir($path) {
        // TODO:
        try {
            $this->s3->deleteMatchingObjects($this->options['bucket'], $path . '/');
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Create new file and write into it from file pointer.
     * Return new file path or false on error.
     *
     * @param  resource $fp file pointer
     * @param  string $dir target dir path
     * @param  string $name file name
     * @param  array $stat
     * @return bool|string
     * @author Dmitry (dio) Levashov
     */
    protected function _save($fp, $dir, $name, $stat) {
        $path = $this->_joinPath($dir, $name);

        $data = [];
        $data['ACL'] = 'public-read';

        $data['Bucket'] = $this->options['bucket'];

        // TODO: normalize?
        $data['Key'] = $path;

        // get mime type
        $meta = stream_get_meta_data($fp);
        $uri = isset($meta['uri'])? $meta['uri'] : '';

        if (empty($uri)) {
            return false;
        }

        $data['ContentType'] = mime_content_type($uri);

        $data['Body'] = $fp;

        try {
            $this->s3->putObject($data);

            clearstatcache();
            return $path;
        } catch (Exception $e) {}

        return false;
    }
    
    /**
     * Get file contents
     *
     * @param  string  $path  file path
     * @return string|false
     * @author Dmitry (dio) Levashov
     **/
    protected function _getContents($path) {
        return false;
    }
    
    /**
     * Write a string to a file
     *
     * @param  string  $path     file path
     * @param  string  $content  new file content
     * @return bool
     * @author Dmitry (dio) Levashov
     **/
    protected function _filePutContents($path, $content) {
        return false;
    }

    /**
     * Extract files from archive
     *
     * @param  string  $path file path
     * @param  array   $arc  archiver options
     * @return bool
     * @author Dmitry (dio) Levashov, 
     * @author Alexey Sukhotin
     **/
     protected function _extract($path, $arc) {
        return false;
     }

    /**
     * Create archive and return its path
     *
     * @param  string  $dir    target dir
     * @param  array   $files  files names list
     * @param  string  $name   archive name
     * @param  array   $arc    archiver options
     * @return string|bool
     * @author Dmitry (dio) Levashov, 
     * @author Alexey Sukhotin
     **/
    protected function _archive($dir, $files, $name, $arc) {
        return false;
    }

    /**
     * Detect available archivers
     *
     * @return void
     * @author Dmitry (dio) Levashov, 
     * @author Alexey Sukhotin
     **/
    protected function _checkArchivers() {
        
    }

    /**
     * chmod implementation
     *
     * @param string $path
     * @param string $mode
     * @return bool
     */
    protected function _chmod($path, $mode) {
        return false;
    }

}