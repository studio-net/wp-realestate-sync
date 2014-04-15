<?php
/*
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

/**
 * Helper Class to make REST requests to api.lesiteimmo.com.
 *
 * @package Lesiteimmo
 * @copyright 1997-2013 Lesiteimmo.com (c)
 * @author Christophe Badoit <c.badoit@lesiteimmo.com>
 */
class LsiPhpApi {

	private $key = null;

	private $referer = null;

	private $curlHandler;

	private $apiUrl = "http://api.lesiteimmo.com";

	private $format = "json";

	private $jsonp  = "jsonp";

	private $baseParams = array();

	/**
	 * Constructor
	 *
	 * @param string $key LSI Api Key
	 * @return void
	 */
	public function __construct($key = null) {

		$this->validDependencies();

		if ($key !== null)
			$this->setKey($key);

		if (isset($_SERVER["HTTP_HOST"]))
			$this->setReferer($_SERVER["HTTP_HOST"]);
	}

	/**
	 * Set API url (endpoint)
	 *
	 * @param string $url
	 * @return void
	 */
	public function setApiUrl($url) {
		$this->apiUrl = $url;
	}

	/**
	 * Set API key
	 *
	 * @param string $key LSI Api Key
	 * @return void
	 */
	public function setKey($key) {
		$this->key = $key;
	}

	/**
	 * Set Referer (which will be sent)
	 *
	 * @param mixed $referer
	 * @return void
	 */
	public function setReferer($referer) {
		$this->referer = $referer;
	}

	/**
	 * Set communication format
	 *
	 * This should not be changed - default format (json) is the fastest.
	 *
	 * @param string $format
	 * @return void
	 */
	public function setFormat($format) {
		if ($format != "json" and $format != "php" and $format != 'jsonp')
			throw new LsiPhpApiException("Unmanaged format");
		$this->format = $format;
	}

	/**
	 * Set the json callback string when using jsonp format.
	 *
	 * @param string $jsonp
	 * @return void
	 */
	public function setJsonpCallback($jsonp) {
		$this->jsonp = $jsonp;
	}

	/**
	 * Set the params which will be automatically set for each function call
	 *
	 * @param array $params
	 * @return void
	 */
	public function setBaseParams(array $params) {
		$this->baseParams = $params;
	}

	private function validDependencies() {
		if (!extension_loaded("curl"))
			throw new LsiPhpApiException("Curl Extension not available");
	}

	private function getCurlHandler() {

		if (!$this->curlHandler) {

			$ch = curl_init();

			$header = array();
			$header[] = "Accept: text/xml,application/xml,application/xhtml+xml,"
				. "text/html;q=0.9,text/plain;q=0.8,image/png,*/*;q=0.5";
			$header[] = "Cache-Control: max-age=0";
			$header[] = "Connection: keep-alive";
			$header[] = "Keep-Alive: 300";
			$header[] = "Accept-Charset: ISO-8859-1,utf-8;q=0.7,*;q=0.7";
			$header[] = "Accept-Language: en-us,en;q=0.5";
			$header[] = "Pragma: ";

			curl_setopt($ch, CURLOPT_HTTPHEADER    , $header);
			curl_setopt($ch, CURLOPT_AUTOREFERER   , true);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_TIMEOUT       , 10);
			curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
			curl_setopt($ch, CURLOPT_FAILONERROR   , false);

			if ($this->referer !== null)
				curl_setopt($ch, CURLOPT_REFERER, $this->referer);

			$this->curlHandler = $ch;

		}

		// clear params
		curl_setopt($this->curlHandler, CURLOPT_POSTFIELDS, null);

