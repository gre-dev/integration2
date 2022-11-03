<?php

require_once 'API.php';
require_once './Exceptions/DBException.php';
require_once './Exceptions/SessionException.php';
require_once './Traits/InputHandleTrait.php';

require_once 'vendor/autoload.php';

class Account {

    use InputHandleTrait;

    /**
     * @var string $dbhost mysql db host name or ip to use when quering data.
     *
     * @var string $dbname mysql db name to get data from.
     *
     * @var string $dbuser mysql db user to use.
     *
     * @var string $dbpass mysql db password to use
     *
     **/

    private $dbhost;
    private $dbname;
    private $dbuser;
    private $dbpass;
    
    /**
     * @var string $api_keys_table the api keys table name.
     *
     * @var string $api_keys_table the accounts table name.
     **/

    private $api_keys_table;
    private $accounts_table;

    
    /**
     * @var string $PLAN_FREE_ID free plan id (default plan to attach to the fresh account created).
     **/
    private $PLAN_FREE_ID;

    
    /**
     * @var PDO $db PDO database object, don't use it directly inside
     * class method (instead use db_connect and db_close_connection),
     * exists for saving its reference only out after a function ends
     * executions.      
     **/
    
    private $db;
    /**
     * @todo add phpdotenv exception documentation.
     **/

    public function __construct() {
        
        $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
        $dotenv->load();
        $dotenv->required(['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASSWORD']);
        $dotenv->required(['DB_ACCOUNTS_TABLE', 'DB_API_KEYS_TABLE']);

        $this->dbhost = $_ENV['DB_HOST'];
        $this->dbname = $_ENV['DB_NAME'];
        $this->dbuser = $_ENV['DB_USER'];
        $this->dbpass = $_ENV['DB_PASSWORD'];
        $this->api_keys_table = $_ENV['DB_API_KEYS_TABLE'];
        $this->accounts_table = $_ENV['DB_ACCOUNTS_TABLE'];
        $this->plans_table = $_ENV['DB_ACCOUNTS_TABLE'];

        $dotenv->ifPresent('PLAN_FREE_ID')->isInteger();
        $this->PLAN_FREE_ID = $_ENV['PLAN_FREE_ID'] ?? 1;

    }

    /**
     * established db connection. ///
     *
     * @return PDO represents the db connection object
     *
     * @throws DBException if an error encourtered while connecting to mysql db server
     **/

    private function db_connect() {
        try {            
            $this->db = new PDO("mysql:host={$this->dbhost};dbname={$this->dbname}", $this->dbuser, $this->dbpass);
            $this->db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
            $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            return $this->db;

        } catch (PDOException $e) {
            
            $exception = new DBException(DBException::DB_ERR_CONN_WRONG_DATA,$e);
            $exception->set_connection_wrong_data("Error while connecting to db, please check if server login credentials is correct: Username {$this->dbuser}, Password {$this->dbpass}");
            throw $exception;
        }
    }

    /**
     * closes db connection.
     **/
    
    private function db_close_connection() {
        $this->db = null;
    }

    /**
     * checks if current session have valid (already exist) login credentials.
     *
     * @return bool indicated session is valid or not.
     * @throws SessionException if the one or more session parameter is empty,
     *                          note that session parameters are subject to input filer 
     *                          to prevent input related security attacks.
     *
     *                          
     * @uses $_SESSION['login_email'] and $_SESSION['login_password'] to read login data.
     **/

