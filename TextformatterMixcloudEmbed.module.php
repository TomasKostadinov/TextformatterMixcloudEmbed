<?php

/**
 * ProcessWire Mixcloud Embedding Textformatter
 * Copyright (c) 2017 by Conclurer GmbH / Tomas Kostadinov
 *
 * Looks for Mixcloud URLs and automatically converts them to embeds.
 *
 * Based on the Soundcloud Embedding Textformatter by Conclurer / Marvin Scharle
 *
 * ProcessWire 3.x
 * Copyright (C) 2012 by Ryan Cramer
 * Licensed under GNU/GPL v2, see LICENSE.TXT
 *
 * http://processwire.com
 *
 *
 *
 */

class TextformatterMixcloudEmbed extends Textformatter implements ConfigurableModule {

	public static function getModuleInfo() {
		return array(
			'title' => __('Mixcloud embed', __FILE__),
			'version' => 100,
			'summary' => __('Enter a full Mixcloud url in a separate row to turn it into a customizable Mixcloud widget. Based on Embed for Soundcloud by Conclurer/Marvin Scharle.', __FILE__),
			'author' => 'Conclurer GmbH / Tomas Kostadinov',
			'href' => ''
		);
	}

	const dbTableName = 'textformatter_mixcloud';

	protected static $configDefaults = array(
		'maxWidth' => -1,
		'maxHeight' => -1,
		'autoPlay' => 0,
		'showComments' => 1,
		'color' => ''
	);

	/**
	 * Data as used by the get/set functions
	 *
	 */
	protected $data = array();

	/**
	 * Set our configuration defaults
	 *
	 */
	public function __construct() {
		foreach(self::$configDefaults as $key => $value) {
			$this->set($key, $value);
		}
	}

	/**
	 * Given a service oembed URL and video ID, return the corresponding embed code.
	 *
	 * A cached version of the embed code will be used if possible. When not possible,
	 * it will be retrieved from the service's oembed URL, and then cached.
	 *
	 */
	protected function getEmbedCode($oembedURL, $widgetID) {

		$db = wire('db');
		$widgetID = $db->escape_string($widgetID);
		$result = $db->query("SELECT embed_code FROM " . self::dbTableName . " WHERE widget_url='$widgetID'");

		if($result->num_rows) {
			list($embedCode) = $result->fetch_row();
			return $embedCode;
		}

		$data = file_get_contents($oembedURL);

		if($data) $data = json_decode($data, true);


		if(is_array($data) && isset($data['html'])) {

			$embedCode = $data['html'];

			$sql = 	"INSERT INTO " . self::dbTableName . " SET " .
				"widget_url='$widgetID', " .
				"embed_code='" . $db->escape_string($embedCode) . "', " .
				"created=NOW() ";

			$db->query($sql);
		}

		$result->free();
		return $embedCode;
	}

	/**
	 * Make an iframe-based embed code responsive
	 *
	 */
	protected function makeResponsive($out) {
		$out = str_ireplace('<iframe ', '<iframe width="100%" height="120px" ', $out);
		$out = "<div class='TextformatterMixcloudEmbed' style='position:relative;padding:30px 0 56.25% 0;height:0;overflow:hidden;'>$out</div>";
		return $out;
	}

	/**
	 * Text formatting function as used by the Textformatter interface
	 *
	 * Here we look for video codes on first pass using a fast strpos() function.
	 * When found, we do our second pass with preg_match_all and replace the video URLs
	 * with the proper embed codes obtained from each service's oembed web service.
	 *
	 */
	public function format(&$str) {
		$this->embedMixcloud($str);
	}

