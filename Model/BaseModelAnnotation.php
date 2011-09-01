<?php

/**
 *
 * @author    Thomas Lundquist <thomasez@redpill-linpro.com>
 * @copyright 2011 Thomas Lundquist
 * @license   http://www.gnu.org/copyleft/lesser.html  LGPL License 2.1
 *
 */

namespace RedpillLinpro\NosqlBundle\Model;

abstract class BaseModelAnnotation implements StorableObjectInterface
{

    /**
     * @var \Doctrine\Common\Annotations\AnnotationReader
     */
    protected static $_reader = null;

    /**
     * @var \RedpillLinpro\NosqlBundle\Manager\BaseManager
     */
    protected static $_entitymanager = null;
    
    /**
     * @var \ReflectionClass
     */
    protected static $_reflectedclass = null;
    
    protected static $_id_property = null;
    protected static $_id_column = null;
    
    protected $_resource_location = null;

    public function fromDataArray($data, \RedpillLinpro\NosqlBundle\Manager\BaseManager $manager)
    {
        $this->_dataArrayMap($data);
        static::$_entitymanager = $manager;
    }

    public function toDataArray()
    {
        return $this->_extractToDataArray();
    }

    protected function _populateAnnotatedIdValues()
    {
        if (static::$_id_column === null || static::$_id_property === null) {
            foreach (static::getReflectedClass()->getProperties() as $property) {
                if ($id_annotation = static::getIdAnnotation($property)) {
                    if (!$column_annotation = static::getColumnAnnotation($property))
                        throw new Exception('You must set the Id annotation on a property annotated with @Column');

                    $tmp = null;
                    static::$_id_column =& $tmp;
                    static::$_id_property =& $tmp;
                    unset($tmp);

                    static::$_id_column = ($column_annotation->name) ? $column_annotation->name : $property->name;
                    static::$_id_property = $property->name;
                    break;
                }
            }
        }
    }
    
    public function getDataArrayIdentifierValue()
    {
        $this->_populateAnnotatedIdValues();
        return $this->{static::$_id_property};
    }
    
    public function setDataArrayIdentifierValue($identifier_value)
    {
        $this->_populateAnnotatedIdValues();
        $this->{static::$_id_property} = $identifier_value;
    }

    public function getDataArrayIdentifierColumn()
    {
        $this->_populateAnnotatedIdValues();
        return static::$_id_column;
    }
    
    /**
     * Get an Annotationn reader object
     *
     * @return \Doctrine\Common\Annotations\AnnotationReader
     */
    protected static function getAnnotationsReader()
    {
        if (self::$_reader === null) {
            self::$_reader = new \Doctrine\Common\Annotations\AnnotationReader(new \Doctrine\Common\Cache\ArrayCache());
            self::$_reader->setEnableParsePhpImports(true);
            self::$_reader->setDefaultAnnotationNamespace('RedpillLinpro\\NosqlBundle\\Annotations\\');
        }
        return self::$_reader;
    }

    /**
     * Get a reflection class object valid for this static class, so we don't
     * have to instantiate a new one for each instance with the overhead that
     * comes with it
     * 
     * @return \ReflectionClass
     */
    protected static function getReflectedClass()
    {
        if (static::$_reflectedclass === null) {
            $called_class = get_called_class();
            $reflection_obj = new \ReflectionClass($called_class);
            $called_class::$_reflectedclass =& $reflection_obj;
        }
        return static::$_reflectedclass;
    }
    
    /**
     * @return RedpillLinpro\NosqlBundle\Annotations\Id
     */
    public static function getIdAnnotation($property)
    {
        return self::getAnnotationsReader()->getPropertyAnnotation($property, 'RedpillLinpro\\NosqlBundle\\Annotations\\Id');
    }
    
    /**
     * @return RedpillLinpro\NosqlBundle\Annotations\Column
     */
    public static function getColumnAnnotation($property)
    {
        return self::getAnnotationsReader()->getPropertyAnnotation($property, 'RedpillLinpro\\NosqlBundle\\Annotations\\Column');
    }

    /**
     * @return RedpillLinpro\NosqlBundle\Annotations\Relates
     */
    public static function getRelatesAnnotation($property)
    {
        return self::getAnnotationsReader()->getPropertyAnnotation($property, 'RedpillLinpro\\NosqlBundle\\Annotations\\Relates');
    }
    
    protected function _getResourceLocation()
    {
        if ($this->_resource_location === null) {
            $this->_resource_location = str_replace(':id', $this->{static::$_id_property}, static::$_entitymanager->getEntityResource());
        }
        return $this->_resource_location;
    }
    
