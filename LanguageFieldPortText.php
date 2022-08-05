<?php namespace ProcessWire;

/**
 * LanguageFieldPort for Text and Textarea fields
 *
 * ProcessWire 3.x, Copyright 2022 by Ryan Cramer
 * https://processwire.com
 *
 */
class LanguageFieldPortText extends LanguageFieldPort {
	
	public function portable(Field $field) {
		return $field->type instanceof FieldtypeLanguageInterface;
	}

	public function export($pageId, Field $field) {
		
		$sourceCol = $this->sourceLanguage->isDefault() ? "data" : "data{$this->sourceLanguage->id}";
		$targetCol = $this->targetLanguage->isDefault() ? "data" : "data{$this->targetLanguage->id}";
		
		$row = null;
		$omitBlank = $this->getOmitBlank();

		$table = $field->getTable();
		$sql = "SELECT $sourceCol AS source, $targetCol AS target FROM $table WHERE pages_id=:id";
		$query = $this->wire()->database->prepare($sql);
		$query->bindValue(':id', $pageId, \PDO::PARAM_INT);
		$query->execute();
		
		if($query->rowCount()) {
			$row = $query->fetch(\PDO::FETCH_ASSOC);
			if($omitBlank && !strlen((string) $row['source'])) {
				// blank source value is omitted
				$row = null;
			} else {
				$row = array_merge($this->getRowTemplate($pageId, $field->name), $row);
			}
		}

		$query->closeCursor();

		if($row && $field->type instanceof FieldtypeTextarea) {
			$contentType = $field->get('contentType');
			if($contentType >= FieldtypeTextarea::contentTypeHTML) {
				/** @var FieldtypeTextarea $fieldtype */
				$row['type'] = 'markup';
			} else {
				$row['type'] = 'textarea';
			}
		}

		if($row) $this->addExportRow($pageId, $field, $row);
	}

	public function import($pageId, Field $field, array $row) {
		
		$overwrite = (int) $this->getOverwrite();
		$confirmSource = (int) $this->getConfirmSource();
		$testMode = $this->getTestMode();
		$table = $field->getTable();

		$targetCol = $this->targetLanguage->isDefault() ? "data" : "data{$this->targetLanguage->id}";
		$sourceCol = $this->sourceLanguage->isDefault() ? "data" : "data{$this->sourceLanguage->id}";
		
		$sourceLanguage = $this->getSourceLanguage();
		$targetLanguage = $this->getTargetLanguage();
		
		if($testMode) {
			$sql = "SELECT pages_id FROM $table WHERE pages_id=:pages_id AND $targetCol!=:target_value ";
		} else {
			$sql = "UPDATE $table SET $targetCol=:target_value WHERE pages_id=:pages_id ";
		}
		
		if(!$overwrite) {
			$sql .= "AND ($targetCol IS NULL OR $targetCol='') ";
		}
		
		if($confirmSource) {
			$sql .= "AND $sourceCol=:source_value ";
		}
		
		if($row['type'] === 'markup') {
			$this->importer()->tools()->updateMarkupLinks($row, $sourceLanguage, $targetLanguage); 
		}
		
		$query = $this->wire()->database->prepare($sql);
		$query->bindValue(':target_value', $row['target']); 
		$query->bindValue(':pages_id', $pageId, \PDO::PARAM_INT); 
		if($confirmSource) $query->bindValue(':source_value', $row['source']); 
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