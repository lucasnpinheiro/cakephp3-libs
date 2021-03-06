<?php
namespace Alaxos\Controller\Component;

use Cake\Controller\Component;
use Cake\Controller\ComponentRegistry;
use Cake\Controller\Controller;
use Alaxos\Event\TimezoneEventListener;
use Cake\Core\Configure;
use Cake\I18n\Time;
use Cake\Database\Query;
use Cake\Routing\Router;
use Alaxos\Lib\StringTool;
use Cake\Utility\Inflector;

class FilterComponent extends Component
{
    protected $_defaultConfig = [];
    
    /**
     * Holds the reference to Controller
     *
     * @var \Cake\Controller\Controller;
     */
    public $controller;
    
    /**
     * Holds the reference to Controller::$request
     *
     * @var \Cake\Network\Request
     */
    public $request;
    
   /**
    * Holds the reference to Controller::$response
    *
    * @var \Cake\Network\Response
    */
    public $response;
    
    
    public function __construct(ComponentRegistry $collection, array $config = array()) 
    {
        parent::__construct($collection, $config);
        
        $this->controller = $collection->getController();
        $this->request    = $this->controller->request;
        $this->response   = $this->controller->response;
    }
    
    /********************************************************************************/
    
    /**
     * $options:
     * ---------
     * 'check_referer'              indicates wether eventual existing filter for the current url must be reused only if the referer is the same url
     *                              true by default -> filter is preserved only during pagination navigation
     *                              
     * 'auto_wildcard_string'       true by default -> automatcally appends wildcard character '%' around search terms
     * 
     * @param array $options
     * @return \Cake\ORM\Query
     */
    public function getFilterQuery(array $options = array())
    {
        $default_options = ['check_referer'        => true,
                            'auto_wildcard_string' => true];
        
        $options = array_merge($default_options, $options);
        
        /*
         * Prepare Entity used to display search filters in the view
         */
        $this->prepareSearchEntity($options);
        
        
        $options['modelClass'] = isset($options['modelClass']) ? $options['modelClass'] : $this->controller->modelClass;
        
        $filter_data = null;
        
        if($this->request->is('post') || $this->request->is('put'))
        {
        	if(isset($this->request->data['Filter']) && !empty($this->request->data['Filter']))
        	{
        		$filter_data = $this->request->data['Filter'];
        	}
        }
        elseif($this->request->is('get'))
        {
            $current_path = $this->getComparisonPath($this->request->params);
            
            if($options['check_referer'])
            {
            	$referer_path = $this->getComparisonPath(Router::parse($this->request->referer(true)));
            	
            	if($referer_path == $current_path)
            	{
            	   $filter_data = $this->getStoredQuery($current_path, $options);
            	}
            }
            else
            {
                $filter_data = $this->getStoredQuery($current_path, $options);
            }
        }
        
        if(!empty($filter_data))
        {
            //debug($this->request->data);
        	$query = $this->controller->{$options['modelClass']}->find();
        	
        	//debug($query->__debugInfo()['params']);
        	
        	foreach($filter_data as $fieldName => $value)
        	{
        	    $has_value = false;
        	    
        	    if(is_array($value))
        	    {
        	        if(isset($value['__start__']) && (!empty($value['__start__']) || $value['__start__'] === '0'))
        	        {
        	            $has_value = true;
        	        }
        	        
        	        if(isset($value['__end__']) && (!empty($value['__end__']) || $value['__end__'] === '0'))
        	        {
        	            $has_value = true;
        	        }
        	    }
        	    elseif($value === '0')
        	    {
        	        $has_value = true;
        	    }
        	    else
        	    {
        	        $has_value = !empty($value);
        	    }
        	    
        		if($has_value)
        		{
        			$columnType = $this->controller->{$options['modelClass']}->schema()->columnType($fieldName);
        			
        			//$condition_fieldName = StringTool::ensure_start_with($fieldName, Inflector::singularize($options['modelClass']) . '.');
        			$fieldName = StringTool::ensure_start_with($fieldName, $options['modelClass'] . '.');
        			
        			//debug($columnType);
        			
        			switch($columnType)
        			{
        			    case 'integer':
        			    case 'float':
        			    	$this->_addNumericCondition($query, $fieldName, $value, $options);
        			    	break;
        	
        			    case 'datetime':
        			    	$this->_addDatetimeCondition($query, $fieldName, $value, $options);
        			    	break;
        	
        			    case 'string':
        			    	$this->_addStringCondition($query, $fieldName, $value, $options);
        			    	break;
        			    	
        			    case 'boolean':
        			        $this->_addBooleanCondition($query, $fieldName, $value, $options);
        			        break;
        			        
        			}
        		}
        	}
        	
        	/*
        	 * Store Query in session in order to be able to navigate to other list pages
        	 * without loosing the filters
        	 */
        	$path = $this->getComparisonPath($this->request->params);
        	$this->storeQuery($path, $filter_data);
        	
        	/*
        	 * Set request data if no already filled
        	 * (this is the case when navigating from page to page with pagination)
        	 */
        	if(!isset($this->request->data['Filter']))
        	{
        		$this->request->data['Filter'] = $filter_data;
        	}
        	
        	return $query;
        }
    }
    
