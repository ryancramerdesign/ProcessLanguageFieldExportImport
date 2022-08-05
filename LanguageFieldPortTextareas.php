<?php namespace ProcessWire;

/**
 * LanguageFieldPort for ProFields FieldtypeTextareas
 *
 * ProcessWire 3.x, Copyright 2022 by Ryan Cramer
 * https://processwire.com
 *
 */
class LanguageFieldPortTextareas extends LanguageFieldPort {

	public function usable() {
		return $this->wire()->modules->isInstalled('FieldtypeTextareas');
	}

	public function portable(Field $field) {
		return wireInstanceOf($field->type, 'FieldtypeTextareas') && $field->get('multilang');
	}

	public function export($pageId, Field $field) {
		
		$languages = $this->wire()->languages;
		$omitBlank = $this->getOmitBlank();
		$inputfieldClass = $field->get('inputfieldClass');
		$inputfieldClasses = array(
			'InputfieldText',
			'InputfieldTextarea',
			'InputfieldCKEditor',
			'InputfieldURL',
			'InputfieldEmail',
		);

		if(!in_array($inputfieldClass, $inputfieldClasses)) return;
		if($field->get('contentType') >= FieldtypeTextarea::contentTypeHTML) {
			$rowType = 'markup';
		} else if($inputfieldClass === 'InputfieldCKEditor') {
			$rowType = 'markup';
		} else {
			$rowType = strtolower(str_replace('Inputfield', '', $inputfieldClass));
		}

		$table = $field->getTable();
		$sql = "SELECT data FROM $table WHERE pages_id=:id";
		$query = $this->wire()->database->prepare($sql);
		$query->bindValue(':id', $pageId, \PDO::PARAM_INT);
		$query->execute();

		$data = $query->fetchColumn();
		$query->closeCursor();

		/* 
		 * Textareas 'data' column format: 
		 * 	
		 * name:<p>EN value</p>
		 * name___1554:<p>BR value</p>
		 * name___2635:<p>ES value</p>
		 * 
		 */

		$values = array();

		foreach(explode("\r", $data) as $part) {
			if(strpos($part, ':')) {
				list($name, $value) = explode(':', $part, 2);
			} else {
				continue;
			}
			if(strpos($name, '___')) {
				list($name, $languageId) = explode('___', $name, 2);
			} else {
				$languageId = $languages->getDefault()->id;
			}
			$languageId = (int) $languageId;
			if(!isset($values[$name])) $values[$name] = array();
			if($languageId == $this->sourceLanguage->id) {
				$values[$name]['source'] = $value;
			} else if($languageId == $this->targetLanguage->id) {
				$values[$name]['target'] = $value;
			}
		}

		foreach($values as $name => $langValues) {
			$source = isset($langValues['source']) ? $langValues['source'] : '';
			$target = isset($langValues['target']) ? $langValues['target'] : '';
			if(!strlen($source) && !strlen($target)) continue;
			if($omitBlank && !strlen($source)) continue;
			$row = $this->getRowTemplate($pageId, $field);
			$row['field'] .= ".$name";
			$row['type'] = $rowType;
			$row['source'] = $source;
			$row['target'] = $target;
			$this->addExportRow($pageId, $field, $row);
		}
	}

	public function import($pageId, Field $field, array $row) {
		
		$targetLanguage = $this->getTargetLanguage();
		$sourceLanguage = $this->getSourceLanguage();
		$testMode = $this->getTestMode();
		
		if(!strpos($row['field'], '.')) {
			$this->warn("No textareas col/property found in '$row[field]'", $row);
			return false;
		}
		
		$parts = explode('.', $row['field']); 
		$property = array_pop($parts);
		
		$table = $field->getTable();
		$sql = "SELECT data FROM $table WHERE pages_id=:id";
		$query = $this->wire()->database->prepare($sql);
		$query->bindValue(':id', $pageId, \PDO::PARAM_INT);
		$query->execute();
		
		if(!$query->rowCount()) {
			// no row found
			$this->warn("Textareas row not found in DB for page $pageId", $row);
			return false;
		}

		$data = $query->fetchColumn();
		$query->closeCursor();
		$values = array();
		
		foreach(explode("\r", $data) as $value) {
			list($name, $value) = explode(':', $value, 2);
			$values[$name] = $value;
		}

		$sourceKey = $sourceLanguage->isDefault() ? $property : "{$property}___$sourceLanguage->id";
		$targetKey = $targetLanguage->isDefault() ? $property : "{$property}___$targetLanguage->id";
		
		if(!empty($values[$targetKey]) && $values[$targetKey] === $row['target']) {
			// already has correct value
			$this->warn("Skipped because correct target value already present in DB", $row); 
			return false;
		}
	
		if(!$this->getOverwrite() && !empty($values[$targetKey])) {
			// overwrite of existing value is disallowed 
			$this->warn("Skipped because existing value present for '$field.$targetKey' and overwrite disallowed", $row);
			return false;	
		}
		
		if($this->getConfirmSource() && $values[$sourceKey] != $row['source']) {
			// source value differs and we require them to match
			$this->warn("Skipped because source value differs from that in row", $row);
			return false;
		}	
		
		if($row['type'] === 'markup') {
			$this->importer()->tools()->updateMarkupLinks($row, $sourceLanguage, $targetLanguage);
		}

		// populate new target
		$values[$targetKey] = $row['target'];
	
		// insert property name prefixes back into values
		foreach($values as $key => $value) {
			$values[$key] = "$key:$value";
		}
	
		// reconstruct original updated value
		$data = implode("\r", $values);
	
		if($testMode) {
			$sql = "SELECT pages_id FROM $table WHERE pages_id=:pages_id AND data!=:data";
		} else {
			$sql = "UPDATE $table SET data=:data WHERE pages_id=:pages_id";
		}
		$query = $this->wire()->database->prepare($sql);
		$query->bindValue(':data', $data);
		$query->bindValue(':pages_id', $pageId, \PDO::PARAM_INT);
		$query->execute();
		
		if($query->rowCount() > 0) {
			$this->note("Updated", $row);
			$result = true;
		} else {
			if($this->getVerbose()) $this->warn("Skipped", $row);
			$result = false;
		}
		
		if($testMode) $query->closeCursor();
		
		return $result;
	}

}