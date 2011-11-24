<?php
/**
 * Menu Component
 *
 * Uses ACL to generate Menus.
 *
 * Copyright 2008, Mark Story.
 *
 * Licensed under The MIT License
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright Copyright 2008, Mark Story.
 * @link http://mark-story.com
 * @version 1.1
 * @author Mark Story <mark@mark-story.com>
 * @license http://www.opensource.org/licenses/mit-license.php The MIT License
 */
class MenuComponent extends Component {
/**
 * The Default Menu Parent for things that have no parent element defined
 * used a lot by menu items generated by controller folder scrapings
 *
 * @var string
 */
	public $defaultMenuParent = null;
/**
 * Set to false to disable the auto menu generation in startup()
 * Useful if you want your menus generated off of Aro's other than the user in the current session.
 *
 * @var boolean
 */
	public $autoLoad = true;
/**
 * Controller reference
 *
 * @var object
 */	
	public $Controller = null;
/**
 * Components used by Menu
 *
 * @var array
 */
	public $components = array('Acl', 'Auth');
	
/**
 * Key for the caching
 *
 * @var string
 */
	public $cacheKey = 'menu_storage';
	
/**
 * Time to cache menus for.
 *
 * @var string  String compatible with strtotime.
 */
	public $cacheTime = '+1 day';
/**
 * cache config key
 *
 * @var string
 */
	public $cacheConfig = 'menu_component';
/**
 * Separator between controller and action name.
 *
 * @var string
 */
	public $aclSeparator = '/';
	
/**
 * The Node path to get to the controller listing
 *
 * @var string
 **/
	public $aclPath = 'controllers/';

/**
 * Array of Actions to exclude when making menus.
 * Per controller exclusions can be set with Controller::menuOptions
 *
 * @var array
 **/
	public $excludeActions = array('view', 'edit', 'delete', 'admin_edit', 'admin_delete', 'admin_edit', 'admin_view');
	
/**
 * Completed list of methods to not include in menus. Includes all of Controller's methods.
 *
 * @var array
 **/
	public $excludedMethods = array();
/**
 * The Completed menu for the current user.
 *
 * @var array
 */
	public $menu = array();	
	
/**
 * Raw menus before formatting, either loaded from parsing controllers directory or loading Cache
 *
 * @var array
 */
	public $rawMenus = array();
/**
 * Internal Flag to check if new menus have been added to a cached menu set.  Indicates that new menu items
 * have been added and that menus need to be rebuilt.
 * 
 */
	protected $_rebuildMenus = false;

/**
 * initialize function
 *
 * Takes Settings declared in Controller and assigns them.
 *
 * @return bool
 **/
	public function initialize($Controller, $settings = array()) {
		if (!empty($settings)) {
			$this->_set($settings);
		}
		return true;
	}
	
/**
 * Startup Method
 *
 * Automatically makes menus for all a the controllers based on the current user.
 * If $this->autoLoad = false then you must manually loadCache(), 
 * contstructMenu() and writeCache().
 *
 * @param Object $Controller
 */
	public function startup($Controller) {
		$this->Controller = $Controller;
		
		Cache::config($this->cacheConfig, array('engine' => 'File', 'duration' => $this->cacheTime, 'prefix' => $this->cacheKey));
		
		//no active session, no menu can be generated
		if (!$this->Auth->user()) {
			return;
		}
		if ($this->autoLoad) {
			$this->loadCache();
			$this->constructMenu($this->Auth->user());
			$this->writeCache();
		}
	}

/**
 * Write the current Block Access data to a file.
 *
 * @return boolean on success of writing a file.
 */
	public function writeCache() {
		$data = array(
			'menus' => $this->rawMenus
		);
		if (Cache::write($this->cacheKey, $data, $this->cacheConfig)) {
			return true;
		}
		$this->log('Menu Component - Could not write Menu cache.');
		return false;
	}
	
/**
 * Load the Cached Permissions and restore them
 *
 * @return boolean true if cache was loaded.
 */
	public function loadCache() {
		if ($data = Cache::read($this->cacheKey, $this->cacheConfig)) {
			$this->rawMenus = $this->_mergeMenuCache($data['menus']);
			return true;
		}
		$this->_rebuildMenus = true;
		return false;
	}

/**
 * Clears the raw Menu Cache, this will in turn force
 * a menu rebuild for each ARO that needs a menu.
 *
 * @return boolean
 **/
	public function clearCache() {
		return Cache::delete($this->cacheKey, $this->cacheConfig);
	}

/**
 * Construct the menus From the Controllers in the Application.  This is an expensive
 * Process Timewise and is cached.
 *
 * @param string $aro  Aro Alias / identification array that a menu is needed for.
 */	
	public function constructMenu($aro) {
		$aroKey = $aro;
		if (is_array($aro)) {
			$aroKey = key($aro) . $aro[key($aro)]['id'];
		}
		$cacheKey = $aroKey . '_' . $this->cacheKey;
		$completeMenu = Cache::read($cacheKey, $this->cacheConfig);
		if (!$completeMenu || $this->_rebuildMenus == true) {
			$this->generateRawMenus();
			$menu = array();
			$size = count($this->rawMenus);
			for ($i = 0; $i < $size; $i++) {
				$item = $this->rawMenus[$i];
				$aco = Inflector::camelize($item['url']['controller']);
				if (isset($item['url']['action'])) {
					$aco = $this->aclPath . $aco . $this->aclSeparator . $item['url']['action'];
				}
				if ($this->Acl->check($aro, $aco)) {
					if (!isset($menu[$item['id']])) {
						$menu[$item['id']] = $this->rawMenus[$i];
					}
				}
			}
			$completeMenu = $this->_formatMenu($menu);
			Cache::write($cacheKey, $completeMenu, $this->cacheConfig);
		}
		$this->menu = $completeMenu;
	}
	
/**
 * Generate Raw Menus from Controller in the Application
 * Loads a list of All controllers in the app/controllers, imports the class and gets a method
 * list.  Uses a common exclusion list to remove unwanted methods.  Each Controller can specify a 
 * menuOptions var which allows additional menu configuration.
 * 
 * Menu Options for Controllers:
 * 		exclude => actions to exclude from the menu list
 * 		parent => Parent link to add a controller / actions underneath
 * 		alias => array of action => aliases Allows you to set friendly link names for actions
 *
 * @return void sets $this->rawMenus
 */	
	public function generateRawMenus() {
		$Controllers = $this->getControllers();
		$cakeAdmin = Configure::read('Routing.prefixes.0');
		$this->createExclusions();
		
		//go through the controllers folder and make an array of every menu that could be used.
		foreach($Controllers as $Controller) {
			if ($Controller == 'App') {
				continue;
			}
			$ctrlName = $Controller;
			App::import('Controller', $ctrlName);
			$ctrlclass = $ctrlName . 'Controller';
			$methods = get_class_methods($ctrlclass);
			
			$classVars = get_class_vars($ctrlclass);
			$menuOptions = $this->setOptions($classVars);
			if ($menuOptions === false) {
				continue;
			}
			$methods = $this->filterMethods($methods, $menuOptions['exclude']);
			
			$ctrlCamel = Inflector::variable($ctrlName);
			$ctrlHuman = Inflector::humanize(Inflector::underscore($ctrlCamel));
			$methodList = array();
			$adminController = false;
			foreach ($methods as $action) {
				$camelAction = Inflector::variable($action);
				if (empty($menuOptions['alias']) || !isset($menuOptions['alias'][$action])) {
					$human = Inflector::humanize(Inflector::underscore($action));
				} else {
					$human = $menuOptions['alias'][$action];
				}
				
				$url = array(
					'controller' => $ctrlCamel,
					'action' => $action
				);
				if ($cakeAdmin) {
					$url[$cakeAdmin] = false;
				}
				if (strpos($action, $cakeAdmin . '_') !== false && $cakeAdmin) {
					$url[$cakeAdmin] = true;
					$adminController = true;
				}

				$parent = $menuOptions['controllerButton'] ? $ctrlCamel : $menuOptions['parent'];
				$this->rawMenus[] = array(
					'parent' => $parent,
					'id' => $this->_createId($ctrlCamel, $action),
					'title' => $human,
					'url' => $url,
					'weight' => 0,
				);
			}
			if ($menuOptions['controllerButton']) {
				//If an admin index exists use it.
				$action = $adminController ? $cakeAdmin . '_index' : 'index';
				$url = array(
					'controller' => $ctrlCamel,
					'action' => $action,
					'admin' => $adminController,
				);
				$menuItem = array(
					'parent' => $menuOptions['parent'],
					'id' => $ctrlCamel,
					'title' => $ctrlHuman,
					'url' => $url,
					'weight' => 0
				);
				$this->rawMenus[] = $menuItem;
			}
		}
	}
	
/**
 * Get the Controllers in the Application
 *
 * @access public
 * @return void
 */
	public function getControllers() {
		return App::objects('Controller');
	}
	
/**
 * filter out methods based on $menuOptions.
 * Removes private actions as well.
 *
 * @param array $methods  Array of methods to prepare
 * @param array $remove Array of additional Methods to remove, normally options on the controller.
 * @return array
 **/
	public function filterMethods($methods, $remove = array()) {
		if (!empty($remove)) {
			$remove = array_map('strtolower', $remove);
		}
		$exclusions = array_merge($this->excludedMethods, $remove);
		foreach ($methods as $k => $method) {
			$method = strtolower($method);
			if (strpos($method, '_', 0) === 0) {
				unset($methods[$k]);
			}
			if (in_array($method, $exclusions)) {
				unset($methods[$k]);
			}
		}
		return array_values($methods);
	}
	
/**
 * Set the Options for the current Controller.
 *
 * @return mixed.  Array of options or false on total exclusion
 **/
	public function setOptions($controllerVars) {
		$cakeAdmin = Configure::read('Routing.prefixes.0');
		$menuOptions = isset($controllerVars['menuOptions']) ? $controllerVars['menuOptions'] : array();

		$exclude = array('view', 'edit', 'delete', $cakeAdmin . '_edit', 
			$cakeAdmin . '_delete', $cakeAdmin . '_edit', $cakeAdmin . '_view');

		$defaults = array(
			'exclude' => $exclude, 
			'alias' => array(), 
			'parent' => $this->defaultMenuParent, 
			'controllerButton' => true
		);
		$menuOptions = Set::merge($defaults, $menuOptions);
		if (in_array('*', (array)$menuOptions['exclude'])) {
			return false;
		}
		return $menuOptions;
	}
	
/**
 * Creates the Exclusions for generating menus.
 *
 * @return void
 **/
	public function createExclusions() {
		$methods = array_merge(get_class_methods('Controller'), $this->excludeActions);
		$this->excludedMethods = array_map('strtolower', $methods);
	}
/**
 * Add a Menu Item.
 * Allows manual Insertion into the menu system.
 * If Added after constructMenu()  It will not be shown
 *
 * @param string $parent
 * @param array $menu
 * 		'Menu' Array
 * 			'title' => name
 * 			'url' => url array of menu, url strings are lame and won't work
 * 			'key' => unique name of this menu for parenting purposes.
 * 			'controller' => controller Name this action is from
 */
	public function addMenu($menu) {
		$defaults = array(
			'title' => null,
			'url' => null,
			'parent' => null,
			'id' => null,
			'weight' => 0,
		);
		$menu = array_merge($defaults, $menu);
		if (!$menu['id'] && isset($menu['url'])) {
			$menu['id'] = $this->_createId($menu['url']);
		}
		if (!$menu['title'] && isset($menu['url']['action'])) {
			$menu['title'] = Inflector::humanize($menu['url']['action']);
		}
		$this->rawMenus[] = $menu;
	}

/**
 * BeforeRender Callback.
 *
 */
	public function beforeRender() {
		if(!$this->Controller) return;
		$this->Controller->set('menu', $this->menu);
	}
	
/**
 * Make a Unique Menu item key
 *
 * @param array $parts
 * @return string Unique key name
 */
	protected function _createId() {
		$parts = func_get_args();
		if (is_array($parts[0])) {
			$parts = $parts[0];
		}
		$key = Inflector::variable(implode('-', $parts));
		return $key;
	}

/**
 * Recursive function to construct Menu
 *
 * @param unknown_type $menu
 * @param unknown_type $parentId
 */
	protected function _formatMenu($menu) {
		$out = $idMap = array();
		foreach ($menu as $item) {
			$item['children'] = array();
			$id = $item['id'];
			$parentId = $item['parent'];
			if (isset($idMap[$id]['children'])) {
				$idMap[$id] = am($item, $idMap[$id]);
			} else {
				$idMap[$id] = am($item, array('children' => array()));
			}
			if ($parentId) {
				$idMap[$parentId]['children'][] =& $idMap[$id];
			} else {
				$out[] =& $idMap[$id];
			}
		}
		usort($out, array(&$this, '_sortMenu'));
		return $out;
	}

/**
 * Sort the menu before returning it. Used with usort()
 *
 * @return int
 **/
	protected function _sortMenu($one, $two) {
		if ($one['weight'] == $two['weight']) {
			return 1;
		}
		return ($one['weight'] < $two['weight']) ? -1 : 1;
	}
/**
 * Merge the Cached menus with the Menus added in Controller::beforeFilter to ensure they are unique.
 *
 * @param array $cachedMenus
 * @return array Merged Menus
 */
	protected function _mergeMenuCache($cachedMenus) {
		$cacheCount = sizeOf($cachedMenus);
		$currentCount = sizeOf($this->rawMenus);
		$tmp = array();
		for ($i = 0; $i < $currentCount; $i++) {
			$exist = false;
			$addedMenu = $this->rawMenus[$i];
			for ($j =0; $j < $cacheCount; $j++) {
				$cachedItem = $cachedMenus[$j];
				if ($addedMenu['id'] == $cachedItem['id']) {
					$exist = true;
					break;
				}
			}
			if ($exist) {
				continue;
			}
			$tmp[] = $addedMenu;
		}
		if (!empty($tmp)) {
			$this->_rebuildMenus = true;
		}
		return array_merge($cachedMenus, $tmp);
	}

}
?>