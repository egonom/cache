<?php
namespace egonom\simplecache\cache\driver;

class Cacheuserfile {

	public $_cache_path;
	public $_relative_cache_path;
	public $_relative_cache_file;
	protected $_user_id;

	/**
	 * Constructor
	 */
	public function __construct($user_id = null)
	{
		$this->_user_id = $user_id;

		$this->_cache_path = getcwd().DIRECTORY_SEPARATOR.'_cache'.DIRECTORY_SEPARATOR;
		$this->_relative_cache_path = '_cache'.DIRECTORY_SEPARATOR;
	}

//közös cache használatához be kell állítani hash-t
	/**
	 * @param $hash
	 * @param $cache_type (html, data)
	 */
	function init($hash, $cache_type = 'data') {
		//ha már be van állítva egy cache path, akkor azt reseteljük, hogy ne legyenek egymásba ágyazott dolgok.
		if (!empty($this->_cache_path) && $this->_cache_path != getcwd().DIRECTORY_SEPARATOR.'_cache'.DIRECTORY_SEPARATOR) {
			$this->_cache_path = getcwd().DIRECTORY_SEPARATOR.'_cache'.DIRECTORY_SEPARATOR;
			$this->_relative_cache_path = '_cache'.DIRECTORY_SEPARATOR;
		}

		$this->_cache_path .= $cache_type.DIRECTORY_SEPARATOR;
		$this->_relative_cache_path .= $cache_type.DIRECTORY_SEPARATOR;
		if(empty($hash)) {
			if( ! empty($this->_user_id) ) {
				$id = $this->_user_id;
				$this->_cache_path .= ($id - $id % 1000) . DIRECTORY_SEPARATOR . $id . DIRECTORY_SEPARATOR;
				$this->_relative_cache_path .= ($id - $id % 1000) . DIRECTORY_SEPARATOR . $id . DIRECTORY_SEPARATOR;
			} else {
				$this->_cache_path .= 'common'.DIRECTORY_SEPARATOR;
				$this->_relative_cache_path .= 'common'.DIRECTORY_SEPARATOR;
			}
		} else {
			$this->_cache_path .= $hash . DIRECTORY_SEPARATOR;
			$this->_relative_cache_path .= $hash . DIRECTORY_SEPARATOR;
		}
	}

	// ------------------------------------------------------------------------

	/**
	 * Fetch from cache
	 *
	 * @param 	mixed		unique key id
	 * @return 	mixed		data on success/false on failure
	 */
	public function getCache($id)
	{
		$cachefile = $this->_cache_path.$id;

		if ( ! file_exists($cachefile))
		{
			return FALSE;
		}

		$data = file_get_contents($this->_cache_path.$id);
		$data = unserialize($data);

		if (time() >  $data['time'] + $data['ttl'])
		{
			unlink($this->_cache_path.$id);
			return FALSE;
		}

		return $data['data'];
	}

	// ------------------------------------------------------------------------

	/**
	 * Save into cache
	 *
	 * @param 	string		unique key
	 * @param 	mixed		data to store
	 * @param 	int			length of time (in seconds) the cache is valid
	 *						- Default is 60 seconds
	 * @return 	boolean		true on success/false on failure
	 */
	public function save($id, $data, $ttl = 60)
	{

		getOrMakeDir($this->_cache_path);

		$data = str_replace(array("\t"), '', $data);
		$contents = array(
			'time'		=> time(),
			'ttl'		=> $ttl,
			'data'		=> $data
		);

		if (write_file($this->_cache_path.$id, serialize($contents)))
		{
			$this->_relative_cache_file = $this->_relative_cache_path.$id;

			@chmod($this->_cache_path.$id, 0777);
			return TRUE;
		}

		return FALSE;
	}

	// ------------------------------------------------------------------------

	/**
	 * dropAllUserCache from Cache
	 *
	 * @param 	mixed		unique identifier of item in cache
	 * @return 	boolean		true on success/false on failure
	 */
	public function dropAllUserCache($id)
	{
		//felolvassuk a könyvtárat
		$handle = opendir($this->_cache_path);

		while (false !== ($file = readdir($handle))) {

			$name_array = explode('__', $file);
			if(
				count($name_array) == 2
				&&
				$name_array[1] == $id
			) {
				unlink($this->_cache_path.$file);
			}
		}

		closedir($handle);

		return true;
	}

	// ------------------------------------------------------------------------

	/**
	 * Delete from Cache
	 *
	 * @param 	mixed		unique identifier of item in cache
	 * @return 	boolean		true on success/false on failure
	 */
	public function delete($id)
	{
		$path = $this->_cache_path.$id;
		if (is_file($path)) {
			return unlink($path);
		}
		return true;
	}

	// ------------------------------------------------------------------------

	/**
	 * Clean the Cache
	 *
	 * @return 	boolean		false on failure/true on success
	 */
	public function clean()
	{
		return delete_files($this->_cache_path);
	}

	// ------------------------------------------------------------------------

	/**
	 * Cache Info
	 *
	 * Not supported by file-based caching
	 *
	 * @param 	string	user/filehits
	 * @return 	mixed 	FALSE
	 */
	public function cache_info($type = NULL)
	{
		return get_dir_file_info($this->_cache_path);
	}

	// ------------------------------------------------------------------------

	/**
	 * Get Cache Metadata
	 *
	 * @param 	mixed		key to get cache metadata on
	 * @return 	mixed		FALSE on failure, array on success.
	 */
	public function get_metadata($id)
	{
		if ( ! file_exists($this->_cache_path.$id))
		{
			return FALSE;
		}

		$data = read_file($this->_cache_path.$id);
		$data = unserialize($data);

		if (is_array($data))
		{
			$data = $data['data'];
			$mtime = filemtime($this->_cache_path.$id);

			if ( ! isset($data['ttl']))
			{
				return FALSE;
			}

			return array(
				'expire' 	=> $mtime + $data['ttl'],
				'mtime'		=> $mtime
			);
		}

		return FALSE;
	}

	// ------------------------------------------------------------------------

	/**
	 * Is supported
	 *
	 * In the file driver, check to see that the cache directory is indeed writable
	 *
	 * @return boolean
	 */
	public function is_supported()
	{
		return is_really_writable($this->_cache_path);
	}

	// ------------------------------------------------------------------------
}