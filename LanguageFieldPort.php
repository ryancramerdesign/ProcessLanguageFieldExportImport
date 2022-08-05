<?php namespace ProcessWire;

/**
 * LanguageFieldPort abstract/base class
 *
 * ProcessWire 3.x, Copyright 2022 by Ryan Cramer
 * https://processwire.com
 * 
 * @property-read bool $omitBlank
 * @property-read bool $overwrite
 * @property-read bool $verbose
 * @property-read bool $confirmSource
 *
 */
abstract class LanguageFieldPort extends Wire {

	/**
	 * @var null|LanguageFieldExporter
	 *
	 */
	protected $exporter = null;

	/**
	 * @var null|LanguageFieldImporter
	 *
	 */
	protected $importer = null;

	/**
	 * @var null|Language
	 *
	 */
	protected $sourceLanguage = null;

	/**
	 * @var null|Language
	 *
	 */
	protected $targetLanguage = null;

	/**
	 * Overwrite existing translated values during import?
	 *
	 * @var bool
	 *
	 */
	protected $overwrite = false;

	/**
	 * Confirm that source values match before importing?
	 *
	 * @var bool
	 *
	 */
	protected $confirmSource = false;

	/**
	 * Provide verbose debugging notifications?
	 *
	 * @var bool
	 *
	 */
	protected $verbose = false;

	/**
	 * Are we in test mode?
	 * 
	 * @var bool
	 * 
	 */
	protected $testMode = false;

	/**
	 * @var LanguageFieldTools|null
	 *
	 */
	protected $tools = null;

	/**
	 * Is this Port usable in current system?
	 *
	 * @return bool
	 *
	 */
	public function usable() {
		return true;
	}

	/**
	 * Wait for export/import till otherwise finished with a page?
	 *
	 * @return bool
	 *
	 */
	public function waitable() {
		return false;
	}

	/**
	 * Can this port be used for the given field?
	 *
	 * @param Field $field
	 *
	 * @return bool
	 *
	 */
	abstract public function portable(Field $field);

	/**
	 * Export field for page
	 *
	 * @param int $pageId
	 * @param Field $field
	 *
	 */
	abstract public function export($pageId, Field $field);

	/**
	 * Import field for page
	 *
	 * @param int $pageId
	 * @param Field $field
	 * @param array $row
	 *
	 * @return bool
	 *
	 */
	abstract public function import($pageId, Field $field, array $row);

	/**************************************************************/

	/**
	 * Get all fieldtypes that are exportable/importable by this port
	 *
	 * @return array|Fieldtype[]
	 *
	 */
	public function getFieldtypes() {
		$fieldtypes = array();
		foreach($this->getFields() as $field) {
			$fieldtype = $field->type;
			/** @var Fieldtype $fieldtype */
			$fieldtypes[$fieldtype->className()] = $fieldtype;
		}
		return $fieldtypes;
	}

	/**
	 * Get all fields exportable/importable by this port
	 *
	 * @return array|Field[]
	 * @throws WireException
	 *
	 */
	public function getFields() {
		$fields = array();
		if(!$this->usable()) return $fields;
		foreach($this->wire()->fields as $field) {
			if($this->portable($field)) $fields[] = $field;
		}
		return $fields;
	}

	/**
	 * Set the exporter instance
	 *
	 * @param LanguageFieldExporter $exporter
	 *
	 */
	public function setExporter(LanguageFieldExporter $exporter) {
		$this->exporter = $exporter;
	}

	/**
	 * Set the importer instance
	 *
	 * @param LanguageFieldImporter $importer
	 *
	 */
	public function setImporter(LanguageFieldImporter $importer) {
		$this->importer = $importer;
	}

	/**
	 * Get exporter instance
	 *
	 * @return null|LanguageFieldExporter
	 *
	 */
	public function exporter() {
		if($this->exporter) return $this->exporter;
		require_once(__DIR__ . '/LanguageFieldExporter.php');
		$this->exporter = $this->wire(new LanguageFieldExporter());
		return $this->exporter;
	}

	/**
	 * Get importer instance
	 *
	 * @return null|LanguageFieldImporter
	 *
	 */
	public function importer() {
		if($this->importer) return $this->importer;
		require_once(__DIR__ . '/LanguageFieldImporter.php');
		$this->importer = $this->wire(new LanguageFieldImporter());
		return $this->importer;
	}

	/**
	 * @return LanguageFieldTools
	 *
	 */
	public function tools() {
		if($this->tools) return $this->tools;
		require_once(__DIR__ . '/LanguageFieldTools.php');
		$this->tools = $this->wire(new LanguageFieldTools());
		return $this->tools;
	}

