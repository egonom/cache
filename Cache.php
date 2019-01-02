<?php
namespace egonom\simplecache;

use egonom\simplecache\driver\Cacheuserfile;
use Slim\Slim;

class Cache {

	protected $valid_drivers 	= array(
		'cache_apc', 'cache_file', 'cache_memcached', 'cache_dummy', 'cache_userfile'
	);

	protected $_cache_path		= NULL;		// Path of cache files (if file-based cache)
	protected $_relative_cache_path		= NULL;		// Path of cache files (if file-based cache)
	protected $_relative_cache_file		= NULL;

	protected $_adapter			= 'dummy';
	protected $_cached_content;

	protected $_cache_type = 'data';	//html, data
	protected $_cache_type_prefix = 'cclData';	//cclHtml, cclData, cclBoth

	protected $_cache_operator = null;	//drop, skip
	protected $_cache_items = array();	//tömb, a törölni/kihagyni kívánt elemekkel

	protected $_database;

	// ------------------------------------------------------------------------

	/**
	 * Constructor
	 *
	 * @param array
	 */
	public function __construct($config = array())
	{

		if ( ! empty($config))
		{
			$this->_initialize($config);
		}

	}

	// ------------------------------------------------------------------------
	/**
	 * Initialize
	 *
	 * Initialize class properties based on the configuration array.
	 *
	 * @param	array
	 * @return 	void
	 */
	/*private*/
	function _initialize($config)
	{
		$default_config = array(
			'adapter',
			'memcached',
			'cache_operator',
			'cache_items',
		);

		foreach ($default_config as $key)
		{
			if (isset($config[$key]))
			{
				$param = '_'.$key;

				$this->{$param} = $config[$key];
			}
		}

//		dv($config);
//		dv( $this->valid_drivers);
		if (isset($config['adapter']))
		{
			if (in_array('cache_'.$config['adapter'], $this->valid_drivers))
			{
//dv($this->_adapter);
				if(!$this->is_supported($this->_adapter)){
					dve('unsupported cache type');
				}
			}
		}

		if (isset($config['type']))
		{
			$this->_cache_type = $config['type'];
			$this->_cache_type_prefix = 'ccl'.ucfirst($config['type']);
		}

//dv($this->_adapter);
		if (array_key_exists('hash', $config))
		{
//dv($config);
//dv($this->_adapter);
			$this->{$this->_adapter}->init($config['hash'], $this->_cache_type);
//dve(2);
		} else {
//dve(3);
			$this->{$this->_adapter}->init(null);
//dve(4);
		}
	}

	// ------------------------------------------------------------------------

	/**
	 * Get
	 *
	 * Look for a value in the cache.  If it exists, return the data
	 * if not, return FALSE
	 *
	 * @param 	string
	 * @return 	mixed		value that is stored/FALSE on failure
	 */
	public function getCache($id)
	{
		//ha megegyezik a cache típusa a törölni tervezett cache típussal, akkor -> kuka
		//ha bármilyen cache törlést kérünk, akkor a html cache nem fog legenerálódni újra
		//maga a kod vegigfut, mert a htaccess-ben a ccl-es szabalyok raviszik, tehat az index.html-ek
		//elott meg megfogja. Emiatt a data cache-ek újra legyártódnak.

		if (!empty($this->_cache_operator)) {
			$op = $this->_cache_operator;
			if($op == 'skip'){

				if (empty($this->_cache_items[$op]) || $this->_cache_items[$op] == 'all' || in_array($id, $this->_cache_items[$op])) {
					return false;
				}
			} else if($op == 'drop'){
				if (empty($this->_cache_items[$op]) || $this->_cache_items[$op] == 'all' || in_array($id, $this->_cache_items[$op])) {
					$this->{$this->_adapter}->delete($id);
				}
				return false;
			}
		}


		if (isDev() && !empty($_COOKIE['nocache'])) {
			return false;
		}

		$retval = $this->{$this->_adapter}->getCache($id);
		return $retval;
	}

	// ------------------------------------------------------------------------

