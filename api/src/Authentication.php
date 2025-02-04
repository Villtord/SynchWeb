<?php

namespace SynchWeb;

use JWT;
use Slim\Slim;

class Authentication
{
        protected $app, $db, $user;

		function __construct(Slim $app, $db) {
		    $this->app = $app;
			$this->db = $db;

			$this->app->post('/authenticate', array(&$this, 'authenticate'))->conditions(array(
				'login' => '[A-z0-9]+',
				'password' => '.*',
			));

            $this->app->get('/authenticate/check', array(&$this, 'check'));
			$this->app->get('/authenticate/key', array(&$this, 'generate_jwt_key'));
			$this->app->get('/authenticate/logout', array(&$this, 'logout'));
		}


		function get_user() {
			return $this->user;
		}


    // For SSO check if we are already logged in elsewhere
    // - if a mechanism exists to do this
    function check()
    {
        global $authentication_type;

        $user = $this->authenticateByType($authentication_type)->check();

        if (!$user) $this->_error(400, 'No previous session');

        $userc = $this->db->pq("SELECT personid FROM person WHERE login=:1", array($user));

        if (sizeof($userc)) $this->_output(200, $this->generate_jwt($user));
        else $this->_error(400, 'No previous session');
    }


		// Check if this request needs authentication
		// We allow some pages unauthorised access based on IP, or calendar hash
		function check_auth_required() {
            global $blsr, $bcr, $img, $auto;

			$parts = explode('/', $this->app->request->getResourceUri());
		    if (sizeof($parts)) array_shift($parts);

		    $need_auth = true;
		    # Work around to allow beamline sample registration without CAS authentication
		    if (sizeof($parts) >= 3) {
		    	if (
		            # Calendar ICS export
		            ($parts[0] == 'cal' && $parts[1] == 'ics' && $parts[2] == 'h') || 

                    # Allow formulatrix machines unauthorised access to inspections, certain IPs only
                    ($parts[0] == 'imaging' && $parts[1] == 'inspection' && in_array($_SERVER["REMOTE_ADDR"], $img)) ||

					# For use on the touchscreen computers in the hutch.
					# Handles api calls: /assign/visits/<vist> e.g./assign/visits/mx1234-1
		            ($parts[0] == 'assign' && $parts[1] == 'visits' && in_array($_SERVER["REMOTE_ADDR"], $blsr)) ||

					# Allow barcode reader ips unauthorised access to add history
		            ($parts[0] == 'shipment' && $parts[1] == 'dewars' && $parts[2] == 'history' && in_array($_SERVER["REMOTE_ADDR"], $bcr)) ||

					# Allow barcode reader ips unauthorised access to add comments
		            ($parts[0] == 'shipment' && $parts[1] == 'dewars' && $parts[2] == 'comments' && in_array($_SERVER["REMOTE_ADDR"], $bcr)) ||

		            # Container notification: allow beamlines running in automated mode to notify users
					($parts[0] == 'shipment' && $parts[1] == 'containers' && $parts[2] == 'notify' && in_array($_SERVER["REMOTE_ADDR"], $auto)) ||

					# Allow barcode reader ips unauthorised access to add container history
		            ($parts[0] == 'shipment' && $parts[1] == 'containers' && $parts[2] == 'history' && in_array($_SERVER["REMOTE_ADDR"], $bcr))
		    	) {
		    		$need_auth = false;
		    	}

		    } else if (sizeof($parts) >= 2) {
		        if (
					# For use on the touchscreen computers in the hutch
					# Handles api calls: /assign/assign, /assign/unassign, /assign/deact, /assign/visits
		            (($parts[0] == 'assign') && in_array($_SERVER["REMOTE_ADDR"], $blsr)) ||
		            (($parts[0] == 'shipment' && $parts[1] == 'containers') && in_array($_SERVER["REMOTE_ADDR"], $blsr)) ||

		            # Allow barcode reader unauthorised access, same as above, certain IPs only
		            ($parts[0] == 'shipment' && $parts[1] == 'dewars' && in_array($_SERVER["REMOTE_ADDR"], $bcr)) ||

                    # Allow formulatrix machines unauthorised access to inspections, certain IPs only
                    ($parts[0] == 'imaging' && $parts[1] == 'inspection' && in_array($_SERVER["REMOTE_ADDR"], $img)) ||

                    # Allow EPICS machines to create and close auto collect visits, certain IPs only.
                    # Permitted IPs listed in global variable $auto in config.php.
                    ($parts[0] == 'proposal' && $parts[1] == 'auto' && in_array($_SERVER["REMOTE_ADDR"], $auto))
		        ) {
		            $need_auth = false;
		        }

		    }

		    if (sizeof($parts) > 0) {
		    	if ($parts[0] == 'authenticate' || $parts[0] == 'options') $need_auth = false;
		    }

		    # One time use tokens
		    $once = $this->app->request->get('token');
		    if ($once) {
		    	$token = $this->db->pq("SELECT o.validity, pe.personid, pe.login, CONCAT(p.proposalcode, p.proposalnumber) as prop 
		    		FROM SW_onceToken o
		    		INNER JOIN proposal p ON p.proposalid = o.proposalid
		    		INNER JOIN person pe ON pe.personid = o.personid
		    		WHERE token=:1", array($once));
		    	if (sizeof($token)) {
		    		$token = $token[0];
		    		$qs = $_SERVER['QUERY_STRING'] ? (preg_replace('/(&amp;)?token=\w+/', '', str_replace('&', '&amp;', $_SERVER['QUERY_STRING']))) : null;
		    		if ($qs) $qs = '?'.$qs;

		    		if ($this->app->request->getResourceUri().$qs == $token['VALIDITY']) {
		    			$_REQUEST['prop'] = $token['PROP'];
		    			$this->user = $token['LOGIN'];
		    			$need_auth = false;
		    			$this->db->pq("DELETE FROM SW_onceToken WHERE token=:1", array($once));
		    		}
		    	} else {
		    		$this->_error(400, 'Invalid one time authorisation token');
		    	}
		    }

		    # Remove tokens more than 10 seconds old, they should have been used
		    $this->db->pq("DELETE FROM SW_onceToken WHERE TIMESTAMPDIFF('SECOND', recordTimeStamp, CURRENT_TIMESTAMP) > 10");

            if ($need_auth) $this->check_auth();
		}



		// Generate a new JWT encryption key
		function generate_jwt_key() {
            $this->_output(200, array('key' => base64_encode(openssl_random_pseudo_bytes(64))));
		}


		// Generates a JWT token with the login embedded
		function generate_jwt($login) {
            global $jwt_key;
			$key = base64_decode($jwt_key);

			$now = time();
			$data = array(
		        'iat'  => $now,
		        'jti'  => sha1($login . microtime(true)),//base64_encode(mcrypt_create_iv(32)),
		        'iss'  => 'http://'.$_SERVER['HTTP_HOST'],
		        'nbf'  => $now,
		        'exp'  => $now + 10 + 60*60*24,
		        'data' => array(
		            'login' => $login,
		        )
		    );

			$jwt = JWT::encode($data, $jwt_key, 'HS512');
			return array('jwt' => $jwt);
		}


		// Checks any supplied JWT is valid
		function check_auth() {
            global $jwt_key;
			$key = base64_decode($jwt_key);

			// $auth_header = $this->app->request->headers->get('authorization');
			$headers = getallheaders();

			if (array_key_exists('Authorization', $headers)) {
				$auth_header = $headers['Authorization'];
			} else $auth_header = '';

			if ($auth_header) {
				list($jwt) = sscanf($auth_header, 'Bearer %s');

				try {
					$token = JWT::decode($jwt, $jwt_key, array('HS512'));
					$this->user = $token->data->login;

				// Invalid token
				} catch (\Exception $e) {
					$this->_error(401, 'Invalid authorisation token');
				}

			} else {
				$this->_error(401, 'No authorisation token provided');
			}
		}



		// Send correct for errors
		function _output($code, $message) {
            $this->app->response->setStatus($code);

			// Cant call $app->halt as app not yet running, just end process
			header('X-PHP-Response-Code: '.$code, true, $code);
       		header('Content-Type: application/json');
			print json_encode($message);
			exit();
		}

		function _error($code, $message) {
            $this->_output($code, array('error' => $message));
		}



		// Calls the relevant Authentication Mechanism
		function authenticate()
        {
            global $authentication_type;

            // urgh
            $login = $this->app->request->post('login');
            $password = $this->app->request->post('password');

            if (!$login) {
                $bbreq = (array)json_decode($this->app->request()->getBody());
                $login = $bbreq['login'];
                $password = $bbreq['password'];
            }

            if (!$login) $this->_error(400, 'No login specified');
            if (!$password) $this->_error(400, 'No password specified');

            $user = $this->db->pq("SELECT personid FROM person WHERE login=:1", array($login));
            if (!sizeof($user)) {
                $this->_error(400, 'Invalid Credentials');
            }

            if ($this->authenticateByType($authentication_type)->authenticate($login, $password)) {
                $this->_output(200, $this->generate_jwt($login));
            } else {
                $this->_error(400, 'Invalid Credentials');
            }
        }

		// Logout
		function logout() {

		}

    // Return instance of authentication class corresponding to $authentication_type.
    // The value passed by the calling method derives from $authentication_type, a global variable specified in config.php.

    private function authenticateByType($authentication_type)
    {
        // Array of authentication types and corresponding authentication class names.
        // Value is class name in SynchWeb\Authentication\Type namespace.
        // Key is lower case representation of class name.
        $authentication_types = array(
            'cas' => 'CAS',
            'dummy' => 'Dummy',
            'ldap' => 'LDAP',
            'simple' => 'Simple'
        );

        if (!$authentication_type) {
            error_log("Authentication method not specified in config.php.");

            $authentication_type = 'CAS';
        }

        // Determine fully-qualified class name of authentication class corresponding to $authentication_type.

        $full_class_name = null;

        if (key_exists(strtolower($authentication_type), $authentication_types)) {
            $full_class_name = 'SynchWeb\\Authentication\\Type\\' . $authentication_types[$authentication_type];
        } else {
            error_log("Authentication method '$authentication_type' not configured.");
        }

        // Return instance of authentication class.

        if (class_exists($full_class_name)) {
            return new $full_class_name();
        } else {
            error_log("Authentication class '$full_class_name' does not exist.");
        }

        $this->_error(500, 'Authentication not possible due to a configuration error.');
    }
}