	/**
	 * Set source language
	 *
	 * @param Language $language
	 *
	 */
	public function setSourceLanguage(Language $language) {
		$this->sourceLanguage = $language;
	}

	/**
	 * Get source language
	 *
	 * @return Language
	 *
	 */
	public function getSourceLanguage() {
		return $this->sourceLanguage;
	}

	/**
	 * Set target language
	 *
	 * @param Language $language
	 *
	 */
	public function setTargetLanguage(Language $language) {
		$this->targetLanguage = $language;
	}

	/**
	 * Get target language
	 *
	 * @return Language
	 *
	 */
	public function getTargetLanguage() {
		return $this->targetLanguage;
	}

	/**
	 * Add an export row
	 *
	 * @param int $pageId
	 * @param Field $field
	 * @param array $row
	 *
	 */
	public function addExportRow($pageId, Field $field, array $row) {
		$this->exporter->addRow($pageId, $field, $row);
	}

	/**
	 * Get an export row template
	 *
	 * @param int $page
	 * @param string|Field $field
	 *
	 * @return array
	 *
	 */
	public function getRowTemplate($page = 0, $field = '') {
		return $this->exporter()->getRowTemplate($page, $field);
	}

	/**
	 * Get if rows for blank source values should be omitted
	 *
	 * @return bool
	 *
	 */
	public function getOmitBlank() {
		return $this->exporter()->omitBlank;
	}

	/**
	 * Get import overwrite mode
	 *
	 * @return bool
	 *
	 */
	public function getOverwrite() {
		return $this->overwrite;
	}

	/**
	 * Set import overwrite mode
	 *
	 * @param bool $overwrite
	 *
	 */
	public function setOverwrite($overwrite) {
		$this->overwrite = (bool) $overwrite;
	}

	/**
	 * Confirm source values before importing?
	 *
	 * @return bool
	 *
	 */
	public function getConfirmSource() {
		return $this->confirmSource;
	}

	/**
	 * Set whether source values sould be confirmed before importing
	 *
	 * @param bool $confirmSource
	 *
	 */
	public function setConfirmSource($confirmSource) {
		$this->confirmSource = (bool) $confirmSource;
	}

	/**
	 * Set verbose mode on/off
	 * 
	 * @param bool $verbose
	 * 
	 */
	public function setVerbose($verbose) {
		$this->verbose = $verbose;
	}

	/**
	 * Get if in verbose mode
	 * 
	 * @return bool
	 * 
	 */
	public function getVerbose() {
		return $this->verbose;
	}
	
	/**
	 * Set whether we are in test mode
	 * 
	 * @param bool $testMode
	 * 
	 */
	public function setTestMode($testMode) {
		$this->testMode = (bool) $testMode;
	}

	/**
	 * Are we in test mode? 
	 * 
	 * @return bool
	 * 
	 */
	public function getTestMode() {
		return $this->testMode;
	}

	/**
	 * Provide a debug note (used in verbose mode only)
	 * 
	 * @param string $note
	 * @param array|null|string $value
	 * @param string|bool $type One of 'message', 'warning' or 'error' or true for warning or false for message
	 * 
	 */
	public function note($note, $value = null, $type = 'message') {
		$verbose = $this->verbose || $this->testMode;
		if(!$verbose) return;
		if(is_bool($type)) $type = $type ? 'warning' : 'message';
		if($value !== null) {
			if(is_array($value)) {
				foreach(array('source', 'target') as $key) {
					if(!empty($value[$key]) && strlen($value[$key]) > 255) {
						$value[$key] = substr($value[$key], 0, 255);
					}
				}
			}
			$value = 
				htmlspecialchars($note) . '<br /><pre>' . 
				htmlspecialchars(print_r($value, true), ENT_QUOTES, 'UTF-8') . '</pre>';
			$this->$type($value,  Notice::allowMarkup); 
		} else {
			$this->$type($value);
		}
	}

	/**
	 * Provide a debug warning (used in verbose mode only)
	 *
	 * @param string $note
	 * @param array|null|string $value
	 *
	 */
	public function warn($note, $value = null) {
		$this->note($note, $value, 'warning');
	}

	/**
	 * Get property
	 * 
	 * @param string $name
	 * @return bool|mixed|null
	 * 
	 */
	public function __get($name) {
		if($name === 'omitBlank') return $this->getOmitBlank();
		if($name === 'overwrite') return $this->getOverwrite();
		if($name === 'confirmSource') return $this->getConfirmSource();
		if($name === 'verbose') return $this->getVerbose();
		return parent::__get($name);
	}
}