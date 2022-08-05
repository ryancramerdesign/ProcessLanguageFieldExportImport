<?php namespace ProcessWire;

/**
 * LanguageFieldPort for FieldtypeFile/FildtypeImage
 * 
 * (does not support file custom fields)
 *
 * ProcessWire 3.x, Copyright 2022 by Ryan Cramer
 * https://processwire.com
 *
 */
class LanguageFieldPortFile extends LanguageFieldPort {

	public function portable(Field $field) {
		if(!$field->type instanceof FieldtypeFile) return false;
		if((int) $field->get('descriptionRows') < 1) return false;
		if($field->get('noLang')) return false;
		// if field is using custom fields then don't allow it just yet @todo
		if($this->wire()->templates->get("field-{$field->name}")) return false;
		return true;
	}

	public function export($pageId, Field $field) {
		
		$languages = $this->wire()->languages;
		$table = $field->getTable();
		$rowTemplate = $this->getRowTemplate($pageId, $field);
		$targetLanguage = $this->getTargetLanguage();
		$sourceLanguage = $this->getSourceLanguage();
		$defaultLanguage = $languages->getDefault();
		
		$sql = "SELECT data, description FROM $table WHERE pages_id=:id";
		$query = $this->wire()->database->prepare($sql);
		$query->bindValue(':id', $pageId, \PDO::PARAM_INT);
		$query->execute();
		
		while($a = $query->fetch(\PDO::FETCH_ASSOC)) {
			$desc = $a['description'];
			if(strpos($desc, '[') === 0 || strpos($desc, '{') === 0) {
				$descriptions = json_decode($desc, true);
			} else {
				$descriptions = array("0" => $desc);
			}
			if(isset($descriptions["0"])) {
				// index "0" refers to default language, convert to language ID index
				$descriptions["$defaultLanguage->id"] = $descriptions["0"]; 
				unset($descriptions["0"]); 
			}
			$row = $rowTemplate;
			$row["field"] .= '[' . $a['data'] . ']'; // add in filename, i.e. "images[file.jpg]"
			$row["source"] = isset($descriptions["$sourceLanguage->id"]) ? $descriptions["$sourceLanguage->id"] : "";
			$row["target"] = isset($descriptions["$targetLanguage->id"]) ? $descriptions["$targetLanguage->id"] : "";
			$this->addExportRow($pageId, $field, $row);
		}
		
		$query->closeCursor();
	}

	public function import($pageId, Field $field, array $row) {
		
		$languages = $this->wire()->languages;
		$defaultLanguage = $languages->getDefault();
		$testMode = $this->getTestMode();
		$verbose = $this->getVerbose();
		$targetLanguage = $this->getTargetLanguage();
		$sourceLanguage = $this->getSourceLanguage();
		$descriptionKeys = array(); // keys and order of original value
		$notes = array();
		
		$basename = $this->tools()->getFieldIndex($row['field']); 
		if(!strlen("$basename")) {
			$this->warn("Cannot identify file basename", $row);
			return false;
		}
		
		$table = $field->getTable();
		$sql = "SELECT description FROM $table WHERE pages_id=:pages_id AND data=:data";
		$query = $this->wire()->database->prepare($sql);
		$query->bindValue(':pages_id', $pageId, \PDO::PARAM_INT);
		$query->bindValue(':data', $basename);
		$query->execute();
		
		if(!$query->rowCount()) {
			$this->warn("Row for file '$basename' not found in DB", $row);
			$query->closeCursor();
			return false;
		}
		
		$value = $query->fetch(\PDO::FETCH_NUM);
		$value = (string) $value[0];
		$query->closeCursor();
		
		if(strpos($value, '[') === 0 || strpos($value, '{') === 0) {
			$descriptions = json_decode($value, true);
			$a = array();
			foreach($descriptions as $languageId => $languageValue) {
				$a["$languageId"] = $languageValue; // force keys as strings
				$descriptionKeys[] = "$languageId";
			}
			$descriptions = $a;
		} else {
			$descriptions = array("0" => $value);
			$descriptionKeys[] = "0";
		}
		
		if(isset($descriptions["0"])) {
			// index "0" refers to default language, convert to language ID index
			$descriptions["$defaultLanguage->id"] = $descriptions["0"];
			unset($descriptions["0"]);
		}
		
		if(!empty($descriptions["$targetLanguage->id"])) {
			// there is already a value present and overwrite mode is off
			if($this->overwrite) {
				$notes[] = "Overwriting existing target value";
			} else {
				if($verbose) $this->warn("Skipping because target value already present (overwrite=false)", $row);
				return false;
			}
		}
		
		if(!isset($descriptions["$sourceLanguage->id"])) $descriptions["$sourceLanguage->id"] = '';
		
		if($descriptions["$sourceLanguage->id"] != $row['source']) {
			if($this->confirmSource) {
				// source value differs from one in translation
				if($verbose) $this->warn("Skipping because source value in DB differs from source value in import", $row); 
				return false;
			} else {
				$notes[] = "Source value in DB differs from source value in import (file)";
			}
		}
		
		$descriptions["$targetLanguage->id"] = $row['target'];

		if(isset($descriptions["$defaultLanguage->id"])) {
			$a = array('0' => $descriptions["$defaultLanguage->id"]);
			unset($descriptions["$defaultLanguage->id"]);
			foreach($descriptions as $key => $value) {
				$a["$key"] = $value;
			}
			$descriptions = $a;
		}
	
		// re-order descriptions to match original order
		$a = array();
		foreach($descriptionKeys as $key) {
			if(!isset($descriptions["$key"])) continue;
			$a["$key"] = $descriptions["$key"]; 
			unset($descriptions["$key"]);
		}
		foreach($descriptions as $key => $value) {
			$a["$key"] = $value; // append any remaining
		}
		$descriptions = $a;
	
		if($testMode) {
			$sql = "SELECT pages_id FROM $table WHERE pages_id=:pages_id AND data=:data AND description!=:description";
		} else {
			$sql = "UPDATE $table SET description=:description WHERE pages_id=:pages_id AND data=:data";
		}
		$flags = defined("JSON_UNESCAPED_UNICODE") ? JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES : 0; // more fulltext friendly
		$descriptionJSON = json_encode($descriptions, $flags);
		$query = $this->wire()->database->prepare($sql);
		$query->bindValue(':description', $descriptionJSON); 
		$query->bindValue(':pages_id', $pageId, \PDO::PARAM_INT);
		$query->bindValue(':data', $basename); 
		$query->execute();

		if($query->rowCount() > 0) {
			$notes[] = "Updated";
			$this->note(implode('. ', $notes), $row);
			$result = true;
		} else {
			$notes[] = "Skipped";
			if($verbose) $this->warn(implode('. ', $notes), $row);
			$result = false;
		}
		
		if($testMode) $query->closeCursor();
		
		return $result;
	}

}