	/**
	 * Cache Save
	 *
	 * @param 	string		Unique Key
	 * @param 	mixed		Data to store
	 * @param 	int			Length of time (in seconds) to cache the data
	 *
	 * @return 	boolean		true on success/false on failure
	 */
	public function save($id, $data, $ttl = 60)
	{
		if (!empty($_COOKIE['nocache'])) {
			return false;	//vagy true, ami szimpatikusabb
		}
		//ha van cache törlési kérés, ami megfelel típusban, akkor nem generáljuk újra
		if (defined('CACHE_CLEAR')) {

			if ($this->_cache_type === CACHE_CLEAR
				|| CACHE_CLEAR === 'cclBoth'
				|| CACHE_CLEAR === 'cclPerm'
			) {
				return false;
			}
		}


		$this->_cached_content = $data;
		$return = $this->{$this->_adapter}->save($id, $data, $ttl);

		if ($this->_cache_type == 'html'){
			$parsed_url = parse_url($_SERVER['REQUEST_URI']);
			//ha van bármilyen cache törlési kérés, akkor nem mentjük az url-ek közé
			$this->_storeCache($parsed_url['path'], defined('CACHE_CLEAR'));
		}

		return $return;
	}

	// ------------------------------------------------------------------------

	/**
	 * dropAllUserCache from Cache
	 *
	 * @param 	mixed		unique identifier of the item in the cache
	 * @return 	boolean		true on success/false on failure
	 */
	public function dropAllUserCache($id)
	{
		return $this->{$this->_adapter}->dropAllUserCache($id);
	}

	// ------------------------------------------------------------------------

	/**
	 * Delete from Cache
	 *
	 * @param 	mixed		unique identifier of the item in the cache
	 * @return 	boolean		true on success/false on failure
	 */
	public function delete($id)
	{
		return $this->{$this->_adapter}->delete($id);
	}

	// ------------------------------------------------------------------------

	/**
	 * Clean the cache
	 *
	 * @return 	boolean		false on failure/true on success
	 */
	public function clean()
	{
		return $this->{$this->_adapter}->clean();
	}

	// ------------------------------------------------------------------------

	/**
	 * Cache Info
	 *
	 * @param 	string		user/filehits
	 * @return 	mixed		array on success, false on failure
	 */
	public function cache_info($type = 'user')
	{
		return $this->{$this->_adapter}->cache_info($type);
	}

	// ------------------------------------------------------------------------

	/**
	 * Get Cache Metadata
	 *
	 * @param 	mixed		key to get cache metadata on
	 * @return 	mixed		return value from child method
	 */
	public function get_metadata($id)
	{
		return $this->{$this->_adapter}->get_metadata($id);
	}

	// ------------------------------------------------------------------------

	/**
	 * Erre amiatt van szükség, mert a CI alapbol nem huzza be ujra a load-dal a cache-t, így
	 * ha vegyesen van a kódban common és user cache, akkor elqrodik 2013.08.17.
	 */
	function setHash($hash) {
		$this->{$this->_adapter}->init($hash);
	}

	// ------------------------------------------------------------------------

	/**
	 * Is the requested driver supported in this environment?
	 *
	 * @param 	string	The driver to test.
	 * @return 	array
	 */
	public function is_supported($driver)
	{
		static $support = array();

		if ( ! isset($support[$driver]))
		{
			$classname = 'App\\driver\\Cache'.$driver;
			$cache_driver = new $classname();

			$this->{$driver} = $cache_driver;

//			$support[$driver] = $this->{$driver}->is_supported();
			$support[$driver] = $this->{$driver};
		} else {
			$this->{$driver} = $support[$driver];
		}

		return $support[$driver];
	}

	// ------------------------------------------------------------------------

	/**
	 * __get()
	 *
	 * @param 	child
	 * @return 	object
	 */
	public function __get($child)
	{
		if ( ! $this->is_supported($child)) {
			$obj = new $child;
			return $obj;

		} else {
			if (!isset($this->{$child})) {

				$classname = 'Cache'.$child;

				$cache_driver = new $classname;

				$this->{$child} = $cache_driver;
				$support[$child] = $this->{$child}->is_supported();

			}
			return $this->{$child};
		}

	}

