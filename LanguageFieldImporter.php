<?php namespace ProcessWire;

/**
 * LanguageFieldImporter
 *
 * ProcessWire 3.x, Copyright 2022 by Ryan Cramer
 * https://processwire.com
 * 
 * @method array rowReady(array $row, Field $field, $pageId)
 *
 */
class LanguageFieldImporter extends Wire {
	
	protected $sourceLanguage = null;
	protected $targetLanguage = null;
	// protected $overwrite = false;
	
	public function import(array &$data, array $options = array()) {
		
		$defaults = array(
			'overwrite' => false,
			'confirmSource' => false,
			'updateLinks' => false, 
			'verbose' => false,
			'testMode' => false, 
			'templateIds' => array(),
			'fields' => array(), 
		);
	
		$options = array_merge($defaults, $options);
		$skipFields = array();
		$numImported = 0;
		$numNotImported = 0;
		$numEmpty = 0;
		$pageIds = array();
		$skipPageIds = array();
		
		$this->sourceLanguage = $this->tools()->getLanguage($data['source_language']);
		$this->targetLanguage = $this->tools()->getLanguage($data['target_language']); 
		
		if(!$this->sourceLanguage) throw new WireException("Cannot find source language");
		if(!$this->targetLanguage) throw new WireException("Cannot find target language");
		
		$ports = $this->getPorts();
		
		foreach($ports as $port) {
			$port->setSourceLanguage($this->sourceLanguage);
			$port->setTargetLanguage($this->targetLanguage);
			$port->setOverwrite($options['overwrite']);
			$port->setConfirmSource($options['confirmSource']);
			$port->setVerbose($options['verbose']); 
			$port->setTestMode($options['testMode']); 
		}
		
		foreach($data['items'] as $row) {
			
			if(isset($skipFields[$row['field']])) continue;
			
			if(!strlen("$row[target]")) {
				$numEmpty++;
				$numNotImported++;
				continue;
			}
			
			if(!empty($options['fields']) && !in_array($row['field'], $options['fields'])) {
				$numNotImported++;
				continue;
			}
			
			$field = $this->tools()->getField($row['field']);
			
			if(!$field) {
				$skipFields[$row['field']] = $row['field']; 
				continue;
			}
			
			$pageId = $this->tools()->getPageId($row['page']); 
			if(!$pageId) continue;
			
			if(isset($skipPageIds[$pageId])) continue;
			
			if(!isset($pageIds[$pageId]) && !empty($options['templateIds'])) {
				if(!$this->pageIdHasTemplateIds($pageId, $options['templateIds'])) {
					$skipPageIds[$pageId] = $pageId;
					$numNotImported++;
					continue;
				}
			}
			
			$row = $this->rowReady($row, $field, $pageId);
			if($row['target'] === false) continue;

			$imported = false;
			
			foreach($ports as $port) {
				/** @var LanguageFieldPort $port */
				if(!$port->portable($field)) continue;
				if($port->import($pageId, $field, $row)) {
					$numImported++;
					$imported = true;
					$pageIds[$pageId] = $pageId;
				}
				break;
			}
			
			if(!$imported) $numNotImported++;
		}
	
		$importLabel = $options['testMode'] ? 'Tested import' : 'Completed import';
		if($numEmpty && $options['verbose']) $this->warning("$numEmpty row(s) had empty target values that were ignored", Notice::prepend); 
		if(count($skipFields)) $this->warning("Skipped fields that were not found: " . implode(', ', $skipFields), Notice::prepend);
		if($numNotImported) $this->warning("Skipped $numNotImported row(s)", Notice::prepend);
		if($numImported) $this->message("$importLabel of $numImported row(s) for " . count($pageIds) . " page(s)", Notice::prepend); 
	}
	
	/**
	 * Does given page ID have a template in the set of given template IDs?
	 * 
	 * @param int $pageId
	 * @param array $templateIds
	 * @return bool
	 * 
	 */
	protected function pageIdHasTemplateIds($pageId, array $templateIds) {
		if(!count($templateIds)) return false;
		$a = array();
		foreach($templateIds as $templateId) $a[] = (int) $templateId;
		$templateIdsStr = implode(',', $a);
		$sql = "SELECT COUNT(*) FROM pages WHERE id=:id AND templates_id IN($templateIdsStr)";
		$query = $this->wire()->database->prepare($sql);
		$query->bindValue(':id', $pageId, \PDO::PARAM_INT);
		$query->execute();
		$qty = (int) $query->fetchColumn();
		$query->closeCursor();
		return $qty > 0;
	}
	
	/**
	 * Is given import data valid?
	 * 
	 * @param array $data
	 * @param bool $verbose Report errors/warnings as notifications? (default=true)
	 * @return bool
	 * 
	 */
	public function isValidImportData(&$data, $verbose = true) {
		
		if(!is_array($data) || !is_array($data['items']) || !is_array($data['fields'])) {
			if($verbose) $this->error("Invalid JSON");
			return false;
		}
		
		$keys = array(
			'source_language',
			'target_language',
			'version',
			'fields',
			'items',
		);
		
		$missing = array();
		
		foreach($keys as $key) {
			if(empty($data[$key])) $missing[] = $key;
		}
		
		if(count($missing)) {
			if($verbose) $this->error("Missing required properties in JSON: " . implode(', ', $missing)); 
			return false;
		}
	
		$requireVersion = LanguageFieldExporter::version;
		if((int) $data['version'] < $requireVersion) {
			$this->error("Import file has an older version (v$data[version]) than the required version (v$requireVersion)");
			return false;
		}

		foreach(array('source_language', 'target_language') as $key) {
			$value = $data[$key];
			$language = $this->tools()->getLanguage($value);
			if(!$language) {
				$this->error("Cannot find $key: $value"); 
				return false;
			}
		}
		
		$numValidFields = 0;
		
		foreach($data['fields'] as $name => $type) {
			$field = $this->tools()->getField($name);
			if($field) {
				$numValidFields++;
			} else {
				$this->warning("Field not found '$name'");
			}
		}
		
		return $numValidFields > 0;
	}

	/**
	 * @return array|LanguageFieldPort[]
	 *
	 */
	protected function getPorts() {
		$ports = $this->tools()->getPorts($this->sourceLanguage, $this->targetLanguage);
		foreach($ports as /*$name => */ $port) {
			$port->setImporter($this);
		}
		return $ports;
	}
	
	/**
	 * @return LanguageFieldTools
	 *
	 */
	public function tools() {
		static $tools = null;
		if($tools) return $tools;
		require_once(__DIR__ . '/LanguageFieldTools.php');
		$tools = $this->wire(new LanguageFieldTools());
		return $tools;
	}

	/**
	 * Hook called when row is about to be imported
	 * 
	 * You can hook before to modify $event->arguments(0) or hook after to modify $event->return,
	 * either will have the same effect. 
	 * 
	 * To force row to skip, set the $row['target'] to boolean false. 
	 * 
	 * @param array $row
	 * @param Field $field
	 * @param int $pageId
	 * @return array
	 * 
	 */
	protected function ___rowReady(array $row, $field, $pageId) {
		if($field && $pageId) {} // ignore
		if(($row['type'] === 'text' || $row['type'] === 'textarea') && strpos($row['target'], '&') !== false) {
			// detect and convert entity encoded plain-text values
			if(preg_match('/&(#\d+|[a-z][a-z0-9]+);/i', $row['target'])) {
				$value = $this->wire()->sanitizer->unentities($row['target']); 
				if(strlen($value)) $row['target'] = $value;
			}
		}
		return $row;
	}
}	
