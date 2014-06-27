<?php

/**
 * DrQuickTools. 
 *
 * Provide tools functions for general purpose.
 * 
 * All quicktools functions begin with underscore (_).
 *
 */

/**
 *
 * Abbreviation for *words* : shortcut to php's explode function.
 *
 * Also removes empty strings.
 *
 * Parameters:
 *  - $string : the string to explode
 *  - $sep : the separator, defaults to space (" ")
 *
 * Exemple:
 * <code>
 * _w("these are four elements") == array("these", "are", "four", "elements");
 * foreach (_w("banana apple orange") as $fruit) echo "Fruit : $fruit\n";
 *
 * _w("tada") === (array)"tada" === array("tada")
 * _w("a,b,", ",") === array("a", "b") // removes empty values
 * _w("a,b,,c , e , ,  ", ",") === array("a", "b", "c", "e") // auto trim
 * _w(array("a", "b")) == array("a", "b") // don't change arrays
 * </code>
 *
 * @param mixed $string
 * @param string $sep
 * @return array
 */
function _w($string, $sep = " ") {

	if (empty($string))
		return array();

	if (is_array($string))
		return $string;

	if (is_string($string))
		return _array_clean(explode($sep, $string));

	return (array)$string;

}

/**
 * Abbreviation for *sprintf*.
 *
 * shortcut to php's sprintf function.
 *
 * Parameters: See php's sprintf function.
 *
 * Exemple: _s("%d %s", 5, "foo")
 * 
 * @param string $format 
 * @return string
 */
function _s($format) {
	$args = func_get_args();
	return call_user_func_array("sprintf", $args);
}

/**
 * Creates an associative array from a string.
 *
 * Exemples : 
 * _a("first:1th,second:2nd") === array("first"=>"1th", "second"=>"2nd")
 *
 * _a("a:1,b:2", array("b"=>22,"c"=>33)) === array("a"=>"1","b"=>2,"c"=>33);
 *
 * _a(null) === array();
 *
 *
 * Limitations:
 * keys cannot contain the ":" character.
 * You can escape "," with "\,".
 * 
 * @param string $string 
 * @param mixed $defaults if given, _apply is called on the array
 * @return array
 */
function _a($string, $defaults = null) {

	if ($string === null) {
		$ret = array();
	} elseif (is_array($string)) {
		// Already an array, do nothing
		$ret = $string;
	} else {

		$string = str_replace('\,', 'ßcommaß', $string, $replaced);

		// Let's convert the string
		$list = explode(",", (string)$string);
		$ret = array();
		foreach ($list as $val) {
			list($k,$v) = explode(":", $val, 2);
			if ($replaced > 0) {
				$k = str_replace('ßcommaß', ',', $k);
				$v = str_replace('ßcommaß', ',', $v);
			}
			$ret[$k] = $v;
		}
	}
	if ($defaults !== null)
		_apply($ret, $defaults);

	return $ret;
}

/**
 * Applies defaults to an associative array.
 *
 * It also tries to convert datatypes according to defaults.
 *
 * See tests for some exemples.
 * 
 * @param array $config 
 * @param array $defaults 
 * @return array
 */
function _apply(&$config, $defaults = null) {

	if (!is_array($config))
		$config = array();

	if ($defaults === null)
		return $config;

	// lookup defaults, and convert var types
	foreach ($defaults as $k => $v) {
		if (!array_key_exists($k, $config)) {
			$config[$k] = $v;
		} elseif ($config[$k] !== null) {
			// convert values according to default
			if (is_bool($v))
				$config[$k] = _to_bool($config[$k]);
			elseif (is_int($v))
				$config[$k] = (int)($config[$k]);
			elseif (is_float($v))
				$config[$k] = (float)($config[$k]);
		}
	}

	return $config;
}

/**
 * Html Entities.
 * Abbreviation for *html* : shortcut to php's htmlentities
 *
 * <code>
 * echo "This is bold : <b>bold</b>";
 * echo _h("This is written as : <b>bold</b>");
 * </code>
 *
 * @param string $string the string to convert
 * @return string
 */
function _h($string) {
	global $DRASILL_CONF;
	return str_replace("'", "&#145;", htmlentities($string, ENT_COMPAT,
	   	$DRASILL_CONF["encoding"]));
}

