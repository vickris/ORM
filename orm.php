<?php

class Database
{
    private $host = 'localhost';
    private $user = 'root';
    private $pass = '';
    private $dbname = 'mydatabase';
    private $dbtype = 'mysql';

    private $db_handler;
    private $error;
    private $statement;

    public function __construct()
    {
        $dsn = $this->dbtype.':host='.$this->host.';dbname='.$this->dbname;
        $options = array(
            PDO::ATTR_PERSISTENT => true,
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            );

        try {
            $this->db_handler = new PDO($dsn, $this->user, $this->pass, $options);
        } catch (PDOException $e) {
            echo $e->getmessage();
        }
    }

    public function prepare($query)
    {
        $this->statement = $this->db_handler->prepare($query);
    }

    public function bind($param, $value, $type = null)
    {
        if (is_null($type)) {
            switch (true) {
                case is_int($value):
                    $type = PDO::PARAM_INT;
                    break;
                case is_bool($value):
                    $type = PDO::PARAM_BOOL;
                    break;
                case is_null($value):
                    $type = PDO::PARAM_NULL;
                    break;
                default:
                    $type = PDO::PARAM_STR;
            }
        }
        $this->statement->bindValue($param, $value, $type);
    }

    public function execute()
    {
        $this->statement->execute();
    }

    public function select($table, $where = '', $fields = '*', $order = '', $limit = null, $offset = '')
    {
        $query = "SELECT $fields FROM $table "
                 .($where ? " WHERE $where " : '')
                 .($limit ? " LIMIT $limit " : '')
                 .(($offset && $limit ? " OFFSET $offset " : ''))
                 .($order ? " ORDER BY $order " : '');
        $this->prepare($query);
    }

    public function insert($table, $data)
    {
        ksort($data);

        $fieldNames = implode(',', array_keys($data));
        $fieldValues = ':'.implode(', :', array_keys($data));

        $query = "INSERT INTO $table ($fieldNames) VALUES($fieldValues)";
        $this->prepare($query);

        foreach ($data as $key => $value) {
            $this->bind(":$key", $value);
        }

        $this->execute();
    }

    /**
     * Update data.
     */
    public function update($table, array $data, $where = '')
    {
        ksort($data);
        $fieldDetails = null;
        foreach ($data as $key => $value) {
            $fieldDetails .= "$key = :$key,";
        }
        $fieldDetails = rtrim($fieldDetails, ',');
        $query = "UPDATE $table SET $fieldDetails ".($where ? 'WHERE '.$where : '');
        $this->prepare($query);

        foreach ($data as $key => $value) {
            $this->bind(":$key", $value);
        }
        $this->execute();
    }

    /**
     * Delete Functionality.
     */
    public function delete($table, $where, $limit = 1)
    {
        $this->prepare("DELETE FROM $table WHERE $where LIMIT $limit");
        $this->execute();
    }