		return $this->curlHandler;

	}
	
	/**
	 * Replace bad chars for query string.
	 * 
	 * @param  string $query
	 * @return string        sanitized query
	 */
	private function sanitizeQuery($query){
		
		$query = str_replace(" ", "%20", $query);
		
		return $query;
		
	}

	private function makeRequest($method, $query, $params = array()) {

		$p = array(
			"key"    => $this->key,
			"format" => $this->format,
		);
		if ($this->format == "jsonp")
			$params["jsonp"] = $this->jsonp;

		$params = $p + (array)$params + $this->baseParams;
		
		$query = $this->sanitizeQuery($query);
		
		$url = $this->apiUrl . "/$query";

		$ch = $this->getCurlHandler();
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

		$putMemoryHandler = null;

		if ($method == "POST") {
			curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
			curl_setopt($ch, CURLOPT_URL, $url);
		} else if ($method == "PUT") {
			$maxSize = 1024 * 1024;
			$putMemoryHandler = fopen("php://temp/maxmemory:{$maxSize}", "rw");
			$encodedParams = http_build_query($params);
			fwrite($putMemoryHandler, $encodedParams);
			rewind($putMemoryHandler);

			curl_setopt($ch, CURLOPT_URL, $url);
			curl_setopt($ch, CURLOPT_PUT, true);
			curl_setopt($ch, CURLOPT_INFILE, $putMemoryHandler);
			curl_setopt($ch, CURLOPT_INFILESIZE, strlen($encodedParams));

		} else {
			curl_setopt($ch, CURLOPT_URL,
				$url . "?" . http_build_query($params));
		}

		$data = curl_exec($ch);

		if (curl_errno($ch))
			throw new LsiPhpApiException(curl_error($ch));

		if ($putMemoryHandler)
			fclose($putMemoryHandler);

		$statusCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		if ($statusCode !== 200) {
			// if content is given, get error message from content
			if (($data = $this->decode($data)) !== null and isset($data->error->message))
				throw new LsiPhpApiException($data->error->message, $statusCode);
			// otherwise, send code
			throw new LsiPhpApiException("Error code $statusCode", $statusCode);
		}

		$decoded = $this->decode($data);
		if ($decoded === null) {
			throw new LsiPhpApiException("Incorrect Data");
		}

		if ($this->format == "jsonp" and $decoded->error->code >= 400) {
			// JSONP does not set HTTP headers on errors...
			throw new LsiPhpApiException(
				$decoded->error->message, $decoded->error->code);
		}

		return $decoded;

	}

	/**
	 * Execute a GET request
	 *
	 * @param string $resource
	 * @param array $params
	 * @return mixed
	 */
	public function get($resource, array $params = array()) {

		return $this->makeRequest("GET", $resource, $params);

	}

	/**
	 * Execute a POST request
	 *
	 * @param string $resource
	 * @param array $params
	 * @param mixed $data data to post
	 * @return mixed
	 */
	public function post($resource, array $params = array(), $data = null) {

		if ($data !== null)
			$params["data"] = $this->encode($data);

		return $this->makeRequest("POST", $resource, $params);

	}

	/**
	 * Execute a DELETE request
	 *
	 * @param string $resource
	 * @param array $params
	 * @return mixed
	 */
	public function delete($resource, array $params = array()) {

		return $this->makeRequest("DELETE", $resource, $params);

	}

	/**
	 * Execute a PUT request
	 *
	 * @param string $resource
	 * @param array $params
	 * @param mixed $data data to put
	 * @return mixed
	 */
	public function put($resource, array $params = array(), $data = null) {

		if ($data !== null)
			$params["data"] = $this->encode($data);

		return $this->makeRequest("PUT", $resource, $params);

	}

	/**
	 * Decode data according to current format.
	 *
	 * @param string $data
	 * @return mixed
	 */
	private function decode($data) {
		switch($this->format) {
		case "json"  : return $this->jsonDecode($data);
		case "php"   : return unserialize($data);
		case "jsonp" :
			if (!preg_match('/^'.$this->jsonp.'\((.*)\);$/', $data, $m))
				return null;
			return $this->jsonDecode($m[1]);
		default      : throw new LsiPhpApiException("Unable to decode");
		}
	}

	/**
	 * Decodes a json string.
	 *
	 * @param string $data
	 * @return object
	 */
	private function jsonDecode($data) {

		return $this->parseJsonValues(json_decode($data));

	}

	/**
	 * Parse specific JSON values (dates, ...).
	 *
	 * @param object $value
	 * @return object
	 */
	private function parseJsonValues($value) {

		if (is_array($value) or is_object($value)) {
			foreach($value as &$v)
				$v = $this->parseJsonValues($v);
			return $value;
		}

		// Date format spotted !
		// Converts it to DateTime instance.
		// See http://lsi.im/jsondate1 http://lsi.im/jsondate2
		if (is_string($value)
			and preg_match('%^\\\/Date\((\d+)\)\\\/$%', $value, $m)) {
				$ts = (int)(substr($m[1], 0, -3));
				$d = new DateTime();
				return $d->setTimestamp($ts);
			}

		return $value;

	}


	/**
	 * Encode data according to current format.
	 *
	 * @param mixed $data
	 * @return string
	 */
	private function encode($data) {
		switch($this->format) {
		case "jsonp" :
		case "json"  : return json_encode($data);
		case "php"   : return serialize($data);
		default      : throw new LsiPhpApiException("Unable to encode");
		}
	}

}

class LsiPhpApiException extends Exception {};