/**
 * Html Entities.
 *
 * Abbreviation for *html utf8* : shortcut to php's htmlentities, with utf8 
 * encoding.
 *
 * <code>
 * echo "This is bold : <b>bold</b>";
 * echo _h("This is written as : <b>bold</b>");
 * </code>
 *
 * @param string $string the string to convert
 * @return string
 */
function _hu($string) {
	return str_replace("'", "&#145;", htmlentities($string, ENT_COMPAT, "UTF-8"));
}

/**
 * Truncate a string, adding an ellipsis (...) if effectively shortened.
 *
 * Exemples :
 * <code>
 * // "i am too long"
 * //  1 3  6   10 13
 * _trunc("i am too long", 7) == "i am...";   // strictly 7 chars
 * _trunc("i am too long", 9) == "i am t..."; // strictly 9 chars
 * _trunc("i am too long", 9, true) == "i am..."; // truncated at word
 * </code>
 * 
 * @param string $string 
 * @param int $maxlen 
 * @param bool $smart if true, truncate at word (so that $string can be shorter 
 * than maxlen)
 * @return string
 */
function _trunc($string, $maxlen, $smart = false) {

	if ($maxlen < 1)
		return "";

	if (strlen($string) <= $maxlen)
		return $string;
	if (!$smart or $maxlen < 10)
		return substr($string, 0, $maxlen - 3) . "...";

	$string = substr($string, 0, $maxlen + 20);
	while (strlen($string) > $maxlen - 3 and $string != "")
		$string = preg_replace("/\W*\w+\W*$/", "", $string);

	return $string . "...";

}

/**
 * Pluralize one or more words.
 * 
 * Exemples :
 * <code>
 * _pluralize(3, "house")  -> "3 houses"
 * _pluralize(1, "dog")    -> "1 dog"
 * _pluralize(1, "dog", true) -> "dog"
 * 
 * $numRes = 3;
 * $s = "Vous avez " . _pluralize($numRes, "resultat different") . " : "
 * // "Vous avez 3 resultats differents : "
 * </code>
 * 
 * @param int The number of elements          
 * @param string the name of elements            
 * @param bool do not show $num if it equals 1 
 * @return void
 */
function _pluralize($num, $string, $hideNumIfOne = false) {
	if ($num > 1) {
		foreach (explode(" ", $string) as $token)
			$newString[] = $token . 's';
		$newString = join(" ", $newString);
	} else {
		$newString = $string;
		if ($hideNumIfOne) $num = "";
	}
	return trim("$num $newString");
}

/**
 * Format a number.
 *
 * @param mixed $val number to format
 * @param int $decimals number of decimals
 * @param string|null $symbol a unit to suffix the number
 * @return string
 */
function _number($val, $decimals = 2, $symbol = null) {
	if (is_string($val))
		$val = (float)preg_replace("/[^\d\.,]/", "", $val);
	if ($symbol !== null)
		$symbol = "&nbsp;$symbol";
	return number_format($val, $decimals, ".", " ") . $symbol;
}

/**
 * Format a number as money.
 *
 * <code>
 * echo "You have " . _money(50.35, 2, "&euro;");
 * echo "Price : " . _money($price) . " Euros";
 * </code>
 * 
 * @param mixed the amount                                 
 * @param int   number of decimals to show (defaults to 2) 
 * @param mixed symbol to append (default to null = none)  
 * @return string
 */
function _money($val, $decimals = 2, $symbol = null) {
	if (is_string($val))
		$val = (float)preg_replace("/[^\d\.,]/", "", $val);
	if ($symbol !== null)
		$symbol = "&nbsp;$symbol";
	return number_format($val, $decimals, ".", " ") . $symbol;
}


/**
 * Alias for calling _money with euro suffix
 * 
 * @param float $val 
 * @param int $decimals 
 * @return string
 */
function _euro($val, $decimals = 2) {
	return _money($val, $decimals, "&euro;");
}


/**
 * Abbreviation for utf8 encode.
 *
 * Shortcut to php's utf8_encode function.
 *
 * The $detect option uses _isUtf8().
 *
 * @param string $s the string to encode
 * @param bool $detect do not encode if the string is already utf8
 * @return string
 */
