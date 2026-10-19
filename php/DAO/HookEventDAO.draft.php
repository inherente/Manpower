<?php
class HookEventDAO {
    protected $config = parse_ini_file('to-do.ini');
    protected $servername = $config['dbhost'];  
    protected $username = $config['user'];
    protected $password = $config['password']; 
    protected $dbname = $config['dbname'];
    protected $conn = null;
    function __construct() {
     // $this->$conn = new mysqli($this->$servername, $this->$username, $this->$password, $this->$dbname);
    }
 // echo "<h1>Health Check</h1>";
    function queueEvent ($content) {
        if ($this->$conn->connect_error) {
         // echo "<h2>Connected Failed</h2>"; 
            die("Connection failed: " . $this->$conn->connect_error);
        } 
     // echo "<br>";
     // echo "<h2>Connected Successfully</h2>";
        $sql = "SELECT PRODUCT_ID, NAME, DESCRIPTION, SUGGESTED_PRICE FROM CHERRY_PRODUCTS;";
        $result = $this->$conn->query($sql);
     // echo $result->num_rows . " Returned row(s) ";
    }

    function __destruct() {
     // echo "<h3>Ready to close Connection</h3>";
        $this->$conn->close();
     // echo "<h2>Closed Connection</h2>";
    }
}
?>