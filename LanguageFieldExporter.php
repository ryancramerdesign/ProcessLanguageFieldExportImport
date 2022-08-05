<?php namespace ProcessWire;

require_once(__DIR__ . '/LanguageFieldPort.php');

/**
 * LanguageFieldExporter
 *
 * ProcessWire 3.x, Copyright 2022 by Ryan Cramer
 * https://processwire.com
 * 
 * @method void exportPageFieldOther($pageId, Field $field)
 * 
 * @property bool $omitBlank
 * @property bool $omitTranslated
 * @property bool|int $sourceToTarget True to repeat source value in target, or bool 2 to repeat it even if already translated
 * @property bool $includeFieldsIndex
 * @property array $limitFields
 * @property bool $compact
 * @property Language|null $sourceLanguage
 * @property Language|null $targetLanguage
 * 
 */
class LanguageFieldExporter extends Wire {
	
	const version = 2;

	/**
	 * Fields that were used in the export, indexed by field name
	 * 
	 * @var array|Field[]
	 * 
	 */
	protected $usedFields = array();
	
	/**
	 * Names of multi-language fields to include in export or blank to include all
	 *
	 * @var array
	 *
	 */
	protected $limitFields = array();

	/**
	 * @var Language|null
	 * 
	 */
	protected $sourceLanguage = null;

	/**
	 * @var Language|null
	 *
	 */
	protected $targetLanguage = null;

	/**
	 * @var bool
	 * 
	 */
	protected $omitBlank = true;

	/**
	 * @var bool
	 * 
	 */
	protected $omitTranslated = false;
	
	/**
	 * @var bool|int
	 *
	 */
	protected $sourceToTarget = false;

	/**
	 * Include a 'fields' index in the return value?
	 * 
	 * @var bool
	 * 
	 */
	protected $includeFieldsIndex = true;

	/**
	 * @var bool
	 * 
	 */
	protected $compact = false;

	/**
	 * Exported rows for currently processing page
	 * 
	 * @var array
	 * 
	 */
	protected $rows = array();

	/**
	 * Last loaded page object
	 * 
	 * @var Page|null
	 * 
	 */
	protected $lastPage = null;

	/**
	 * Stack of fields for nested exports
	 * 
	 * @var array|Field[]
	 * 
	 */
	protected $fieldStack = array();
	
	/**
	 * @var array|LanguageFieldPort[]
	 * 
	 */
	protected $ports = array();

	/**
	 * Other get/set data
	 * 
	 * @var array
	 * 
	 */
	protected $data = array();