function _u($s, $detect = false) {
	if ($detect and _isUtf8($s))
		return $s;
	return utf8_encode($s);
}


/**
 * Abbreviation for utf8 decode.
 *
 * Shortcut to php's utf8_decode function.
 *
 * The $detect option uses _isUtf8().
 *
 * @param string $s the string to decode
 * @param bool $detect do not decode if the string is not utf8
 * @return string
 */
function _ud($s, $detect = false) {
	if ($detect and !_isUtf8($s))
		return $s;
	return utf8_decode($s);
}

/**
 * Abbreviation for *utf8 encode array*.
 *
 * utf8_encode strings in an array.
 * 
 * It will only encode strings contained in the array, recursively.
 * 
 * @param array $a the array to encode       
 * @param bool $encodeKeys if true, also encode keys 
 * @return array
 */
function _ua($a, $encodeKeys = false) {
	if (is_array($a)) {
		$newA = array();
		foreach ($a as $k => $v) {
			if (is_array($v)) {
				$v =_ua($v, $encodeKeys);
			} else if (is_string($v)) {
				$v = utf8_encode($v);
			}
			if ($encodeKeys and is_string($k))
				$k = utf8_encode($k);
			$newA[$k] = $v;
		}
		return $newA;
	} else if (is_string($a)) {
		return utf8_encode($a);
	} else return $a;

}

/**
 * Abbreviation for *utf8 decode array*.
 *
 * utf8_decode strings in an array.
 * 
 * It will only decode strings contained in the array, recursively.
 * 
 * @param mixed $a the array to decode
 * @return array
 */
function _uda($a) {
	if (is_array($a)) {
		foreach (array_keys($a) as $k)
			$a[$k] = _uda($a[$k]);
		return $a;
	} else if (is_string($a)) {
		return utf8_decode($a);
	} else return $a;
}

/**
 * Test if a string is utf8.
 *
 * Uses the php function mb_detect_encoding.
 *
 * Returns true if the string is empty, or even if it has no special chars.
 *
 * This alias exists because the mb_detect_encoding syntax is pretty awkyard.
 *
 * @param string $s 
 * @return boolean
 */
function _isUtf8($s) {
	return (mb_detect_encoding($s, 'UTF-8', true) !== false);
}


/**
 * Abbreviation for *json decode*.
 *
 * shortcut to php's json_decode function.
 *
 * <code>
 * $values = _j('{name:"john",age:18}');
 * $values === array("name"=>"john","age"=>18);
 * </code>
 * 
 * @param string $json  the string to decode                              
 * @param array $assoc decode to an associative array (defaults to true) 
 * @return mixed
 */
function _j($json, $assoc = true) {
	return json_decode($json, $assoc);
}

/**
 * Abbreviation for *json encode*.
 *
 * shortcut to php's json_encode function.
 * 
 * Returns valid json corresponding to php values.
 * 
 * <code>
 * $values = _je(array("name"=>"john","age"=>18));
 * $values === '{"name":"john","age":18}';
 * </code>
 * 
 * Note:
 * Strings values *must* be in utf8 !
 * 
 * If not you can use <_ua> : $json = _je(_ua($myArray));
 * 
 * @param mixed $v value
 * @return string
 */
function _je($v) {
	return json_encode($v);
}

/**
 * "Underscorize" a string.
 *
 * <code>
 * _underscorize('What the hell') == 'what_the_hell'
 * _underscorize('I', 'am', not ', ' ', 'done', null) == 'i_am_not_done'
 * _underscorize('i say', 'o-yeah', '!') == 'i_say_o_yeah'
 * </code>
 *
 * @access protected
 * @return void
 */
function _underscorize() {
	// first, join args
	$str = join("_", _array_clean(func_get_args()));
	// then, lower case & remove accents
	$str =_removeAccents(strtolower($str));
	// replace bad chars by "_"
	$str = preg_replace('/\W/', '_', $str);
	// chop bad underscores
	$str = preg_replace('/_+/', '_', $str);
	$str = trim($str, '_');

	return $str;
}