    public function check_session() { //use session_start() outside this function

        if (isset($_SESSION['login_email']) && isset($_SESSION['login_password'])) {
            
            $email = $_SESSION['login_email'];
            $password = $_SESSION['login_password'];

            $email = $this->revert_filtered($email);
            $password = $this->revert_filtered($password);

            if (empty($email) || empty($password)) {
                throw new SessionException(SessionException::ERR_DATA_NOT_FOUND);
            }
            
            try {
                $db = $this->db_connect();

                // note that login_password in session is a hash , notice
                $stmt = $db->prepare("SELECT COUNT(id) FROM {$this->accounts_table} WHERE email = :email AND password = :pass LIMIT 1");

                $stmt->bindValue(':email',$email);
                $stmt->bindValue(':pass',$password);

                $stmt->execute();
                $rows = $stmt->fetchColumn();
                
                if ($rows === 1) {
                    $this->db_close_connection();
                    return true;
                }
            }
            catch (PDOException $e) {
                $exception = new DBException(DBException::DB_ERR_SELECT,$e);
                $exception->set_select_data( "Error while checking user session data in db");
                throw  $exception;
            }
        }
        $this->db_close_connection();
        return false;
        
    } 

    /** updates session data with new email and password.
     *
     *@param string $email represents the new email.
     *@param string $pass represnts the new password, note the password will be stored hashed in the session variable.
     *@param bool $is_hashed optional argument indicated whether
     *                       the passed password doesn't need to be 
     *                       hashed internally or not.
     * @throws InvalidArgumentException if the provided email is not a valid email.
     **/

    public function update_session_data($email, $pass, $is_hashed = true) //use session_start() outside this function
    {

        
        // maybe checking account password is strong, notice
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException("Email passed doesn't look like an email string",1);
        }
        if ($is_hashed === false) {
            $hashoptions = [
                'cost' => 12,
            ];
            $pass = password_hash($pass, PASSWORD_BCRYPT, $hashoptions);
        }
        
