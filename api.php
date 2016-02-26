<?php

class CitrixAPI {
	
  public static $oauth, $session_manager, $last_response, $auth_type;
  protected static $url, $client_id, $secret, $ch, $headers;

  const GET = 'GET';
  const POST = 'POST';

  public static function setup($client_id, $options = array('session_manager' => 'CitrixAPISession', 'curl_options' => array())) {
    // Setup client info
    self::$client_id = $client_id;

    // Setup curl
    self::$url = empty($options['api_url']) ? 'https://api.citrixonline.com' : $options['api_url'];
    self::$ch = curl_init();
    self::$headers = array(
      'Accept' => 'application/json',
    );
    curl_setopt(self::$ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt(self::$ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt(self::$ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt(self::$ch, CURLOPT_USERAGENT, 'CitrixAPI PHP Client/3.0');
    curl_setopt(self::$ch, CURLOPT_HEADER, true);
    curl_setopt(self::$ch, CURLINFO_HEADER_OUT, true);

    if ($options && !empty($options['curl_options'])) {
      curl_setopt_array(self::$ch, $options['curl_options']);
    }

    self::$session_manager = null;
    if ($options && !empty($options['session_manager']) && class_exists($options['session_manager'])) {
      self::$session_manager = new $options['session_manager'];
      self::$oauth = self::$session_manager->get();
    }

    // Register shutdown function for debugging and session management
    register_shutdown_function('CitrixAPI::shutdown');
  }
	
	
	
	public static function authenticate_direct($email, $password, $consumer_key) {
		$response = CitrixAPI::get("/oauth/access_token?grant_type=password&user_id=$email&password=$password&client_id=$consumer_key")->body;
		if (!empty($response)) {
			$body = json_decode($response, true);
  			CitrixAPI::$oauth = new CitrixAPIOAuth($body['access_token'], $body['refresh_token'], $body['expires_in'], $body['organizer_key']);
			return true;
		}
	}
	
	
  public static function authenticate_with_authorization_code($authorization_code) {
    $data = array();
    $auth_type = array('type' => 'authorization_code');
   
    $data['grant_type'] = 'authorization_code';
    $data['code'] = $authorization_code;

    $request_data = array_merge($data, array('client_id' => self::$client_id));
    if ($response = self::request(self::GET, '/oauth/access_token', $request_data, array('oauth_request' => true))) {
      $body = $response->json_body();
      self::$oauth = new CitrixAPIOAuth($body['access_token'], $body['refresh_token'], $body['expires_in'], $body['organizer_key']);
      self::$auth_type = $auth_type;
      return true;
    }
    return false;
  }

  public static function is_authenticated() {
    return self::$oauth && self::$oauth->access_token;
  }

  public static function request($method, $url, $attributes = array(), $options = array()) {
    if (!self::$ch) {
		throw new Exception('Client has not been setup with client id and client secret.');
    }

    // Reset attributes so we can reuse curl object
    curl_setopt(self::$ch, CURLOPT_POSTFIELDS, null);
    unset(self::$headers['Content-length']);
    $original_url = $url;
    $encoded_attributes = null;

    if (is_object($attributes) && substr(get_class($attributes), 0, 5) == 'CitrixAPI') {
      $attributes = $attributes->as_json(false);
    }

    if (!is_array($attributes) && !is_object($attributes)) {
      throw new CitrixAPIError('Attributes must be an array');
    }

    switch ($method) {
      case self::GET:
        curl_setopt(self::$ch, CURLOPT_CUSTOMREQUEST, self::GET);
        self::$headers['Content-type'] = 'application/x-www-form-urlencoded';
        if ($attributes) {
          $query = self::encode_attributes($attributes);
          $url = $url.'?'.$query;
        }
        self::$headers['Content-length'] = "0";
        break;
		
      case self::POST:
        curl_setopt(self::$ch, CURLOPT_CUSTOMREQUEST, self::POST);
        if (!empty($options['upload'])) {
          curl_setopt(self::$ch, CURLOPT_POST, TRUE);
          curl_setopt(self::$ch, CURLOPT_POSTFIELDS, $attributes);
          self::$headers['Content-type'] = 'multipart/form-data';
        }
        elseif (empty($options['oauth_request'])) {
          // application/json
          $encoded_attributes = json_encode($attributes);
          curl_setopt(self::$ch, CURLOPT_POSTFIELDS, $encoded_attributes);
          self::$headers['Content-type'] = 'application/json';
        }
        else {
          // x-www-form-urlencoded
          $encoded_attributes = self::encode_attributes($attributes);
          curl_setopt(self::$ch, CURLOPT_POSTFIELDS, $encoded_attributes);
          self::$headers['Content-type'] = 'application/x-www-form-urlencoded';
        }
        break;
    }

    // Add access token to request
    if (isset(self::$oauth) && !empty(self::$oauth->access_token) && !(isset($options['oauth_request']) && $options['oauth_request'] == true)) {
      $token = self::$oauth->access_token;
      self::$headers['Authorization'] = "OAuth oauth_token={$token}";
    }
    else {
      unset(self::$headers['Authorization']);
    }

    // File downloads can be of any type
    if (empty($options['file_download'])) {
      self::$headers['Accept'] = 'application/json';
    }
    else {
      self::$headers['Accept'] = '*/*';
    }

    curl_setopt(self::$ch, CURLOPT_HTTPHEADER, self::curl_headers());
    curl_setopt(self::$ch, CURLOPT_URL, empty($options['file_download']) ? self::$url.$url : $url);
    $response = new CitrixAPIResponse();
    $raw_response = curl_exec(self::$ch);
    $raw_headers_size = curl_getinfo(self::$ch, CURLINFO_HEADER_SIZE);
    $response->body = substr($raw_response, $raw_headers_size);
    $response->status = curl_getinfo(self::$ch, CURLINFO_HTTP_CODE);
    $response->headers = self::parse_headers(substr($raw_response, 0, $raw_headers_size));
    self::$last_response = $response;
    
    switch ($response->status) {
      case 200 :
      case 201 :
      case 204 :
        return $response;
        break;
      case 400 :
        // invalid_grant_error or bad_request_error
        $body = $response->json_body();
        if (strstr($body['error'], 'invalid_grant')) {
          // Reset access token & refresh_token
          self::$oauth = new CitrixAPIOAuth();
          throw new CitrixAPIError($response->body, $response->status, $url);
          break;
        }
        else {
          throw new CitrixAPIError($response->body, $response->status, $url);
        }
        break;
      case 401 :
        $body = $response->json_body();
        if (strstr($body['error_description'], 'expired_token') || strstr($body['error'], 'invalid_token')) {
          if (self::$oauth->refresh_token) {
            // Access token is expired. Try to refresh it.
            if (self::authenticate('refresh_token', array('refresh_token' => self::$oauth->refresh_token))) {
              // Try the original request again.
              return self::request($method, $original_url, $attributes);
            }
            else {
              self::$oauth = new CitrixAPIOAuth();
              throw new CitrixAPIError($response->body, $response->status, $url);
            }
          }
          else {
            // We have tried in vain to get a new access token. Log the user out.
            self::$oauth = new CitrixAPIOAuth();
            throw new CitrixAPIError($response->body, $response->status, $url);
          }
        }
        elseif (strstr($body['error'], 'invalid_request')) {
          // Access token is invalid.
          self::$oauth = new CitrixAPIOAuth();
          throw new CitrixAPIError($response->body, $response->status, $url);
        }
        break;
      default :
		//self::$oauth = new CitrixAPIOAuth();
        //throw new CitrixAPIError($response->body, $response->status, $url);
        return $response;
        break;
    }
    return false;
  }

  public static function get($url, $attributes = array(), $options = array()) {
    return self::request(CitrixAPI::GET, $url, $attributes, $options);
  }
  public static function post($url, $attributes = array(), $options = array()) {
    return self::request(CitrixAPI::POST, $url, $attributes, $options);
  }

  public static function curl_headers() {
    $headers = array();
    foreach (self::$headers as $header => $value) {
      $headers[] = "{$header}: {$value}";
    }
    return $headers;
  }
  public static function encode_attributes($attributes) {
    $return = array();
    foreach ($attributes as $key => $value) {
      $return[] = urlencode($key).'='.urlencode($value);
    }
    return join('&', $return);
  }
  public static function url_with_options($url, $options) {
    $parameters = array();

    if (isset($options['silent']) && $options['silent']) {
      $parameters[] = 'silent=1';
    }

    if (isset($options['hook']) && !$options['hook']) {
      $parameters[] = 'hook=false';
    }

    if (!empty($options['fields'])) {
      $parameters[] = 'fields='.$options['fields'];
    }

    return $parameters ? $url.'?'.join('&', $parameters) : $url;
  }
  public static function parse_headers($headers) {
    $list = array();
    $headers = str_replace("\r", "", $headers);
    $headers = explode("\n", $headers);
    foreach ($headers as $header) {
      if (strstr($header, ':')) {
        $name = strtolower(substr($header, 0, strpos($header, ':')));
        $list[$name] = trim(substr($header, strpos($header, ':')+1));
      }
    }
    return $list;
  }

  public static function shutdown() {
    // Write any new access and refresh tokens to session.
    if (self::$session_manager) {
      self::$session_manager->set(self::$oauth, self::$auth_type);
    }
  }
}

class CitrixAPIOAuth {
  public $access_token;
  public $refresh_token;
  public $expires_in;
  public $organizer_key;
  public function __construct($access_token = null, $refresh_token = null, $expires_in = null, $organizer_key = null) {
    $this->access_token = $access_token;
    $this->refresh_token = $refresh_token;
    $this->expires_in = $expires_in;
	$this->organizer_key = $organizer_key;
  }
}

class CitrixAPIError extends Exception {
  public $body;
  public $status;
  public $url;
  public function __construct($body, $status, $url) {
    $this->body = json_decode($body, TRUE);
    $this->status = $status;
    $this->url = $url;
    $this->request = $this->body['request'];
  }

  public function __toString() {
    $str = $str = get_class($this);
    if (!empty($this->body['error_description'])) {
      $str .= ': "'.$this->body['error_description'].'"';
    }
    $str .= "\nRequest URL: ".$this->request['url'];
    if (!empty($this->request['query_string'])) {
      $str .= '?'.$this->request['query_string'];
    }
    if (!empty($this->request['body'])) {
      $str .= "\nRequest Body: ".json_encode($this->request['body']);
    }

    $str .= "\n\nStack Trace: \n".$this->getTraceAsString();
    return $str;
  }
}

class CitrixAPIResponse {
  public $body;
  public $status;
  public $headers;
  public function json_body() {
    return json_decode($this->body, TRUE);
  }
}

class CitrixAPISession {

  public function __construct() {
    if(!session_id()) {
      session_start();
    }
  }

  /**
   * Get oauth object from session, if present
   */
  public function get($auth_type = null) {
    if (!empty($_SESSION['CitrixAPI-php-session'])) {
      return new CitrixAPIOAuth($_SESSION['CitrixAPI-php-session']['access_token'], $_SESSION['CitrixAPI-php-session']['refresh_token'], $_SESSION['CitrixAPI-php-session']['expires_in'], $_SESSION['CitrixAPI-php-session']['organizer_key']);
    }
    return new CitrixAPIOAuth();
  }

  /**
   * Store the oauth object in the session
   */
  public function set($oauth, $auth_type = null) {
    $_SESSION['CitrixAPI-php-session'] = array(
      'access_token' => $oauth->access_token,
      'refresh_token' => $oauth->refresh_token,
      'expires_in' => $oauth->expires_in,
	  'organizer_key' => $oauth->organizer_key,
    );
  }

  /**
   * Destroy the session
   */
  public function destroy() {
    unset($_SESSION['CitrixAPI-php-session']);
  }

}