/**
 * "Camelize" a string.
 *
 * <code>
 * _camelize('What the hell') == 'WhatTheHell'
 * _camelize('I', 'am', not ', ' ', 'done', null) == 'IamNotDone'
 * _camelize('i say', 'o-yeah', '!') == 'IsayOyeah'
 * </code>
 * 
 * @return string
 */
function _camelize() {
	// first, join args
	$str = join(" ", _array_clean(func_get_args()));
	// then, lower case & remove accents
	$str =_removeAccents(strtolower($str));
	// replace bad chars by a space
	$str = preg_replace('/\W/', ' ', $str);
	// chop double spaces
	$str = preg_replace('/ +/', ' ', $str);

	// resplit
	$tokens = _w($str);

	// creates the Camelized string from tokens.
	$camelized = ucfirst(array_shift($tokens));
	$lastTokenSize = strlen($camelized);
	foreach ($tokens as $token) {
		if ($lastTokenSize !== 1)
			$token = ucfirst($token);
		$camelized .= $token;
		$lastTokenSize = strlen($token);
	}

	return $camelized;
}

/**
 * "Camelize" a string, with first letter lowercased.
 *
 * <code>
 * _camelize_l('What the hell') == 'whatTheHell'
 * _camelize_l('I', 'am', not ', ' ', 'done', null) == 'iAmNotDone'
 * _camelize_l('i say', 'o-yeah', '!') == 'iSayOyeah'
 * </code>
 *
 * @return string
 */
function _camelize_l() {

	// first, join args
	$str = join(" ", _array_clean(func_get_args()));
	// then, lower case & remove accents
	$str = _removeAccents(strtolower($str));
	// replace bad chars by a space
	$str = preg_replace('/\W/', ' ', $str);
	// chop double spaces
	$str = preg_replace('/ +/', ' ', $str);

	// resplit
	$tokens = _w($str);

	// creates the Camelized string from tokens.
	$camelized = array_shift($tokens);
	$lastTokenSize = null;
	foreach ($tokens as $token) {
		if ($lastTokenSize !== 1)
			$token = ucfirst($token);
		$camelized .= $token;
		$lastTokenSize = strlen($token);
	}

	return $camelized;
}

/**
 * "URLize" a string.
 *
 *	- replace non alphanum with "-"
 *	- remove accents
 *
 * <code>
 * _urlize("my","taylor","is","rich") == "my-taylor-is-rich"
 * _urlize("i'm ‡ test_string") == "i-m-a-test-string"
 * </code>
 * 
 * @return string
 */
function _urlize() {
	$args = func_get_args();
	$string = join("-", $args);
	$string = drstring_decodeUnicode($string);
	$string = _removeAccents($string);
	$string = strtolower($string);
	$string = strtr($string, " _,;:%!?.()'\"/", "---------------");
	$string = str_replace("&", "et", $string);
	$string = ereg_replace("[-]+", "-", $string);
	return trim($string, "-");
}

/**
 * Join arrays into an array.
 * 
 * Useful for <DrEntity::Filters> construction.
 * 
 * Exemple:
 * <code>
 * _array_join("or", array(1,"2"), array(5,6), "toto") -> array(1,"2","or",5,6,"or","toto")
 * 
 * _array_join("sep", array(1, array(2,3), 4)) -> array(1, "sep", 2, 3, "sep", 4)
 * 
 * $filter = array();
 * $filter[] = array("age", ">", 18);
 * $filter[] = array("name", "ilike", "john%");
 * $filter[] = array("email", "isnull", "or", "email", "=", "");
 * 
 * $filter = _array_join($filter, "and");
 * 
 * $p = new Person();
 * $persons = $p->find($filter);
 * </code>
 * 
 * Here, filter will be :
 * <code>
 * array(
 *   array("age", ">", 18),
 *   "and",
 *   array("name", "ilike", "john%"),
 *   "and",
 *   array("email", "isnull", "or", "email", "=", "")
 * );
 * </code>
 * 
 * Which could be read in SQL as :
 * 
 * WHERE ( age > 18 ) AND ( name ILIKE 'john%' ) AND ( email IS NULL OR email = '' )
 * 
 * @param mixed $separator 
 * @return array
 */