    protected function _applyDataArrayProperty($property, $result, $extracted = null)
    {
        $id_annotation = (static::$_id_property !== null || $extracted !== null) ? null : static::getIdAnnotation($property);
        $column_annotation = ($extracted !== null) ? null : static::getColumnAnnotation($property);
        $relates_annotation = ($extracted !== null) ? null : static::getRelatesAnnotation($property);

        if ($id_annotation) {
            if (!$column_annotation)
                throw new Exception('You must set the Id annotation on a property annotated with @Column');
            static::$_id_column = ($column_annotation->name) ? $column_annotation->name : $property->name;
            static::$_id_property = $property->name;
        }

        if ($column_annotation !== null || $extracted !== null) {
            if ($extracted !== null) {
                if (array_key_exists($extracted, $result)) {
                    $this->$property = $result[$extracted];
                }
            } else {
                $name = ($column_annotation->name) ? $column_annotation->name : $property->name;
                if ($extract_annotation = self::getAnnotationsReader()->getPropertyAnnotation($property, 'RedpillLinpro\\NosqlBundle\\Annotations\\Extract')) {

                    if (!$extract_annotation->hasColumns())
                        throw new \Exception('No columns defined for the extract annotation');

                    foreach ($extract_annotation->columns as $column => $extract_to_property) {
                        $this->_applyDataArrayProperty($extract_to_property, $result[$name], $column);
                    }
                } elseif (array_key_exists($name, $result)) {
                    if ($relates_annotation !== null && is_array($result[$name])) {
                        $this->_mapRelationData($property, $result[$name], $relates_annotation);
                    } else {
                        $this->{$property->name} = $result[$name];
                    }
                }
            }
        }
    }
    
    protected function _populateRelatedObject($property)
    {
        if (is_array($this->$property) || is_object($this->$property)) return;
        
        $reflected_property = static::getReflectedClass()->getProperty($property);
        $relates_annotation = static::getRelatesAnnotation($reflected_property);
        if (is_numeric($this->$property)) {
            $related_resource_location = str_replace(':id', $this->$property, $relates_annotation->resource);
        } else {
            $related_resource_location = $relates_annotation->resource;
        }
        $data = static::$_entitymanager->getAccessService()->call($this->_getResourceLocation() . '/' . $related_resource_location, 'GET', array());
        
        $this->_mapRelationData($property, $data, $relates_annotation);
    }
    
    protected function _mapRelationData($property, $data, \RedpillLinpro\NosqlBundle\Annotations\Relates $relates_annotation)
    {
        $classname = $relates_annotation->model;
        if (!$classname) {
            $value = $data;
        } elseif ($relates_annotation->collection) {
            $value = array();
            foreach ($data as $single_result) {
                $object = new $classname();
                $object->fromDataArray($single_result, static::$_entitymanager);
                $value[] = $object;
            }
        } else {
            $value = new $classname();
            $value->fromDataArray($data, static::$_entitymanager);
        }
        
        $this->$property = $value;
    }

    protected function _extractDataArrayProperty($property, &$result, $extracted = null)
    {
        $id_annotation = (static::$_id_property !== null || $extracted !== null) ? null : self::getAnnotationsReader()->getPropertyAnnotation($property, 'RedpillLinpro\\NosqlBundle\\Annotations\\Id');
        $column_annotation = ($extracted !== null) ? null : self::getAnnotationsReader()->getPropertyAnnotation($property, 'RedpillLinpro\\NosqlBundle\\Annotations\\Column');

        if ($id_annotation) {
            if (!$column_annotation)
                throw new Exception('You must set the Id annotation on a property annotated with @Column');
            
            $tmp = null;
            static::$_id_column =& $tmp;
            static::$_id_property =& $tmp;
            unset($tmp);
            
            static::$_id_column = ($column_annotation->name) ? $column_annotation->name : $property->name;
            static::$_id_property = $property->name;
        }

        if ($column_annotation !== null || $extracted !== null) {
            if ($extracted !== null) {
                $result[$extracted] = $this->$property;
            } else {
                $name = ($column_annotation->name) ? $column_annotation->name : $property->name;
                if ($extract_annotation = self::getAnnotationsReader()->getPropertyAnnotation($property, 'RedpillLinpro\\NosqlBundle\\Annotations\\Extract')) {
                    $return_value = array();
                    foreach ($extract_annotation->columns as $column => $extract_from_property) {
                        $this->_extractDataArrayProperty($extract_from_property, $return_value, $column);
                    }
                    $result[$name] = $return_value;
                } else {
                    $result[$name] = $this->{$property->name};
                }
            }
        }
    }

    /**
     * Convert the result arrays from VGS client to collection of Entities
     *
     * @param array $resultset array of responses from VGS_Client
     * @return array
     */
    protected function _dataArrayMap($result)
    {
        foreach (static::getReflectedClass()->getProperties() as $property) {
            $this->_applyDataArrayProperty($property, $result);
        }
    }

    protected function _extractToDataArray()
    {
        $result = array();

        foreach (static::getReflectedClass()->getProperties() as $property) {
            $this->_extractDataArrayProperty($property, $result);
        }

        return $result;
    }

    static function getFormSetup()
    {
        return static::$model_setup;
    }

    static function getClassName()
    {
        return 'user';
    }

}