	/**
	 * Wired to API
	 * 
	 */
	public function wired() {
		parent::wired();	
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
	 * @return array|LanguageFieldPort[]
	 * 
	 */
	protected function getPorts() {
		$ports = $this->tools()->getPorts($this->sourceLanguage, $this->targetLanguage);
		foreach($ports as /*$name => */ $port) {
			$port->setExporter($this);
		}
		return $ports;
	}

	/**
	 * Export pages by IDs
	 * 
	 * @param array $pageIds
	 * @param Language $sourceLanguage
	 * @param Language $targetLanguage
	 * @return array
	 * 
	 */
	public function exportPages(array $pageIds, Language $sourceLanguage, Language $targetLanguage) {
		
		$this->sourceLanguage = $sourceLanguage;
		$this->targetLanguage = $targetLanguage;
		
		$data = array(
			'source_language' => "$sourceLanguage->name ($sourceLanguage->title)", 
			'target_language' => "$targetLanguage->name ($targetLanguage->title)", 
			'version' => self::version,
			'exported' => date('Y-m-d H:i:s'),
			// 'fields' => array(),
			// 'items' => array(), 
		);
		
		foreach($pageIds as $pageId) {
			$this->exportPage($pageId);
		}
		
		$data['items'] = $this->rows;
		$data['fields'] = $this->usedFields;
		$this->rows = array();
		
		return $data;
	}
	
	/**
	 * Export a page by ID
	 * 
	 * @param int $pageId
	 * @param int $templateId
	 * 
	 */
	public function exportPage($pageId, $templateId = 0) {
		
		if($templateId) {
			$template = $this->wire()->templates->get($templateId);
		} else {
			$template = $this->getPageTemplate($pageId);
		}
		
		$ports = $this->getPorts();
		$waitables = array();
		
		foreach($template->fieldgroup as $field) {
			/** @var Field $field */
			
			if(!empty($this->limitFields) && !in_array($field->name, $this->limitFields)) continue;
			
			foreach($ports as $port) {
				/** @var LanguageFieldPort $port */
				if(!$port->portable($field)) continue;

				$port->setSourceLanguage($this->sourceLanguage);
				$port->setTargetLanguage($this->targetLanguage);

				if($port->waitable()) {
					$waitables[] = array($port, $field);
				} else {
					$this->fieldStack[] = "$pageId.$field->name";
					$port->export($pageId, $field);
					array_pop($this->fieldStack);
				}
				
				break;
			}
		}
		
		foreach($waitables as $waitable) {
			$port = $waitable[0]; /** @var LanguageFieldPort $port */
			$field = $waitable[1]; /** @var Field $field */
			$this->fieldStack[] = "$pageId.$field->name";
			$port->export($pageId, $field);
			array_pop($this->fieldStack);
		}
	}

	/**
	 * Export page by ID and include all fields (regardless of limitFields setting)
	 * 
	 * @param int $pageId
	 * @param int $templateId
	 * 
	 */
	public function exportPageAllFields($pageId, $templateId = 0) {
		$limitFields = $this->limitFields;
		$this->limitFields = array();
		$this->exportPage($pageId, $templateId);
		$this->limitFields = $limitFields;
	}

	/**
	 * Export other/unknown page field
	 * 
	 * #pw-hooker
	 * 
	 * @param int $pageId
	 * @param Field $field
	 * 
	 */
	protected function ___exportPageFieldOther($pageId, Field $field) { }

	/**
	 * Get page (when needed)
	 *
	 * @param int $pageId
	 * @return Page
	 *
	 */
	public function getPage($pageId) {
		if($this->lastPage) {
			if($this->lastPage->id == $pageId) return $this->lastPage;
			$this->wire()->pages->uncache($this->lastPage);
			$this->lastPage = null;
		}
		$page = $this->wire()->pages->getById($pageId, array(
			'getOne' => true,
			'autojoin' => false,
		));
		if($page->id) $this->lastPage = $page;
		return $page;
	}

	/**
	 * Get page template from page ID
	 *
	 * @param $pageId
	 * @return null|Template
	 *
	 */
	public function getPageTemplate($pageId) {
		$sql = "SELECT templates_id FROM pages WHERE id=:id";
		$query = $this->wire()->database->prepare($sql);
		$query->bindValue(':id', $pageId, \PDO::PARAM_INT);
		$query->execute();
		$templateId = (int) $query->fetchColumn();
		if(!$templateId) return null;
		return $this->wire()->templates->get($templateId);
	}

	/**
	 * Get an export item template
	 *
	 * @param int $page
	 * @param string|Field $field
	 * @return array
	 *
	 */
	public function getRowTemplate($page = 0, $field = '') {
		return array(
			'page' => "$page",
			'field' => "$field",
			'type' => "text", 
			'source' => "",
			'target' => "",
		);
	}

	/**
	 * Prepare a row for any common logic
	 * 
	 * @param int $pageId
	 * @param Field $field
	 * @param array $row
	 * 
	 */
	public function prepareRow($pageId, Field $field, array &$row) {
	
		// account for link abstraction and other markup features
		if($row['type'] === 'markup' && ((int) $field->get('contentType')) >= FieldtypeTextarea::contentTypeHTML) {
			/** @var FieldtypeTextarea $fieldtype */
			$fieldtype = $this->wire()->fieldtypes->get('FieldtypeTextarea');
			$page = $this->getPage($pageId);
			if($page->id) {
				$row['source'] = $fieldtype->wakeupValue($page, $field, $row['source']);
				if(strlen($row['target'])) $row['target'] = $fieldtype->wakeupValue($page, $field, $row['target']); 
			}
		}
	
		if($this->compact) {
			// leave row[page] and row[field] as-is
		} else if(count($this->fieldStack) > 1) {
			$fieldStack = $this->fieldStack;
			array_pop($fieldStack);
			$pageIds = array();
			$fieldNames = array();
			foreach($fieldStack as $item) {
				list($pid, $fieldName) = explode('.', $item, 2);
				$pageIds[] = $pid;
				$fieldNames[] = $fieldName;
			}
			$row['page'] = implode(' > ', $pageIds) . ' > ' . $row['page'];
			$row['field'] = implode(' > ', $fieldNames) . ' > ' . $row['field'];
		}
		
		/*
		if(is_object($row['source']) && $row['source'] instanceof LanguagesValueInterface) {
			$value = $row['source'];
			$row['source'] = $value->getLanguageValue($this->sourceLanguage->id);
		}
		if(is_object($row['target']) && $row['target'] instanceof LanguagesValueInterface) {
			$value = $row['target'];
			$row['target'] = $value->getLanguageValue($this->targetLanguage->id);
		}
		*/
	}

	/**
	 * Add an export row 
	 * 
	 * @param int $pageId
	 * @param Field $field
	 * @param array $row
	 * @param string|Fieldtype $typeName
	 * 
	 */
	public function addRow($pageId, Field $field, array $row, $typeName = '') {
		
		if($this->omitBlank && !strlen($row['source'])) return;
		
		if(!isset($row['target'])) $row['target'] = ''; 
		
		if(!strlen($row['target'])) {
			// target value is empty
			if($this->sourceToTarget) $row['target'] = $row['source'];
			
		} else if(((int) $this->sourceToTarget) === 2) {
			// repeat source value in target even if target populated
			$row['target'] = $row['source'];
			
		} else if($this->omitTranslated) {
			// omit already translated values
			return;
		}
		
		if(ctype_digit("$row[page]")) $row['page'] = (int) "$row[page]";
		
		$this->prepareRow($pageId, $field, $row);
		
		$fieldName = "$row[field]"; 
		
		if(strpos($fieldName, '[')) {
			$fieldName = preg_replace('/\[[^\]]+\]/', '', $fieldName); // i.e. images[file.jpg], etc.
		}
		
		if(empty($typeName)) $typeName = (string) $field->type;
		
		$typeName = str_replace('Fieldtype', '', $typeName);
		
		$this->usedFields[$fieldName] = $typeName;
		$this->rows[] = $row;
	}

	/**
	 * Get installed language Fieldtype class names
	 * 
	 * @return array
	 * 
	 */
	public function getInstalledLanguageTypes() {
		$a = array();
		foreach($this->getPorts() as $port) {
			foreach($port->getFieldtypes() as $fieldtype) {
				$a[] = $fieldtype->className();
			}
		}
		return $a;
	}

	/**
	 * Get or set source language
	 * 
	 * @param Language $language
	 * @return null|Language
	 * 
	 */
	public function sourceLanguage(Language $language = null) {
		if($language) $this->sourceLanguage = $language;
		return $this->sourceLanguage;
	}

	/**
	 * Get or set target language
	 *
	 * @param Language $language
	 * @return null|Language
	 *
	 */
	public function targetLanguage(Language $language = null) {
		if($language) $this->targetLanguage = $language;
		return $this->targetLanguage;
	}
	
	/**
	 * Set which language fields to export or blank array for all
	 *
	 * @param array $limitFields
	 *
	 */
	public function setLimitFields(array $limitFields) {
		$this->limitFields = $limitFields;
	}

	/**
	 * @param string $name
	 * @return bool|mixed|null|array|Language
	 * 
	 */
	public function __get($name) {
		switch($name) {
			case 'omitBlank': return $this->omitBlank;
			case 'omitTranslated': return $this->omitTranslated;
			case 'sourceToTarget': return $this->sourceToTarget;
			case 'includeFieldsIndex': return $this->includeFieldsIndex;
			case 'sourceLanguage': return $this->sourceLanguage;
			case 'targetLanguage': return $this->targetLanguage;
			case 'limitFields': return $this->limitFields;
			case 'compact': return $this->compact;
		}
		if(isset($this->data[$name])) return $this->data[$name];
		return parent::__get($name);
	}

	/**
	 * @param string $key
	 * @param mixed $value
	 * 
	 */
	public function __set($key, $value) {
		switch($key) {
			case 'omitBlank': $this->omitBlank = (bool) $value; break;
			case 'omitTranslated': $this->omitTranslated = (bool) $value; break;
			case 'sourceToTarget': $this->sourceToTarget = (ctype_digit("$value") ? (int) $value : (bool) $value); break;
			case 'includeFieldsIndex': $this->includeFieldsIndex = (bool) $value; break;
			case 'sourceLanguage': $this->sourceLanguage($value); break;
			case 'targetLanguage': $this->targetLanguage($value); break;
			case 'limitFields': $this->setLimitFields($value); break;
			case 'compact': $this->compact = (bool) $value; break;
			default: $this->data[$key] = $value;
		}
	}
}