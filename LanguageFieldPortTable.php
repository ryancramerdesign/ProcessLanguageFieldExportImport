<?php namespace ProcessWire;

/**
 * LanguageFieldPort for ProFields FieldtypeTable
 *
 * ProcessWire 3.x, Copyright 2022 by Ryan Cramer
 * https://processwire.com
 *
 */
class LanguageFieldPortTable extends LanguageFieldPort {

	public function usable() {
		return $this->wire()->modules->isInstalled('FieldtypeTable');
	}

	public function portable(Field $field) {
		return wireInstanceOf($field->type, 'FieldtypeTable');
	}

	public function export($pageId, Field $field) {
		
		static $columns = array();

		$languages = $this->wire()->languages;

		if(isset($columns[$field->name])) {
			$colTypes = $columns[$field->name];
		} else {
			$colTypes = array();
			$fieldtype = $field->type; /** @var FieldtypeTable $fieldtype */
			$fieldColumns = $fieldtype->getColumns($field);
			foreach($fieldColumns as /*$key => */ $col) {
				$colName = $col['name'];
				$colType = $col['type']; /** @var string $type textLanguage, textareaLanguage, textareaCKELanguage */
				if($colType === 'textLanguage') {
					$inputType = 'text';
				} else if($colType === 'textareaLanguage') {
					$inputType = 'textarea';
				} else if($colType === 'textareaCKELanguage') {
					$inputType = 'markup';
				} else {
					continue;
				}
				$colTypes[$colName] = $inputType;
			}
			$columns[$field->name] = $colTypes;
		}

		if(!count($colTypes)) return; // no multi-langage columns in this table

		$colNames = array_keys($colTypes);
		$colNamesStr = implode(', ', $colNames);
		$table = $field->getTable();
		$rowNum = 0;
		$omitBlank = $this->getOmitBlank();

		$sql = "SELECT data, $colNamesStr FROM $table WHERE pages_id=:id ORDER BY sort";
		$query = $this->wire()->database->prepare($sql);
		$query->bindValue(':id', $pageId, \PDO::PARAM_INT);
		$query->execute();

		while($a = $query->fetch(\PDO::FETCH_ASSOC)) {
			
			$rowId = (int) $a['data'];

			foreach($colNames as $colName) {

				$colValue = $a[$colName];
				if(empty($colValue)) continue;
				$colValues = strpos($colValue, "\r") === false ? array($colValue) : explode("\r", $colValue);

				$langValues = array(
					$this->sourceLanguage->id => '',
					$this->targetLanguage->id => '',
				);

				foreach($colValues as $langValue) {
					list($langId, $langValue) = explode(':', $langValue, 2);
					$lang = $languages->get((int) $langId);
					if(!$lang || !$lang->id) continue;
					$langValues[$lang->id] = $langValue;
				}

				$row = $this->getRowTemplate($pageId, $field);
				$row['type'] = $colTypes[$colName];
				$row['source'] = (string) $langValues[$this->sourceLanguage->id];
				$row['target'] = (string) $langValues[$this->targetLanguage->id];
				$row['field'] .= "[$rowId].$colName";

				if($omitBlank && !strlen($row['source'])) continue;

				$this->addExportRow($pageId, $field, $row);
			}

			$rowNum++;
		}

		$query->closeCursor();
	}
	