function _array_join($separator) {

	$args = func_get_args();
	unset($args[0]);

	if (count($args) == 1 && is_array($args[1]))
		$args = $args[1];

	$result = array();

	foreach ($args as $arg) {
		if (is_array($arg)) {
			foreach ($arg as $val)
				$result[] = $val;
		} else {
			$result[] = $arg;
		}
		if ($separator !== null)
			$result[] = $separator;
	}
	array_pop($result);

	return $result;

}

/**
 * Converts assoc array to attributes.
 *
 * Attributes are like key="value".
 * 
 * @param array $array 
 * @return string
 */
function _array_to_attribs($array) {
	if (!is_array($array) || count($array) == 0)
		return "";
	$attribs = array();
	foreach($array as $k=>$v)
		$attribs[] = "$k=\"$v\"";
	return " " . join(" ", $attribs);
}


/**
 * convert simple notation attributes to array if necessary.
 *
 * ex: "size:5,id:toto" => array("size"=>5, "id"=>"toto")
 * 
 * @obsolete
 * @param mixed $attribs 
 * @return void
 */
function _simple_attribs_to_array($attribs) {
	if (is_array($attribs))
		return $attribs;
	if (empty($attribs))
		return array();
	$attribs = str_replace("\\,", "-- VIRGULE --", $attribs);
	$list = explode(",", $attribs);
	$ret = array();
	foreach ($list as $val) {
		$val = str_replace("-- VIRGULE --", ",", $val);
		$val = str_replace("\\:", "-- DEUX POINTS --",  $val);
		list($k,$v) = explode(":", $val);
		$k = str_replace("-- DEUX POINTS --", ":", $k);
		$v = str_replace("-- DEUX POINTS --", ":", $v);
		$ret[$k] = $v;
	}
	return $ret;
}

if(!function_exists('http_build_query')) {
	/**
	 * PolyFill for http_build_query.
	 * 
	 * @param mixed $formdata 
	 * @param mixed $numeric_prefix 
	 * @param mixed $key 
	 * @return string
	 */
	function http_build_query($formdata, $numeric_prefix = null, $key = null) {
		$res = array();
		foreach ((array)$formdata as $k=>$v) {
			$tmp_key = urlencode(is_int($k) ? $numeric_prefix.$k : $k);
			if ($key) $tmp_key = $key.'['.$tmp_key.']';
			if ( is_array($v) || is_object($v) ) {
				$res[] = http_build_query($v, null, $tmp_key);
			} else {
				$res[] = $tmp_key."=".urlencode($v);
			}
		}
		$separator = ini_get('arg_separator.output');
		return join($separator, $res);
	}
	
}

/**
 * Checks wether an array is associative.
 *
 * Slow, don't use it.
 *
 * return true if array is associative.
 * 
 * @obsolete
 * @param array $array 
 * @return bool
 */
function _array_is_assoc($array) {
	$keys = array_keys($array);
	natsort($keys); //Numeric keys will be first
	return is_string(array_shift($keys));
}



if (!function_exists("openssl_random_pseudo_bytes")) {
	function openssl_random_pseudo_bytes($length) {
		$cmd = sprintf("/usr/bin/openssl rand %d 2>/dev/null", $length);
		return `$cmd`;
	}
}


/**
 * Smart conversion from a string to a boolean
 * 
 * @param string $value 
 * @return void
 */
function _to_bool($value) {

	if (is_bool($value))
		return $value;

	if (is_string($value)) {
		switch (strtolower($value)) {
		case "f": case "false": case "non": case "0": case "n": case "no":
			return false;
		   	break;
		case "t": case "true": case "oui": case "1": case "o": case "y": case "yes":
			return true;
		   	break;
		}
	}

	return (bool)$value;

}

/**
 * Force conversion from a string to an int.
 *
 * _to_int("3'141'592'653") === 3141592653
 * _to_int("3 141")         === 3141
 * _to_int("3 141.59")      === 3141
 * _to_int("03,14")         === 3
 * _to_int("10m55")         === 10
 *
 * _to_int("anythingbad")   === 0
 * 
 * @param mixed $value 
 * @return int
 */
