<?php

/**
 * MetaBehavior CakePHP Plugin
 * @author Moreau Fabien : fmoreau.go@gmail.com
 */
 
class MetaBehavior extends ModelBehavior {

    public $attribute = false;
    public $schema = false;
    public $callbackValues = array();
    
    private $__currentJoins = array();
    
    /**
     * MetaBehavior::setup()
     * 
     * @param mixed $Model
     * @param mixed $config
     * @return void
     */
     
    public function setup(Model $model, $config = array()) {		
        # Loading Meta model to set find and save on the target table 
        $this->Meta = ClassRegistry::init("MetaBehavior.Meta");
		        
        # Storing current schema to compare with $this->data in beforeSave callback
        $this->schema = array_keys($model->schema());
        
        return true;
    }
    
    /**
     * beforeFind Callback
     *
     * @param Model $model Model find was run on
     * @param array $results Array of model results.
     * @param boolean $primary Did the find originate on $model.
     * @return array Modified results
     */
     
    public function beforeFind(Model $model, $query = array()) {
        
        # If there is no conditions, it's useless to looking for it !
        if(!array_key_exists('conditions',$query)){
            return $query;
        }
        
        # Recursive function to find foreign field and build join table instead
        $query['conditions'] = $this->__replace_conditions($model, $query['conditions']);
        
        if(array_key_exists('joins',$query)){
            $query['joins'] = array_merge($query['joins'], array_values($this->__currentJoins));
        }else{
            $query['joins'] = array_values($this->__currentJoins);
        }

        return $query;
    }
    
    private function __replace_conditions(Model $model, $conditions){
    	
        foreach($conditions as $cKey => $cValue){
            if(is_array($cValue)){
                # If is array, we check recursively
                $conditions[$cKey] = $this->__replace_conditions($model, $cValue);
            }

            $match = array();
            
            # Get the field attach to the condition
            $grep_field = preg_match("/".$model->alias."\.(\w+)/i", $cKey, $match);    
            
            if($grep_field){
                $field = $match[1];
                
                if(!in_array($field, $this->schema)){
                    
                    # If the join is not set yet, we build it
                    if(!array_key_exists($field, $this->__currentJoins)){
                        $this->__currentJoins[$field] = array(
                            'table' => 'metas',
                            'alias' => $field,
                            'type' => 'INNER',
                            'conditions' => array(
                                $field.'.foreignModel = "'.$model->alias.'"',
                                $field.'.foreignKey = ' . $model->alias . '.' . $model->primaryKey,
                                $field.'.meta_key = "'.$field.'"'
                            )
                        );
                    }
                    
                    # Get the compare method
                    $grep_operator = preg_match("/".$field."(.+)$/i", $cKey, $match);
                    
                    if($grep_operator){
                        $operator = $match[1];
                    }else{
                        $operator = "";
                    }
                    
                    # Set new condition operator and delete the old one
                    $conditions[$field . '.meta_value' . $operator] = $cValue;   
                    
                    unset($conditions[$cKey]);
                }    
            }
        }

        return $conditions;
    }

    /**
     * afterFind Callback
     *
     * @param Model $model Model find was run on
     * @param array $results Array of model results.
     * @param boolean $primary Did the find originate on $model.
     * @return array Modified results
     */
     
     public function afterFind(Model $model, $results, $primary = false){
     	
        # Check if we're in a array of results
     	if(!empty($results) && isset($results[0][$model->alias])){
    		$primaryKeys = array();
     		$rangeResults = array();
            
            # Get all primaryKeys to send just one Meta request for one search
     		foreach ($results as $key => $result) {
     			$rangeResults[$result[$model->alias][$model->primaryKey]] = $result;
     			$primaryKeys[] = $result[$model->alias][$model->primaryKey];
     		}
     		
            # Get Metadatas
     		$list = $this->Meta->find('all', array('conditions' => array(
            	'Meta.foreignModel =' => $model->alias,
        		'Meta.foreignKey' => $primaryKeys
        	)));
            
            # Sort results table with metas
        	foreach ($list as $l) {
        		$v = $l['Meta']['meta_value'];
            	$v = (@unserialize($v) !== false) ? unserialize($v) : $v;
            	$rangeResults[$l['Meta']['foreignKey']][$model->alias][$l['Meta']['meta_key']] = $v; 
        	}
			
			if($model->findQueryType == 'first'){
				return array(0 => array_shift($rangeResults));
			}
     		
     		return $rangeResults; 	
     	}
     	
     	return $results;
    }

    public function beforeSave(Model $model, $options = array()) {
        # Storing data to save, compare to model schema, needed by afterSave callback
        foreach ($model->data[$model->alias] as $row => $value) {
            if (!in_array($row, $this->schema)) {
                $this->registerAttribute($model, $row, $value);
            }
        }
        return true;
    }

    public function registerAttribute(Model $model, $row, $value) {
		if($value != "" && is_array($value)){
            $value = serialize($value);
        }

        $this->callbackValues[$row] = $value;

        return true;
    }

    public function setAttribute(Model $model, $foreignKey, $key, $value, $primaryKey = false) {

        $attr = array();

        if ($primaryKey !== false && is_numeric($primaryKey)) {
            $attr['Meta']['id'] = $primaryKey;
        }

        $attr['Meta']['foreignKey'] = $foreignKey;
        $attr['Meta']['foreignModel'] = $model->alias;
        $attr['Meta']['meta_key'] = $key;
        $attr['Meta']['meta_value'] = $value;
        
        return $attr;
    }


    public function afterSave(Model $model, $created, $options = array()) {
        if (!empty($this->callbackValues)) {
        	$many = array();
        	
        	$previous_values = false;
        	
        	if(!$created){
        		$previous_values = $this->Meta->find('list',array(
        			'conditions' => array('Meta.foreignModel =' => $model->alias, 'Meta.foreignKey =' => $model->id),
        			'fields' => array('meta_key','id')
        		));
        	}
        
            foreach ($this->callbackValues as $key => $value) {
            	$primary = false;
            	
            	if(is_array($previous_values) && array_key_exists($key, $previous_values)){
            		$primary = $previous_values[$key];
            	}
            	
                $many[] = $this->setAttribute($model, $model->id, $key, $value, $primary);
            }
            
            # Send transaction to meta table
            $this->Meta->saveMany($many);
        }
        
        $this->CallbackValues = array();
    }

    public function afterDelete(Model $model) {
        $this->Meta->deleteAll(array('Meta.foreignKey =' => $model->id, 'Meta.foreignModel =' => $model->alias));
    }
}