	public function import($pageId, Field $field, array $row) {
		
		$database = $this->wire()->database;
		$table = $field->getTable();
		$sourceLanguage = $this->getSourceLanguage();
		$targetLanguage = $this->getTargetLanguage();
		$overwrite = $this->getOverwrite();
		$confirmSource = $this->getConfirmSource();
		$testMode = $this->getTestMode();
		$rowId = (int) $this->tools()->getFieldIndex($row['field']); 
		$col = $this->tools()->getFieldCol($row['field']); // 'field[n].col' to just 'col'
		$notes = array();
		
		if(empty($col) || empty($rowId)) return false;
	
		// language values indexed by language ID
		$langValues = $this->getLanguageValues($pageId, $rowId, $field, $col);
		
		if(empty($langValues[$sourceLanguage->id])) {
			// source value not found
			if($confirmSource) {
				$this->warn('Skipped because source value is empty', $row);
				return false;
			}
			$notes[] = 'Source value in DB is empty';
			$langValues[$sourceLanguage->id] = '';
		}
		
		if($langValues[$sourceLanguage->id] != $row['source']) {
			// source value has changed: target translation is for something different than current value
			if($confirmSource) {
				$this->warn('Skipped because source value in DB is different from source in import data', $row);
				return false;
			} else {
				$notes[] = 'Source value in DB differs from source value in import (table)';
			}
		}

		if($row['type'] === 'markup') {
			$this->importer()->tools()->updateMarkupLinks($row, $sourceLanguage, $targetLanguage);
		}

		if(empty($langValues[$targetLanguage->id])) {
			// target value not found: set default blank
			// $langValues[$targetLanguage->id] = '';
			
		} else if($langValues[$targetLanguage->id] === $row['target']) {
			// value already up-to-date
			$this->warn("Skipped because target value is already up-to-date", $row);
			return false;
			
		} else if(!$overwrite) {
			// do not overwrite existing populated target value
			$this->warn("Skipped because target value is already in DB (overwrite=false)", $row);
			return false;
			
		} else {
			// overwrite existing value
			$notes[] = "Overwriting existing target value";
		}
	
		// update to new value
		$langValues[$targetLanguage->id] = str_replace(array("\r\n", "\r"), "\n", $row['target']);

		if($row['type'] === 'markup') {
			$this->importer()->tools()->updateMarkupLinks($langValues[$targetLanguage->id], $sourceLanguage, $targetLanguage);
		}
	
		foreach($langValues as $langId => $langValue) {
			if(!strlen("$langValue")) {
				unset($langValues[$langId]);
			} else {
				$langValues[$langId] = "$langId:$langValue";
			}
		}

		if($testMode) {
			$sql = "SELECT pages_id FROM $table WHERE pages_id=:pages_id AND data=:row_id AND $col!=:value ";
		} else {
			$sql = "UPDATE $table SET $col=:value WHERE data=:row_id AND pages_id=:pages_id ";
		}
		
		$query = $database->prepare($sql);
		$query->bindValue(':value', implode("\r", $langValues));
		$query->bindValue(':row_id', $rowId, \PDO::PARAM_INT);
		$query->bindValue(':pages_id', $pageId, \PDO::PARAM_INT); 
		$query->execute();
	
		if($this->getVerbose()) {
			foreach($langValues as $key => $langValue) {
				if(strlen($langValue) <= 255) continue;
				$langValues[$key] = substr($langValue, 0, 255);
			}
			$row['langValues'] = $langValues; // for debug
		}
		
		if($query->rowCount() > 0) {
			$notes[] = "Updated";
			$this->note(implode('. ', $notes), $row); 
			$result = true;
		} else {
			if(!count($notes)) $notes[] = 'DB reported update not necessary';
			$notes[] = "Skipped";
			if($this->getVerbose()) $this->warn(implode('. ', $notes), $row);
			$result = false;
		}
		
		if($testMode) $query->closeCursor();
		
		return $result;
	}

