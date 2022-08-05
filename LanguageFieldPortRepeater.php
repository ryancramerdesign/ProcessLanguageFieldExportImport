<?php namespace ProcessWire;

/**
 * LanguageFieldPort for FieldtypeRepeater
 *
 * ProcessWire 3.x, Copyright 2022 by Ryan Cramer
 * https://processwire.com
 *
 */
class LanguageFieldPortRepeater extends LanguageFieldPort {
	
	public function usable() {
		return $this->wire()->modules->isInstalled('FieldtypeRepeater');
	}
	
	public function waitable() {
		return true;
	}

	public function portable(Field $field) {
		return wireInstanceOf($field->type, 'FieldtypeRepeater') && !wireInstanceOf($field->type, 'FieldtypeFieldsetPage'); 
	}

	public function export($pageId, Field $field) {
		
		$pageId = (int) $pageId;
		$fieldId = (int) "$field->id";

		$sql =
			"SELECT pages.id, pages.templates_id " .
			"FROM pages " .
			"JOIN pages AS parent ON parent.name='for-page-$pageId' AND pages.parent_id=parent.id " .
			"JOIN pages as grandparent ON grandparent.name='for-field-$fieldId' AND grandparent.id=parent.parent_id " .
			"ORDER BY pages.sort ";

		$query = $this->wire()->database->prepare($sql);
		$query->execute();
		$ids = array();

		while($row = $query->fetch(\PDO::FETCH_NUM)) {
			$ids[(int) $row[0]] = (int) $row[1];
		}

		$query->closeCursor();
	
		$n = 0;
		foreach($ids as $repeaterPageId => $templateId) {
			$this->exporter()->exportPageAllFields($repeaterPageId, $templateId);
			$n++;
		}
	}

	public function import($pageId, Field $field, array $row) {
		return false;
	}

}