	/**
	 * A cached_url tábla alapján újrageneráljuk a htaccess-t
	 *
	 */
	function reGenerateHtaccess($skip = 'nincsilyenurl'){
		$slim = Slim::getInstance();
		$this->_database = empty($slim->dbconnection) ? NULL : $slim->dbconnection;

		try {
			$sql = "
			SELECT
				*
			FROM
				cached_url
			WHERE
				expire_on_time > NOW()
			";
			$sth = $this->_database->prepare($sql);

			$sth->execute();

			$urls_to_cache = $sth->fetchAll(\PDO::FETCH_ASSOC);

		} catch (PDOException $pe) {
			logol("Error occurred:" . $pe->getMessage(), 'pdo');
		}

		if (!empty($urls_to_cache)) {

			$url_array = array();

			$cacheurl = new cached_url();

			foreach($urls_to_cache AS $url_to_cache) {


				//ha az url cache törlésre van megjelölve
				if ($url_to_cache == $skip) {

					unlink($this->_getCacheFilePath($url_to_cache['cache_path']));

				} else {
					if ( $this->_isCacheExists($url_to_cache['cache_path'])) {

						$size = filesize($this->_getCacheFilePath($url_to_cache['cache_path']));

						$cacheurl->getRow($url_to_cache['id']);
						$cacheurl->row_data['cache_size'] = $size;

						$cacheurl->save();

						$url_array[$url_to_cache['full_url']] = $url_to_cache['cache_path'];
					}

				}

			}

			//vannak cache-ek, amiket htaccess-elni kell
			if (!empty($url_array)) {

				$template = file_get_contents(getcwd().DIRECTORY_SEPARATOR.'htacc.tpl');

//			RewriteCond %{REQUEST_URI} ^/c/
//			RewriteRule ^/c/(.*)$ /_cache/output/$1/index.html [L]
				foreach($url_array AS $full_url => $cache_path){

					if ($this->_cache_type != 'html' || (defined('CACHE_CLEAR') && CACHE_CLEAR == 'cclHtml')) {
						$a = 1;
						continue;
					}

					//átmásoljuk az unserializált cache-t a htaccess-es könyvtárba
					$serialized = file_get_contents($this->_getCacheFilePath($cache_path));
					$unserialized = unserialize($serialized);

					$ht_path = getcwd().DIRECTORY_SEPARATOR.'_cache'.DIRECTORY_SEPARATOR.'output'.str_replace('/', DIRECTORY_SEPARATOR, $full_url);

//dv($unserialized, $this->_cache_type);
					file_put_contents(getOrMakeDir($ht_path).DIRECTORY_SEPARATOR.'index.html', $unserialized['data']);

					$url_array[$full_url]  = 'RewriteCond %{REQUEST_URI} ^'.$full_url.'$'.RN;
					$url_array[$full_url] .= 'RewriteRule ^(.*)$ /_cache/output/$1/index.html [L]';
				}
				if(!defined('CACHE_CLEAR') || CACHE_CLEAR != 'cclHtml') {
					$c = 1;
					$content = str_replace('#cache', implode(RN.RN, $url_array).RN.RN.'#cache', $template, $c);

					file_put_contents(getcwd().DIRECTORY_SEPARATOR.'.htaccess.tmp', $content);
					rename(getcwd().DIRECTORY_SEPARATOR.'.htaccess.tmp', getcwd().DIRECTORY_SEPARATOR.'.htaccess');
				}
			}
		}


	}

	function _getCacheFilePath($relative_path){

		return getcwd().DIRECTORY_SEPARATOR.$relative_path;
	}

	function _isCacheExists($relative_path){

		return is_file($this->_getCacheFilePath($relative_path));
	}

	function _placeContent($path_url){

		if (!empty($this->_cached_content)) {

			$output_cache_file = $this->_getCacheFilePath($path_url);
			file_put_contents($output_cache_file, $this->_cached_content);

			return filesize($output_cache_file);
		}

		return false;
	}

	function _purgeCache($skip_paths){
		recursiveRemoveDirectory(getcwd().DIRECTORY_SEPARATOR.'_cache'.DIRECTORY_SEPARATOR.'output', true, $skip_paths);

	}

	function _storeCache($url, $skip = false){

		if ($skip) {
			$this->reGenerateHtaccess($url);
		} else {
			$cu = new cached_url();
			$row = $cu->searchRow('full_url', $url);

			if (empty($row)) {
				$cu->createRow();
				$cu->row_data['lang_code'] = getLang();
				$cu->row_data['full_url'] = $url;
				$cu->row_data['cache_size'] = 0;
			}
			$cu->row_data['visible'] = 'true';
			$cu->row_data['cache_path'] = $this->{$this->_adapter}->_relative_cache_file;
			$cu->row_data['expire_on_time'] = later(300, 'i', 'Y-m-d H:i:s');
			$cu->save();
			$this->reGenerateHtaccess();
		}


	}
}