        session_start();
        $_SESSION['login_email'] = $this->filter_input($email);
        $_SESSION['login_password'] = $this->filter_input($pass);
    }

    
    /**
     * gets account's data from db.
     *
     * @return array indicates the logged in user info,
     *                 or empty array if doesn't exist in database.
     * @param string $email login email.
     * @param string $pass login password.
     *@param bool $is_hashed optional argument indicated whether
     *                       the passed password doesn't need to be 
     *                       hashed internally or not, note that the 
     *                       password will be stored hash in session
     *                       and db.
     * @throws InvalidArgumentException if the email isnot a valid email
     *                                  or one or more the arguments are
     *                                  empty.
     * @throws DBException if getting the login account info from db failed
     *                     or the db connection encountered a problem.
     **/

    public function validate_login_credentials($email,$pass,$is_hashed = true)
    {
        
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException("Email passed doesn't look like an email string",1);
        }
        
        if (empty($email) || empty($pass)) {
            throw new InvalidArgumentException("Some or all args are empty strings");
        }

        if ($is_hashed === false) {
            $hashoptions = [
                'cost' => 12,
            ];
            $pass = password_hash($pass, PASSWORD_BCRYPT, $hashoptions);
        }

        try {
  
            $db = $this->db_connect();

            $stmt = $db->prepare("SELECT * FROM {$this->accounts_table} WHERE email = :email AND password = :pass LIMIT 1");
                
            $stmt->bindValue(':email', $email);
            $stmt->bindValue(':pass' , $pass);
                
            $success = $stmt->execute();

            if (!$success) {
                $exception = new DBException(DBException::DB_ERR_SELECT);
                $exception->set_select_data("Error while performing login credentials checking queries: Email $email, Password $pass");
                throw $exception;
            }

            $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
            if ($user) {
                $this->db_close_connection();
                return $user ;
            }
            
        }
        catch (PDOException $e) {
            $exception = new DBException(DBException::DB_ERR_SELECT);
            $exception->set_select_data("while performing login credentials checking queries: Email $email, Password $pass");
            throw $exception;
        }

        // for properly close if anything behave unexpectably
        $this->db_close_connection();
        return []; 
    }


    /**
     * creates a new account with a new api key associated to it.
     *
     * @return int new the account id.
     * @param string $email the new account email.
     * @param string $password the new account password.
     * @param string $username the new account user name.
     * @param bool $is_hashed optional argument indicated whether
     *                       the passed password doesn't need to be 
     *                       hashed internally or not, note that the 
     *                       password will be stored hash in session
     *                       and db. 
     * @throws InvalidArgumentException if one or more arguments are empty
     *                                  or $email is not a valid email.
     **/
    
    public function create_new_account($email,$password,$username, $is_hashed = true) { // check if them are required to function or just username
        
        try {
            if (empty($password) || empty($username)) {
                throw new InvalidArgumentException("Some args are empty strings");
            }

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException("Email arg doesn't look like an email string",1);
            }
            
            $db = $this->db_connect();

            $stmt = $db->prepare("INSERT INTO {$this->accounts_table} (username,email, password, time) VALUES (:username, :email, :pass, :time)");

            if ($is_hashed === false) {
                $hashoptions = [
                    'cost' => 12,
                ];
                
                $password = password_hash($password, PASSWORD_BCRYPT, $hashoptions);
            }
            
            $data = [
                'username' => $username,
                'email'    => $email,
                'pass'     => $password,
                'time'    => time()
                
            ];
            
            $result = $stmt->execute($data);

            if ($result === false || $stmt->rowCount() !== 1) {
                $exception = new DBException(DBException::DB_ERR_INSERT);
                $exception->set_insert_data("Error while adding a new account");
                throw $exception;
            }
                
            $accountId = $db->lastInsertId();
            
            $api = new API();
            $keyid = $api->create_new_key($accountId, $this->PLAN_FREE_ID); // maybe change it after we decides about plan tables

            $this->db_close_connection();
            return $accountId;
        }
        catch (PDOException $e) {

            $exception = new DBException(DBException::DB_ERR_INSERT);
            $exception->set_insert_data("Error while adding a new account to db");
            throw $exception;
        }
        $this->db_close_connection();
        $this->update_session_data($email, $pass); 
    }

    
    /**
     * checks it the given email availiable, i.e. it can be used to register a new 
     * account.
     *
     * @return bool indicates the email is avaliable for registeration.
     * @param string $email email to check for.
     * @throws InvalidArgumentException if $email isnot a valid email.
     **/

    public function is_email_avaliable($email)
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException("Email passed $email doesn't looks like sanitized email",1);
        }
        
        try {
            $db = $this->db_connect();
            
            $stmt = $db->prepare("SELECT COUNT(id) FROM {$this->accounts_table} WHERE email = :email");
            $result = $stmt->execute(array($email));
            
            if (!$result || $stmt->rowCount() !== 1)
            {
                $exception = new DBException(DBException::DB_ERR_SELECT);
                $exception->set_select_data("Error while checking if email already exists");
                throw $exception;
            }

            $rows = $stmt->fetchColumn();
            $this->db_close_connection();
            return ($rows === 0);
        }
        catch (PDOException $e) {
            $exception = new DBException(DBException::DB_ERR_SELECT,$e);
            $exception->set_select_data("Error while checking if email already exists in db");
            throw $exception;
        }
        
        $this->db_close_connection();
        return false;
    }

    /** 
     * encodes the passed password, to avoid URL clashes.
     *
     * @return string the base62 encoded password.
     * @param string $data the password to hash.
     **/


    private function base62_encode ($data) {
        /* 
         * Used to hash reset password token correctly,
         * avoiding possibles clashes in url generation
         *
         */
        $outstring = '';
        $len = strlen($data);
        for ($i = 0; $i < $len; $i += 8) {
            $chunk = substr($data, $i, 8);
            $outlen = ceil((strlen($chunk) * 8) / 6);
            $x = bin2hex($chunk);
            $number = ltrim($x, '0');
            if ($number === '') $number = '0';
            $w = gmp_strval(gmp_init($number, 16), 62);
            $pad = str_pad($w, $outlen, '0', STR_PAD_LEFT);
            $outstring .= $pad;
        }
        return $outstring;
    }

    /**
     * generates a new password reset token, and store it in db.
     *
     * @return string the generated reset token string.
     * @param string $id the account id to generate password token for.
     * @throws DBException if the db connection encountered a problem or
     *                     the generate password token db process has
     *                     failed.
     **/

    public function generate_password_token($id) {
        // generate it and store in db

        $random = openssl_random_pseudo_bytes(12); // this will make 33-char length string with hex
        $random =  bin2hex($random);
        
        // we will use base62 encode in order to avoid URL clashes issues
        $encoded_token = $this->base62_encode($random);
        
        $encoded_token = strtolower($encoded_token); // beutify only

        try {
            
            $db = $this->db_connect();
    
            $stmt = $db->prepare("UPDATE {$this->accounts_table} SET reset_pass_token = :token where id = :accountid");

            $data = array (
                'token' => $encoded_token,
                'accountid' => $id
            );
            
            $result = $stmt->execute($data);
    
            if (!$result || $stmt->rowCount() !== 1) // affected rows ///
            {
                $exception = new DBException (DBException::DB_ERR_INSERT);
                $exception->set_insert_data("Error while storing password token for account $id");
                throw $exception;
            }
        }
        catch (PDOException $e) {   
            $exception = new DBException (DBException::DB_ERR_INSERT,$e);
            $exception->set_insert_data("Error while storing password token for account $id in db");
            throw $exception;
        }
        
        $this->db_close_connection();
        return $encoded_token;
    }
    
    /**
     * updates password, both in db and session.
     *
     * @param string $email account email to update password for.
     * @param string $password the new passsword.
     * @param bool $is_hashed optional argument indicated whether
     *                       the passed password doesn't need to be 
     *                       hashed internally or not, note that the 
     *                       password will be stored hash in session
     *                       and db. 
     * @throws InvalidArgumentException if $email or $password is empty.
     * @throws DBException if db connection encounters a problem or
     *                     or updating password has failed in db.
     **/

    public function update_password($email,$password, $is_hashed = true) {

        if (empty($password) || empty($email)) {
            throw new InvalidArgumentException ('Either email or password args passed is empty');
        }
        
        try {
            
            $db = $this->db_connect();
    
            $stmt = $db->prepare("SELECT id FROM {$this->accounts_table} WHERE email = ? LIMIT 1");
            $stmt->execute(array($email));

            $accountId = $stmt->fetchColumn();
        
            if (!$accountId)
            {
                $exception = new DBException(DBException::DB_ERR_UPDATE);
                $exception->set_update_data("Error while updating password for an account");
                throw $exception;
            }

        }
        catch (PDOException $e) {
            $exception = new DBException(DBException::DB_ERR_UPDATE,$e);
            $exception->set_update_data("Error while updating password for an account");
            throw $exception;
        }

        if ($is_hashed === false) {
            $hashoptions = [
                'cost' => 12,
            ];
            $passhash =   password_hash($password, PASSWORD_BCRYPT, $hashoptions);
        }
        else {
            $passhash = $password;
        }
        
        try {

            $passStmt = $db->prepare("UPDATE {$this->accounts_table} SET password = :pass where id = :accountid");


            $data = array(
                'pass' => $passhash,
                'accountid' => $accountId
            );
                  
            $result = $passStmt->execute($data);

            if (!$result)
            {
                $exception = new DBException(DBException::DB_ERR_UPDATE);
                $exception->set_update_data("password for account $accountId");
                throw $exception;
            }
            $this->update_session_data($email, $passhash);

        }        catch (PDOException $e) {
            $exception = new DBException(DBException::DB_ERR_UPDATE, $e);
            $exception->set_update_data("Error while updating password for account $accountId in db");
            throw $exception;
        }
            
        $this->db_close_connection();
    }
    /**
     * logout , erases login data from session
     **/
    
    public function logout() { //session_start() outside this function
        session_start();
        unset($_SESSION['login_email']);
        unset($_SESSION['login_password']);
    }

}

?>
    
