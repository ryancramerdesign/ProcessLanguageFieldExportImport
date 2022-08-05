<?php namespace ProcessWire;

/**
 * LanguageFieldPort for FieldtypeFieldsetPage
 *
 * ProcessWire 3.x, Copyright 2022 by Ryan Cramer
 * https://processwire.com
 *
 */
class LanguageFieldPortFieldsetPage extends LanguageFieldPort {
	
	public function usable() {
		return $this->wire()->modules->isInstalled('FieldtypeFieldsetPage');
	}
	
	public function waitable() {
		return true;
	}

	public function portable(Field $field) {
		return wireInstanceOf($field->type, 'FieldtypeFieldsetPage');
	}

	public function export($pageId, Field $field) {
		$table = $field->getTable();
		$sql = "SELECT data FROM $table WHERE pages_id=:id";
		$query = $this->wire()->database->prepare($sql);
		$query->bindValue(':id', $pageId, \PDO::PARAM_INT);
		$query->execute();
		$fieldsetPageId = (int) $query->fetchColumn();
		$query->closeCursor();
		if(!$fieldsetPageId) return;
		$this->exporter()->exportPageAllFields($fieldsetPageId); 
	}

	public function import($pageId, Field $field, array $row) {
		return false;
	}

}