	protected function embedMixcloud(&$str) {
		if (strpos($str, '://www.mixcloud.com/') === false) return;
		$regex = '/.*?((?:http|https)(?::\\/{2}[\\w]+)(?:[\\/|\\.]?)(?:[^\\s"]*))/is';	//Url
		preg_match_all($regex, $str, $matches);
		foreach ($matches[1] as $match) {
			if (strpos($match, '://www.mixcloud.com/') === false) continue;
			$match = str_replace("</p>", "", $match);
			$mixcloudId = str_replace("https", "http", $match);
			$oembedURL = "http://mixcloud.com/oembed?".$this->getOembedParameters()."format=json&url=".urlencode($match);
			$embedCode = $this->getEmbedCode($oembedURL, $mixcloudId);

			if ($embedCode) $str = str_replace($match, $embedCode, $str);
		}
		//exit;
	}

	protected function getOembedParameters () {
		$params = array();
		if ($this->maxWidth != -1) array_push($params, "width=".$this->maxWidth);
		if ($this->maxHeight != -1) array_push($params, "height=".$this->maxHeight);
		if ($this->color != "") array_push($params, "color=".$this->color);
		if (count($params) == 0) return "";
		return implode("&", $params)."&";
	}


	/**
	 * Module configuration screen
	 *
	 */
	public static function getModuleConfigInputfields(array $data) {

		foreach(self::$configDefaults as $key => $value) {
			if(!isset($data[$key])) $data[$key] = $value;
		}

		unset($data['cacheClear']);
		$inputfields = new InputfieldWrapper();

		$f = wire('modules')->get('InputfieldInteger');
		$f->attr('name', 'maxWidth');
		$f->attr('value', $data['maxWidth']);
		$f->label = __('Max Widget Width in px (-1 for 100%)');
		$inputfields->add($f);

		$f = wire('modules')->get('InputfieldInteger');
		$f->attr('name', 'maxHeight');
		$f->attr('value', $data['maxHeight']);
		$f->label = __('Max Widget Height in px (-1 for auto)');
		$inputfields->add($f);

		$f = wire('modules')->get('InputfieldText');
		$f->attr('name', 'color');
		$f->attr('value', $data['color']);
		$f->label = __('Color as six-digit hex value (without #)');
		$inputfields->add($f);


		if(wire('input')->post('clearCache')) {
			wire('db')->query("DELETE FROM " . self::dbTableName);
			wire('modules')->message(__('Cleared widget embed cache'));
		} else {
			$result = wire('db')->query("SELECT COUNT(*) FROM " . self::dbTableName);
			list($n) = $result->fetch_row();
			$f = wire('modules')->get('InputfieldCheckbox');
			$f->attr('name', 'clearCache');
			$f->attr('value', 1);
			$f->label = __('Clear widget cache?');
			$f->description = __('This will clear out cached embed codes. There is no harm in doing this, other than that it will force them to be re-pulled from Mixcloud as needed.');
			$f->notes = sprintf(__('There are currently %d widget(s) cached'), $n);
			$inputfields->add($f);
		}

		return $inputfields;
	}

	/**
	 * Installation routine
	 *
	 */
	public function ___install() {

		if(!ini_get('allow_url_fopen')) {
			throw new WireException("Your PHP has allow_url_fopen disabled, which is required by this module.");
		}

		$sql =	"CREATE TABLE " . self::dbTableName . " (" .
			"widget_url VARCHAR(255) NOT NULL PRIMARY KEY, " .
			"embed_code VARCHAR(1024) NOT NULL DEFAULT '', " .
			"created TIMESTAMP NOT NULL " .
			")";

		wire('db')->query($sql);

	}

	/**
	 * Uninstallation routine
	 *
	 */
	public function ___uninstall() {
		try { wire('db')->query("DROP TABLE " . self::dbTableName); } catch(Exception $e) { }
	}


	/**
	 * The following functions are to support the ConfigurableModule interface
	 * since Textformatter does not originate from WireData
	 *
	 */

	public function set($key, $value) {
		$this->data[$key] = $value;
		return $this;
	}

	public function get($key) {
		$value = Wire::getFuel($key);
		if($value) return $value;
		return isset($this->data[$key]) ? $this->data[$key] : null;
	}

	public function __set($key, $value) {
		$this->set($key, $value);
	}

	public function __get($key) {
		return $this->get($key);
	}


}