    /**
     * Return data as an assoc array.
     */
    public function resultset()
    {
        $this->execute();

        return $this->statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Return single as an assoc array.
     */
    public function single()
    {
        $this->execute();

        return $this->statement->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Return Objectset.
     */
    public function objectSet($entityClass)
    {
        $this->execute();
        $this->statement->setFetchMode(PDO::FETCH_CLASS, $entityClass);

        return $this->statement->fetchAll();
    }

    /**
     * Return single object.
     */
    public function singleObject($entityClass)
    {
        $this->execute();
        $this->statement->setFetchMode(PDO::FETCH_CLASS, $entityClass);

        return $this->statement->fetch();
    }

    /**
     * Return row count.
     */
    public function rowCount()
    {
        return $this->statement->rowCount();
    }

    public function lastInsertId()
    {
        return $this->db_handler->lastInsertId();
    }

    public function beginTransaction($value = '')
    {
        return $this->db_handler->beginTransaction();
    }

    public function endTransaction()
    {
        return $this->db_handler->commit();
    }

    public function cancelTranscation()
    {
        return $this->db_handler->rollBack();
    }

    public function debugDumpParams()
    {
        return $this->statement->debugDumpParams();
    }
}

/**
 * Data Mapping layer.
 */
class DBContext
{
    private $db;
    private $entities = array();

    public function __construct()
    {
        $this->db = new Database();
    }

    public function find($entity, $conditions = array(), $fields = '*', $order = '', $limit = null, $offset = '')
    {
        $where = '';
        foreach ($conditions as $key => $value) {
            if (is_string($value)) {
                $where .= ' '.$key.' ="'.$value.'"'.' &&';
            } else {
                $where .= ' '.$key.' = '.$value.' &&';
            }
        }
        $where = rtrim($where, '&');
        $this->db->select($entity->entity_table, $where, $fields, $order, $limit, $offset);

        return $this->db->singleObject($entity->entity_class);
    }

    public function findAll($entity, $conditions = array(), $fields = '*', $order = '', $limit = null, $offset = '')
    {
        $where = '';
        foreach ($conditions as $key => $value) {
            if (is_string($value)) {
                $where .= ' '.$key.' ="'.$value.'"'.' &&';
            } else {
                $where .= ' '.$key.' = '.$value.' &&';
            }
        }
        $where = rtrim($where, '&');
        $this->db->select($entity->entity_table, $where, $fields, $order, $limit, $offset);

        return $this->db->objectSet($entity->entity_class);
    }

    public function saveChanges()
    {
        foreach ($this->entities as $entity) {
            switch ($entity->entity_state) {
                case EntityState::Created:
                    foreach ($entity->db_fields as $key) {
                        $data[$key] = $entity->{$key};
                        //var_dump($entity->);
                    }
                    $this->db->insert($entity->entity_table, $data);
                    break;
                case EntityState::Modified:
                    foreach ($entity->db_fields as $key) {
                        if (!is_null($entity->$key)) {
                            $data[$key] = $entity->$key;
                        }
                    }
                    $where = ' ';
                    foreach ($entity->primary_keys as $key) {
                        $where .= ' '.$key.' = '.$entity->$key.' &&';
                    }
                    $where = rtrim($where, '&');
                    $this->db->update($entity->entity_table, $data, $where);
                    break;

                case EntityState::Deleted:
                    $where = ' ';
                    foreach ($entity->primary_keys as $key) {
                        $where .= ' '.$key.' = '.$entity->$key.' &&';
                    }
                    $where = rtrim($where, '&');
                    $this->db->delete($entity->entity_table, $where);
                    break;

                default:
                    break;
            }
        }
        unset($this->entities);
    }

    public function add($entity)
    {
        $entity->entity_state = EntityState::Created;
        array_push($this->entities, $entity);
    }

    public function update($entity)
    {
        $entity->entity_state = EntityState::Modified;
        array_push($this->entities, $entity);
    }

    public function remove($entity)
    {
        $entity->entity_state = EntityState::Deleted;
        array_push($this->entities, $entity);
    }
}

final class EntityState
{
    const Created = 1;
    const Modified = 2;
    const Deleted = 3;
}

class Entity
{
    private $db;

    public function __construct()
    {
        $this->db = new Database();
    }

    public function add()
    {
        foreach ($this->db_fields as $key) {
            $data[$key] = $this->$key;
        }
        $this->db->insert($this->entity_table, $data);
    }

    public function update()
    {
        foreach ($this->db_fields as $key) {
            if (!is_null($this->key)) {
                $data[$key] = $this->$key;
            }
        }
        $where = ' ';
        foreach ($primary_keys as $key) {
            $where .= ' '.$key.' = '.$this->$key.' &&';
        }

        $where = rtrim($where, '&');
        $this->db->update($this->entity_table, $data, $where);
    }

    public function remove()
    {
        $where = ' ';
        foreach ($this->primary_keys as $key) {
            $where .= ' '.$key.' = '.$this->$key.' &&';
        }
        $where = rtrim($where, '&');
        $this->db->delete($this->entity_table, $where);
    }
}

/**
 *
 */
class Person extends Entity
{
    public $ID;
    public $FName;
    public $LName;
    public $Age;
    public $Gender;

    public $entity_table = 'Person';
    public $entity_class = 'Person';
    public $db_fields = array('ID', 'FName', 'LName', 'Age', 'Gender');
    public $primary_keys = array('ID');

    public function info()
    {
        return '#'.$this->ID.':'.$this->FName.' '.$this->LName.' '.$this->Age.' '.$this->Gender;
    }
}

    /*$db = new DBContext();

    $entity1 = new Person();

    $entity1->FName = "Fetty";
    $entity1->LName = "Wap";
    $entity1->Gender = "Male";
    $entity1->Age = 21;
    $db->add($entity1);
    */

    // $entity2 = new Person();

    // $entity2->ID = 1;
    // $entity2->FName = "Stan";
    // $entity2->LName = "MD";
    // $db->update($entity2);

    // $entity3 = new Person();
    // $entity3->ID = 3;
    // $db->remove($entity3);

    // $db->saveChanges();
    // echo "Saved changes successfully";

    // $person = $db->find(array('ID' => 15));
    // echo $person->FName;
    //var_dump($db->findAll(new Person(), array('ID' => 36, 'Age' => 29)));

/*$db = new DBContext();

$entity1 = new Person();

$entity1->FName = "KAnye";
$entity1->LName = "West";
$entity1->Gender = "Female";
$entity1->Age = 21;
$db->add($entity1);

echo "Changes were successfully saved";

$entity2 = new Person();

$entity2->ID = 1;
$entity2->FName = "Mathias";
$entity2->LName = "Iscariot";
$db->update($entity2);

$entity3 = new Person();
$entity3->ID = 3;
$db->remove($entity3);

$db->saveChanges();
echo "Saved changes successfully";
*/

/*$person = $db->find(new Person(), array('ID' => 6));
echo $person->FName;
var_dump($db->findAll(new Person()));
*/

$newEntity = new Person();
$newEntity->FName = 'NO';
$newEntity->LName = 'One';
$newEntity->Gender = 'Female';
$newEntity->Age = 24;
$newEntity->add();

echo 'Saved changes successfully';
