<?php
namespace Softdream\Plugin;


use Phalcon\Events\Event,
        Phalcon\Mvc\Dispatcher;

/**
 * AutoRoute class, depends ErrorController and 404Action in any module to follow non exist path's to error pages.
 *
 * @author Webvizitky, Softdream <info@webvizitky.cz>,<info@softdream.net>
 * @copyright (c) 2013, Softdream, Webvizitky
 * @package name
 * @category name
 * 
 */
class AutoRoute {
    

    /**
     * @var \Phalcon\Mvc\Application
     */
    protected $application;
    /**
     * @var \Phalcon\Mvc\Router
     */
    protected $router;
    /**
     * @var \Phalcon\DI
     */
    protected $di;
    /**
     * @var \Phalcon\Mvc\Dispatcher
     */
    protected $dispatcher;
    /**
     * @var String module name
     */
    protected $module;
    /**
     * @var array Actual module info, if exist
     */
    protected $moduleInfo;
    /**
     * @var String Actual controller name
     */
    protected $controller;
    /**
     * @var String Actual action name
     */
    protected $action;
    
    /**
     * @var array List of active modules
     */
    protected $modules = array();
    /**
     * @var array List of params
     */
    protected $params = array();
    /**
     * @var int|false false when the module was not found in url 
     */
    protected $urlModulePosition = 0;
    /**
     * @var int|false false when the controller was not found in url 
     */
    protected $urlControllerPosition = 1;
    /**
     * @var int|false false when the action was not found in url 
     */
    protected $urlActionPosition = 2;
    /**
     * @var Softdream\Http\Request
     */
    protected $request;
    
    public function __construct() {
	
    }
    
    /**
     * Predefined method for application event handler
     * the method will be called before load and start 
     */
    public function boot(Event $event,\Phalcon\Mvc\Application $application){
	$this->setPluginData($application);
	
	$this->registerNamespaces();
	//set dispatch parameters
	$this->setDispatchParams();	
	$this->registerServices();
	
	//reset request to catch cleaned params
	$this->request->removeMap();
	$this->request->clearItems();
	//parse url without module/c
	$this->request->parseUri();
	
	$this->di->set('request',$this->request);
	$this->router->setDefaultModule($this->module);
	$this->router->setDefaultAction($this->action);
	$this->router->setDefaultController($this->controller);
    }
    
    protected function registerServices(){
	$this->di->set('dispatcher', function(){
            $dispatcher = new \Phalcon\Mvc\Dispatcher();
	    $dispatcher->setDefaultNamespace(ucfirst($this->module).'\Controller');
            return $dispatcher;
        });
	
	
    }

    
    /**
     * Set main Class variables to correct plugin work
     * @param \Phalcon\Mvc\Application $application Full prepared application object     * 
     */
    protected function setPluginData(\Phalcon\Mvc\Application $application){
	$this->application = $application;
	$this->di = $this->application->getDI();
	$this->router = $this->di->get('router');
	$this->modules = $this->application->getModules();
	$config = $this->di->get("config");
	$mapString = (isset($config->application->baseUri) && $config->application->baseUri !== '/') ? '/:baseurl' : '';
	$mapString .= '/:module/:controller/:action';
	$this->request = new \Softdream\Http\Request(new \Softdream\Http\Url\Map($mapString));
	if($this->request->getParam('baseurl')){
	    $this->request->removeParam('baseurl');
	}
    }
    
    /**
     * Register namespaces to correct working class_exist function
     */
    protected function registerNamespaces(){
	$loader = new \Phalcon\Loader();
	$namespaces = array();
	foreach($this->modules as $moduleName => $module){
	    $namespaces[ucfirst($moduleName).'\Controller'] = '../app/'.  ucfirst($moduleName).'Module/controller';
	    $namespaces[ucfirst($moduleName).'\Model'] = '../app/'.ucfirst($moduleName).'Module/model';
	}
	
        $loader->registerNamespaces($namespaces);
        $loader->register();
    }

    
    /**
     * Set variables module,controller,action
     */
    protected function setDispatchParams(){
	if(!$this->module){
	    $this->setModule();
	}
	
	if(!$this->controller){
	    $this->setController();
	}
	
	if(!$this->action){
	    $this->setAction();
	}
    }
    