    /**
     * Prepare Entity used to display search filters in the view
     * @param array $options
     * @return void
     */
    public function prepareSearchEntity(array $options = array())
    {
        $options['modelClass'] = isset($options['modelClass']) ? $options['modelClass'] : $this->controller->modelClass;
        
        $search_entity = $this->controller->{$options['modelClass']}->newEntity();
        $search_entity->accessible('*', true);
        $this->controller->{$options['modelClass']}->patchEntity($search_entity, $this->request->data);
        $this->controller->set(compact('search_entity'));
    }
    
    /********************************************************************************/
    
    protected function storeQuery($path, $data)
    {
    	//debug($path);
    	
        if(isset($this->controller->Session))
        {
            $stored_alaxos_filter = [];
            
            if($this->controller->Session->check('Alaxos.Filter'))
            {
                $stored_alaxos_filter = $this->controller->Session->read('Alaxos.Filter');
            }
            
            if(isset($data))
            {
                $stored_alaxos_filter[$path] = $data;
            }
            else
            {
                unset($stored_alaxos_filter[$path]);
            }
            
            $this->controller->Session->write('Alaxos.Filter', $stored_alaxos_filter);
        }
        
        return false;
    }
    
    protected function getStoredQuery($path)
    {
//     	debug($path);
    	
        if(isset($this->controller->Session))
        {
            if($this->controller->Session->check('Alaxos.Filter'))
            {
                $stored_alaxos_filter = $this->controller->Session->read('Alaxos.Filter');
                
                if(isset($stored_alaxos_filter[$path]))
                {
                    return $stored_alaxos_filter[$path];
                }
            }
        }
        
        return null;
    }
    
    protected function clearStoredQuery($path)
    {
        $this->storeQuery($path, null);
    }
    
    protected function getComparisonPath($url = array())
    {
        if(is_array($url))
        {
            unset($url['?']);
            unset($url['pass']);
            
            $path = Router::url($url);
            
            return $path;
        }
        else
        {
            return null;
        }
    }
    
    /********************************************************************************/
    
    protected function _addNumericCondition(Query $query, $fieldName, $value, array $options = array())
    {
        $number1 = null;
        $number2 = null;
        
        if(is_array($value))
        {
            if(isset($value['__start__']) && !empty($value['__start__']) && is_numeric($value['__start__']))
            {
                $number1 = $value['__start__'];
            }
            
            if(isset($value['__end__']) && !empty($value['__end__']) && is_numeric($value['__end__']))
            {
                $number2 = $value['__end__'];
            }
        }
        elseif(is_string($value) && !empty($value) && is_numeric($value))
        {
            $number1 = $value;
        }
        
        /****/
        
        if(isset($number1) && isset($number2))
        {
            /*
             * search BETWEEN both numbers
             */
            
            $query->where(function($exp) use ($fieldName, $number1, $number2){
                return $exp->gte($fieldName, $number1)
                           ->lte($fieldName, $number2);
            });
        }
        elseif(isset($number1))
        {
            /*
             * search equal first number
             */
            
            $query->where([$fieldName => $number1]);
        }
        elseif(isset($number2))
        {
            /*
             * search less or equal second number
             */
            
            $query->where(function($exp) use ($fieldName, $number2){
                return $exp->lte($fieldName, $number2);
            });
        }
    }
    
    protected function _addDatetimeCondition(Query $query, $fieldName, $value, array $options = array())
    {
        $display_timezone = isset($this->_config['display_timezone']) ? $this->_config['display_timezone'] : Configure::read('display_timezone');
        $default_timezone = date_default_timezone_get();
        
        $display_timezone = !empty($display_timezone) ? $display_timezone : $default_timezone;
        
        $date1 = null;
        $date2 = null;
        
        if(is_array($value))
        {
            /*
             * FROM - TO filter
             */
            
            if(isset($value['__start__']) && !empty($value['__start__']))
            {
                $date1 = Time::parse($value['__start__'], $display_timezone);
                $date1->setTimezone($default_timezone);
            }
            
            if(isset($value['__end__']) && !empty($value['__end__']))
            {
                $date2 = Time::parse($value['__end__'], $display_timezone);
                $date2->setTimezone($default_timezone);
            }
        }
        elseif(is_string($value) && !empty($value))
        {
            /*
             * ONE field filter
             */
            
            $date1 = Time::parse($value, $display_timezone);
            $date1->setTimezone($default_timezone);
        }
        
        /****/
        
        if(isset($date1) && isset($date2))
        {
           /*
            * search BETWEEN both dates
            */
            
            $query->where(function($exp) use ($fieldName, $date1, $date2){
                return $exp->gte($fieldName, $date1->toDateTimeString())
                           ->lte($fieldName, $date2->toDateTimeString());
            });
        }
        elseif(isset($date1))
        {
           /*
            * search AT first date
            */
            
            $query->where([$fieldName => $date1->toDateTimeString()]);
        }
        elseif(isset($date2))
        {
           /*
            * search UNTIL second date
            */
            
            $query->where(function($exp) use ($fieldName, $date2){
                return $exp->lte($fieldName, $date2->toDateTimeString());
            });
        }
    }
    
    protected function _addStringCondition(Query $query, $fieldName, $value, array $options = array())
    {
        if(is_string($value))
        {
            if($options['auto_wildcard_string'])
            {
                $value = '%' . $value . '%';
            }
            
            $query->where([$fieldName . ' LIKE' => $value]);
        }
    }
    
    protected function _addBooleanCondition(Query $query, $fieldName, $value, array $options = array())
    {
        if(in_array($value, [0, 1, true, false, 'true', 'false']))
        {
            $query->where([$fieldName => $value]);
        }
    }
}