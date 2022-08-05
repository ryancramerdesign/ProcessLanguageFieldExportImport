<?php namespace ProcessWire;

/**
 * LanguageFieldTools
 *
 * ProcessWire 3.x, Copyright 2022 by Ryan Cramer
 * https://processwire.com
 *
 */

class LanguageFieldTools extends Wire {

	/**
	 * @var array|LanguageFieldPort[]
	 * 
	 */
	protected $ports = array();
	
	/**
	 * @param Language|null $sourceLanguage
	 * @param Language|null $targetLanguage
	 * @return array|LanguageFieldPort[]
	 *
	 */
	public function getPorts($sourceLanguage = null, $targetLanguage = null) {
		if(!empty($this->ports)) return $this->ports;
		
		foreach(new \DirectoryIterator(__DIR__) as $file) {
			if($file->isDot() || $file->isDir()) continue;
			$basename = $file->getBasename();
			if(strpos($basename, 'LanguageFieldPort') !== 0 || $basename === 'LanguageFieldPort.php') continue;
			require_once(__DIR__ . "/$basename");
			$className = basename($basename, '.php');
			$className = "\\ProcessWire\\$className";
			/** @var LanguageFieldPort $port */
			$port = $this->wire(new $className());
			if(!$port->usable()) continue;
			if($sourceLanguage) $port->setSourceLanguage($sourceLanguage);
			if($targetLanguage) $port->setTargetLanguage($targetLanguage);
			$this->ports[$className] = $port;
		}
		
		return $this->ports;
	}
	
	/**
	 * Get language from string
	 *
	 * @param string $value Language name or "name (title)" string
	 * @return Language|null
	 *
	 */
	public function getLanguage($value) {
		if(strpos($value, ' ')) list($value,) = explode(' ', $value, 2);
		$language = $this->wire()->languages->get($value);
		return $language && $language->id ? $language : null;
	}

	/**
	 * Get field by name or "a > b" string
	 *
	 * When given an "a > b" string, it will return the last field, i.e. "b".
	 * When given an "a.b" string, it will return "a".
	 * When given an "a[1]" string, it will return "a".
	 * When given an "a.b[1]" string, it will return "a".
	 *
	 * @param string $value
	 * @return Field|null
	 * @throws WireException
	 *
	 */
	public function getField($value) {
		
		if(strpos($value, '>')) {
			$a = explode('>', $value);
			$value = trim(array_pop($a));
		}
		
		if(strpos($value, '[')) list($value,) = explode('[', $value, 2); // i.e. field[1].col
		if(strpos($value, '.')) list($value,) = explode('.', $value, 2); // i.e. field.col
		
		return $this->wire()->fields->get(trim($value));
	}

	/**
	 * Get column name specified in an "field.column" string or blank if none
	 *
	 * @param string $value
	 * @return string
	 *
	 */
	public function getFieldCol($value) {
		if(strpos($value, '.') === false) return '';
		$a = explode('.', $value);
		$value = array_pop($a);
		if(ctype_alnum(str_replace('_', '', $value))) return $value;
		return '';
	}

	/**
	 * Get index num/name "index" from "field[index]" string
	 * 
	 * @param string $value
	 * @return string|int
	 * 
	 */
	public function getFieldIndex($value) {
		if(strpos($value, '[') === false) return '';
		if(strpos($value, '>')) {
			$value = explode('>', $value); 
			$value = trim(array_pop($value)); 
		}
		if(strpos($value, '[') === false) return '';
		list(,$value) = explode('[', $value, 2);
		list($value,) = explode(']', $value, 2);
		return $value;
	}

	/**
	 * Get page ID by ID or "123 > 456" string
	 *
	 * When given a "123 > 456" string, it will return the last page, i.e. id=456.
	 *
	 * @param string|int $value
	 * @return int
	 *
	 */
	public function getPageId($value) {
		if(strpos("$value", ">")) {
			$a = explode('>', $value);
			$value = trim(array_pop($a));
		}
		if(!ctype_digit("$value")) return 0;
		return (int) $value;
	}
	
	/**
	 * Update links in given $markup from source language to target language
	 *
	 * @param array|string $row Row or just the row[target]
	 * @param Language $sourceLanguage
	 * @param Language $targetLanguage
	 * @return int Number of links updated
	 *
	 */
	public function updateMarkupLinks(&$row, $sourceLanguage, $targetLanguage) {

		if(is_array($row)) {
			if($row['type'] != 'markup') return 0;
			$markup = &$row['target'];
		} else {
			$markup = &$row;
		}
		
		if(strpos($markup, ' href="/') === false) return 0;
		if(!preg_match_all('!<a\s[^>]*?href="(/[^"]+)"!', $markup, $matches)) return 0;

		$pages = $this->wire()->pages;
		$pathFinder = $pages->pathFinder();
		$rootUrl = $this->wire()->config->urls->root;
		$templates = $this->wire()->templates;
		$numUpdated = 0;
		
		foreach($matches[1] as /*$key => */ $href) {

			$sourceHref = $href;
			$hasRootUrl = false;
			
			if($rootUrl != '/' && strpos($href, $rootUrl) === 0) {
				$href = substr($href, strlen($rootUrl)-1); // remove installation root subdirectory
				$hasRootUrl = true;
			}

			$queryString = '';
			$fragment = '';

			if(strpos($href, '#')) {
				list($href, $fragment) = explode('#', $href);
				$fragment = "#$fragment";
			}

			if(strpos($href, '?')) {
				list($href, $queryString) = explode('?', $href, 2);
				$queryString = "?$queryString";
			}

			$trailingSlash = substr($href, -1) === '/';

			$info = $pathFinder->get($href);

			// error response
			if($info['response'] < 200 || $info['response'] >= 400) continue;

			// link to a different language, may already be translated
			if($info['language']['name'] != $sourceLanguage->name) continue;

			// link is already in the correct language
			if($info['language']['name'] === $targetLanguage->name) continue;

			// get the page
			$template = $templates->get($info['page']['templates_id']);
			$page = $pages->getByIDs($info['page']['id'], [ 'getOne' => true, 'template' => $template ]);
			if(!$page->id) continue;
			
			$targetHref = $page->localUrl($targetLanguage);
			
			if(!$hasRootUrl && $rootUrl != '/' && strpos($targetHref, $rootUrl) === 0) {
				// remove root URL from target because source URL didn’t have it 
				$targetHref = substr($targetHref, strlen($rootUrl)-1);
			}
			
			if($info['urlSegmentStr']) {
				// add in the URL segments
				$targetHref = rtrim($targetHref, '/') . '/' . $info['urlSegmentStr'];
			}
			
			if($trailingSlash) {
				// append the trailing slash
				$targetHref = rtrim($targetHref, '/') . '/';
			}

			// add back query string and fragment
			$targetHref .= $queryString . $fragment;

			// if no changes them skip
			if($targetHref === $href) continue; 

			$count = 0;
			$markup = str_replace('"' . $sourceHref . '"', '"' . $targetHref . '"', $markup, $count);
			$numUpdated += $count;
		}
	
		if($numUpdated && is_array($row)) {
			$row['updateMarkupLinks'] = "Updated $numUpdated link(s) in markup";
		}
		
		return $numUpdated;
	}

}