    /**
     * set object module variable
     */
    protected function setModule(){
	//check if module exist if yes prepare default or find module by first parameter in url
	if(!empty($this->modules)){
	    $this->module = $this->router->getDefaultModule();
	    $module = $this->request->getParam('module');
	    if($module && isset($this->modules[$module])){
		$this->moduleInfo = $this->modules[$this->request->getParam('module')];
		$this->module = $module;
		$this->request->removeParam('module');
	    }
	    else {
		$updateUrlMap = new Softdream\Http\Url\Map('/:controller/:action');
		$this->request->setMap($updateUrlMap);
		$this->moduleInfo = $this->modules[$this->module];
	    }
	}
    }
    
    /**
     * @param String $controllerClassName Controller class
     * @return boolean true when class exists false when not
     */
    protected function isControllerExist($controllerClassName){	
	return class_exists($controllerClassName,true);
    }
    
    /**
     * @param String $className Class name
     * @param String $actionName Full action name to check
     * @return boolean true when method in Object $className exists
     */
    protected function isActionExist($className,$actionName){
	return method_exists($className, $actionName);
    }
    
    /**
     * Set controller object varibale when:
     * 1. When url param founded by $urlControllerPosition and controller exist
     * 2. When controller from 1. doesnt exist, try to find default from configuration
     * 3. When 3. ( default ) controller doesnt exist set error controller 
     */
    protected function setController(){
	$controllerClass = null;
	$controller = $this->request->getParam('controller');
	//get controller from url	
	$controllerClass = '\\'.ucfirst($this->module).'\Controller\\'.$this->urlFormatToCamel($controller, true).'Controller';
	
	//if controller is not set in url or not exist
	if(!$this->isControllerExist($controllerClass))
	{
	    $urlMap = new Softdream\Http\Url\Map('/:action');
	    $this->request->setMap($urlMap);
	    $controller = isset($this->moduleInfo['defaultController']) ? $this->moduleInfo['defaultController'] : null;
//	    echo $controller;
	    $controllerClass = '\\'.ucfirst($this->module).'\Controller\\'.$this->urlFormatToCamel($controller, true).'Controller';
	    if(!$this->isControllerExist($controllerClass)){
		$controller = 'error';
	    }    
	}
	else {
	    $this->request->removeParam('controller');
	}
		
	$this->controller = strtolower($controller);
    }
    
    /**
     * Set controller object varibale when:
     * 1. When url param founded by $urlActionPosition and controller exist
     * 2. When action from 1. doesnt exist, try to find default from configuration
     * 3. When 3. ( default ) action doesnt exist set 404 action according to error controller
     * 4. When controller variable is set to error the variable will be set to 404 
     */
    protected function setAction(){
	$controllerClass = '\\'.ucfirst(strtolower($this->module)).'\Controller\\'.$this->urlFormatToCamel($this->controller, true).'Controller';
	$action = $this->request->getParam('action');
	$actionName = $this->urlFormatToCamel($action).'Action';
	
	if(!$this->isActionExist($controllerClass, $actionName)){
	    $urlMap = new Softdream\Http\Url\Map('/');
	    $this->request->setMap($urlMap);
	    $action = isset($this->moduleInfo['defaultAction']) ? $this->moduleInfo['defaultAction'] : null;
	    $actionName = $this->urlFormatToCamel($action).'Action';
	    if(!$action || !$this->isActionExist($controllerClass, $actionName) || $this->controller == 'error'){
		$action = 'index';
		if($this->controller === 'error' || 
			(isset($this->moduleInfo['defaultAction']) && $this->moduleInfo['defaultAction'] === $action) )
		{
		    $action = 'error404';
		    $this->controller = 'error';
		}
	    }
	}
	else {
	    $this->request->removeParam('action');
	}
	
	$this->action = $action;
	
	
    }
    
    /**
     * Convert url format to camel case format eg.: my-action will be replaced for myAction
     * @param string $string String part of url
     */
    protected function urlFormatToCamel($string,$firstCamel = false){	
	if(strpos($string, '-') !== false && $string !== null){
	    $tmpString = '';
	    $stringParts = explode("-",$string);
	    foreach($stringParts as $key => $part){
		if($key === 0){
		    $tmpString .= ($firstCamel === true) ? ucfirst(strtolower($part)) : strtolower($part);
		}
		else {
		    $tmpString .= ucfirst(strtolower($part));
		}
	    }
	    
	    return $tmpString;
	}
	
	return ($firstCamel) ? ucfirst(strtolower($string)) : strtolower($string);
    }
    
}