	/**
	 * Get values indexed by language ID for given row and column
	 * 
	 * Returns empty array if no values found
	 * 
	 * @param int $pageId
	 * @param int $rowId
	 * @param Field $field
	 * @param string $col
	 * @return array
	 * 
	 */
	protected function getLanguageValues($pageId, $rowId, Field $field, $col) {

		$database = $this->wire()->database;
		$table = $field->getTable();
		$col = $database->escapeCol($col);
		$langValues = array();
		
		$sql = "SELECT data AS id, $col FROM $table WHERE data=:row_id AND pages_id=:pages_id";
		$query = $database->prepare($sql);
		$query->bindValue(':row_id', (int) $rowId, \PDO::PARAM_INT);
		$query->bindValue(':pages_id', (int) $pageId, \PDO::PARAM_INT);
		$query->execute();
		
		if($query->rowCount()) {
			$row = $query->fetch(\PDO::FETCH_ASSOC);
			$value = $row[$col];
			$values = strpos($value, "\r") === false ? array($value) : explode("\r", $value);
		} else {
			$values = array();
		}
		
		foreach($values as $langValue) {
			if(strpos($langValue, ':') === false) {
				// not a language value, reconstruct it as default language value
				$langId = $this->wire()->languages->getDefault()->id;
				if(isset($langValues[$langId])) continue;
			} else {
				list($langId, $langValue) = explode(':', $langValue, 2);
				if(!ctype_digit($langId)) {
					// not a language value, reconstruct it as default language value
					$langValue = "$langId:$langValue";
					$langId = $this->wire()->languages->getDefault()->id;
					if(isset($langValues[$langId])) continue;
				}
			}
			$langValues[(int) $langId] = $langValue;
		}

		$query->closeCursor();

		return $langValues;
	}

	/********************
	
	public function _import($pageId, Field $field, array $row) {
	
		$sourceLanguage = $this->getSourceLanguage();
		$targetLanguage = $this->getTargetLanguage();
		$col = $this->tools()->getFieldCol($row['field']);
		$overwrite = $this->getOverwrite();
		$numUpdates = 0;
		
		if(empty($col)) return false;
		
		$values = $this->_getLanguageValues($pageId, $field, $col);
		
		foreach($values as $key => $value) {
			
			if(empty($value[$sourceLanguage->id])) continue;
			if($value[$sourceLanguage->id] !== $row['source']) continue;
			
			// at this point we found the matching row
			if(!$overwrite && !empty($value[$targetLanguage->id])) {
				// do not overwrite already translated rows
				continue;
			}
			
			if($value[$targetLanguage->id] === $row['target']) {
				// found matching row and there is no change
				continue;
			}
			
			$value[$targetLanguage->id] = $row['target'];
		
			if($this->_updateLanguageValues($pageId, $field, $col, $value)) $numUpdates++;
		}
		
		return $numUpdates > 0;
	}
	
	protected function _updateLanguageValues($pageId, Field $field, $col, array $value) {
		$database = $this->wire()->database;
		$table = $field->getTable();
		$rowId = (int) $value['id'];
		unset($value['id']); 
		$values = array();
		foreach($value as $languageId => $text) {
			$values[] = "$languageId:$text";
		}
		$sql = "UPDATE $table SET $col=:value WHERE data=:id AND pages_id=:pages_id";
		$query = $database->prepare($sql);
		$query->bindValue(':id', $rowId, \PDO::PARAM_INT);
		$query->bindValue(':pages_id', $pageId, \PDO::PARAM_INT);
		$query->bindValue(':value', implode("\r", $values));
		$query->execute();
		return $query->rowCount() > 0;
	}
	
	protected function _getLanguageValues($pageId, Field $field, $col) {
	
		$languages = $this->wire()->languages;
		$rows = array();
		$table = $field->getTable();
		$sql = "SELECT data AS id, $col FROM $table WHERE pages_id=:id ORDER BY sort";
		$query = $this->wire()->database->prepare($sql);
		$query->bindValue(':id', $pageId, \PDO::PARAM_INT);
		$query->execute();
		
		while($row = $query->fetch(\PDO::FETCH_ASSOC)) {
			$value = $row[$col];
			if(empty($value)) continue;
			$values = strpos($value, "\r") === false ? array($value) : explode("\r", $value);
			$langValues = array();
			foreach($values as $langValue) {
				list($langId, $langValue) = explode(':', $langValue, 2);
				$lang = $languages->get((int) $langId);
				if(!$lang || !$lang->id) continue;
				$langValues[$lang->id] = $langValue;
			}
			$rows[] = $langValues;
		}
		
		$query->closeCursor();
		
		return $rows;
	}
	 * 
	 */ 

}