function _to_int($value) {

	if (is_int($value))
		return $value;
	if (is_float($value) or is_numeric($value))
		return (int)$value;

	try {

		$value = trim((string)$value);
		if ($value === "") return 0;

		// First, remove everything once a "word" char is found.
		// But only after a number is found.
		$conv = preg_replace('/(\d)[^0-9.,\'"\/\s].*$/', '$1', $value);
		// Then replace everything which is not a number part
		$conv = preg_replace('/[^0-9.,]/', '', $conv);

		$conv = (int)$conv;
		if ($value[0] === "-")
			$conv = -$conv;
		return $conv;

	} catch (Exception $e) {
		return 0;
	}

}


/**
 * Returns true if $haystack starts with $needle.
 *
 * if (_startsWith('Dr Who', 'Dr ', $r))
 *   echo "$r is a doctor";
 *
 * @param string $haystack
 * @param string $needle
 * @param string &$remainder if given, store the remainder here
 * @return bool
 */
function _startsWith($haystack, $needle, &$remain = null) {
	if (preg_match('/^'.preg_quote($needle,'/').'(.*)$/', $haystack, $m)) {
		$remain = $m[1];
		return true;
	}
	return false;
}

/**
 * Returns true if $haystack ends with $needle.
 *
 * @param string $haystack
 * @param string $needle
 * @param string &$remainder if given, store the remainder here
 * @return bool
 */
function _endsWith($haystack, $needle, &$remain = null) {
	if (preg_match('/^(.*)'.preg_quote($needle,'/').'$/', $haystack, $m)) {
		$remain = $m[1];
		return true;
	}
	return false;
}

/**
 * Cleans a string array.
 *
 * Trim strings
 * Removes empty strings (but keeps zero)
 * Removes null values
 *
 * _array_clean(array("a", "", "b", null)) == array("a", "b")
 * _array_clean(array(" a ", "\nb\n")) === array("a", "b")
 * 
 * @param array $a 
 * @return array
 */
function _array_clean(array $a) {
	$b = array();
	foreach ($a as $v) {
		if ($v === null)
			continue;
		if (is_string($v)) {
			$v = trim($v);
			if (strlen($v) === 0)
				continue;
		}
		$b[] = $v;
	}
	return $b;
}


/**
 * Returns the first element of an array.
 *
 * Ex : _first(getPhotos());
 *
 * In PHP < 5.4, we could use 'reset'.
 * In PHP >= 5.4, we can use getPhotos()[0].
 *
 * Returns boolean false if the array is empty.
 *
 * @param array $a
 * @return mixed
 */
function _first(array $a) {

	if (empty($a))
		return false;

	if (isset($a[0]))
		return $a[0];

	// It seems that $a is an associative array.
	$k = array_keys($a);
	return $a[$k[0]];

}

/**
 * Returns the last element of an array.
 *
 * Ex : _last(getErrors());
 *
 * Returns boolean false if the array is empty.
 *
 * @param array $a
 * @return mixed
 */
function _last(array $a) {

	$cnt = count($a);

	if ($cnt === 0)
		return false;

	$cnt--;

	// We have to test for $a[0] so that _last(array(1=>'a', 2=>'b')) works too.
	if (isset($a[$cnt]) and isset($a[0]))
		return $a[$cnt];

	// It seems that $a is an associative array.
	$k = array_keys($a);
	return $a[$k[$cnt]];

}

/**
 * Removes accents in a string (keeping case).
 *
 * @param string $s
 * @return string
 */
function _removeAccents($s) {
	return strtr($s,
		"¿¡¬√ƒ≈‡·‚„‰Â“”‘’÷ÿÚÛÙıˆ¯»… ÀËÈÍÎ«ÁÃÕŒœÏÌÓÔŸ⁄€‹˘˙˚¸ˇ—Ò",
		"AAAAAAaaaaaaOOOOOOooooooEEEEeeeeCcIIIIiiiiUUUUuuuuyNn");
}

/**
 * Returns the number of occurence of $needle in the $haystack.
 *
 * Works for string, or array.
 *
 * @param string|array $haystack
 * @param string|array $needle
 * @return int
 */
function _count($haystack, $needle) {

	if (is_array($haystack)) {
		$stats = array_count_values($haystack);
		if (!isset($stats[$needle]))
			return 0;
		return $stats[$needle];
	}

	if (is_object($haystack))
		throw new Exception("Cannot use _count on object");

	return preg_match_all('/'.preg_quote($needle, "/").'/', $haystack);

}
